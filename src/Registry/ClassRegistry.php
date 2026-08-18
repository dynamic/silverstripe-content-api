<?php

namespace Dynamic\ContentApi\Registry;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Maps short class references used in URLs/payloads to FQCNs and resolves each
 * exposed class's allowed API verbs.
 *
 * One map drives both surfaces: colymba's `DefaultQueryHandler.models` is the
 * base (define your models there so `/api` and `/content-api/v1` expose the
 * same refs); this class's own `models` config overlays it (ours wins per
 * key) for refs that should exist only on the content-api surface.
 *
 * Exposure is deny-by-default: a class must be in the merged map AND carry an
 * `api_access` (or `content_api_access`) config value before any endpoint will
 * touch it — UNLESS it's reachable only via `discovery_roots` (see below), in
 * which case a missing `api_access` falls back to `discovery_write_policy`
 * rather than "not exposed". An *explicit* `api_access`/`content_api_access`
 * (including an explicit `false` or `''` deny) always wins over the discovery
 * fallback — `accessVerbs()` distinguishes "key never set" from "key set to a
 * falsy value" via `Config::exists()`, not a plain falsy check, so an explicit
 * deny can't be silently overridden.
 *
 * Verbs: read, create, update, delete, action (publish/unpublish/archive).
 *
 * ## Auto-discovery
 *
 * `discovery_roots` (e.g. `[DNADesign\Elemental\Models\BaseElement::class,
 * SilverStripe\CMS\Model\SiteTree::class]`) opt a project into automatically
 * mapping every concrete subclass of each root, without a hand-written
 * `models:` entry per class. Runtime safety doesn't come from the map —
 * every operation still passes through `canView()`/`canEdit()` and
 * `WriteApplicator::isFieldWritable()`'s denylist regardless of how a class
 * reached the map — so discovery only ever grants what `discovery_write_policy`
 * says and never fabricates a writable-field allowlist.
 *
 * `discovery_write_policy: 'off'` (the default) is not just "no verbs" —
 * discovered classes are excluded from the map entirely (`resolve()` throws
 * `UNKNOWN_CLASS` for them, same as before discovery existed), not merely
 * "mapped but zero-verb". A ref that's mapped-but-empty-verbs would still be
 * resolvable to callers that never re-check `accessVerbs()` after resolving
 * (schema introspection, page-conversion targets, a polymorphic has_one's
 * serialized class name) — several of those call sites only ever checked
 * "does this class resolve at all", a question that was safe to answer
 * loosely when only classes someone deliberately listed in `models` could
 * resolve. Discovery would turn that into "does an entire subclass tree
 * resolve", so `'off'` keeps the discovery map itself empty rather than
 * populated-but-unauthorized.
 *
 * Discovery does NOT auto-apply `ExternalIdentifierExtension` — confirmed by
 * a live `dev/build` test that adding an extension at request time (even from
 * the module's own `_config.php`, which runs on every boot) does not reliably
 * add the extension's `db` field to the built schema; DataObjectSchema's
 * field-spec caching for a class is already settled by the time a
 * config-manifest-phase PHP file could retroactively change its extension
 * list. A discovered class is therefore read-addressable by numeric id only
 * until a project applies `ExternalIdentifierExtension` to it explicitly via
 * normal YAML (same as any other class) — that's a one-line addition, not
 * the 10-20-line block discovery is meant to avoid for read access.
 *
 * `discovery_exclude` adds project-specific classes/roots to skip (excluding
 * a class also excludes its own subclasses). A small denylist of
 * framework/auth classes (`Member`, `Group`, `Permission`, …) — and *their*
 * subclasses — is always excluded regardless of config; the reason
 * Drupal-style expose-everything is wrong for this module.
 *
 * Discovery only walks concrete classes (`ReflectionClass::isAbstract()`
 * filtered) — `ClassInfo::subclassesFor()` alone doesn't distinguish an
 * abstract intermediate class from a real leaf type.
 *
 * A short ref collision between two discovered classes under different
 * roots is resolved by `discovery_roots` array order: whichever root is
 * processed first claims the ref, the later one is silently skipped (not
 * mapped at all — it never had a ref before discovery either, so this isn't
 * a new failure mode, just an ordering-dependent one). Give it a manual
 * `models` entry under a different ref if both need to be reachable.
 */
class ClassRegistry
{
    use Configurable;
    use Injectable;

    public const VERBS = ['read', 'create', 'update', 'delete', 'action'];

    /**
     * Content-api-only refs (or overrides of colymba refs):
     * `ElementalArea: DNADesign\Elemental\Models\ElementalArea`.
     */
    private static array $models = [];

    /**
     * FQCNs to auto-discover concrete subclasses of. Empty by default —
     * auto-discovery is entirely opt-in.
     */
    private static array $discovery_roots = [];

    /**
     * Additional FQCNs (or their subclasses) to exclude from discovery, on
     * top of the mandatory denylist below.
     */
    private static array $discovery_exclude = [];

    /**
     * Verbs granted to a class reached only via discovery and carrying no
     * explicit `api_access` of its own. `'off'` (default) excludes discovered
     * classes from the map entirely — see the class docblock for why that's
     * not the same as "mapped but zero verbs". `'read'` grants read-only.
     * There is deliberately no write-granting policy value: a discovered
     * class's writable fields would otherwise have to be inferred, which is
     * exactly the #27 ParentID mistake this module already fixed once —
     * writes always require an explicit `api_writable_fields` on the class.
     */
    private static string $discovery_write_policy = 'off';

    /**
     * Always excluded from discovery, regardless of project config — classes
     * whose exposure (even read-only) would be a security or data-integrity
     * mistake by default. Not a config value, so a project can't accidentally
     * relax it away; use `discovery_roots` more narrowly instead. Subclasses
     * of these are excluded too (expanded in `discoveryDenylist()`).
     */
    private const MANDATORY_DISCOVERY_DENYLIST = [
        \SilverStripe\Security\Member::class,
        \SilverStripe\Security\Group::class,
        \SilverStripe\Security\Permission::class,
        \SilverStripe\Security\PermissionRole::class,
        \SilverStripe\Security\PermissionRoleCode::class,
        \SilverStripe\Security\LoginAttempt::class,
        \SilverStripe\Security\RememberLoginHash::class,
        \SilverStripe\Security\MemberPassword::class,
    ];

    /**
     * The unified map: colymba's models base + our overlay + discovered
     * classes. Manual config always wins over a discovered entry sharing the
     * same ref.
     *
     * Deliberately uncached: an earlier version memoized these per-instance,
     * on the reasoning that ClassRegistry is resolved fresh per request. It
     * isn't — `Injectable::singleton()` returns one instance for the whole
     * process, so a cached result from one test (or one differently-scoped
     * request context) leaked into the next, silently returning a stale
     * empty/populated map after `Config::modify()->set()` changed
     * `discovery_roots` mid-lifetime. Caught by the test suite itself (4
     * failures) before this shipped. Revisit with real cache invalidation
     * (a Config-change hook, not a plain property) if the Config-lookup cost
     * across `allExposed()`'s per-class loop is ever measured as a problem.
     */
    protected function mergedModels(): array
    {
        return array_merge($this->discoveredModels(), $this->manualModels());
    }

    /**
     * The hand-configured map only (colymba's base + our own `models`),
     * excluding anything discovery would add.
     */
    protected function manualModels(): array
    {
        $base = (array) Config::inst()->get(
            'Colymba\\RESTfulAPI\\QueryHandlers\\DefaultQueryHandler',
            'models'
        );

        return array_merge($base, (array) static::config()->get('models'));
    }

    /**
     * Ref => FQCN for every concrete, non-denylisted subclass of a
     * `discovery_roots` entry, when `discovery_write_policy` grants
     * something — `'off'` returns an empty map outright (see class
     * docblock). A discovered ref colliding with an existing manual ref, or
     * a class already reachable via a manual mapping under a different ref,
     * is skipped — manual config always wins and never gets a silent
     * discovery-added alias.
     */
    protected function discoveredModels(): array
    {
        $roots = (array) static::config()->get('discovery_roots');

        if ($roots === [] || $this->discoveryDefaultVerbs() === []) {
            return [];
        }

        $manual = $this->manualModels();
        $manualClasses = array_values($manual);
        $denylist = $this->discoveryDenylist();
        $discovered = [];

        foreach ($roots as $root) {
            if (!is_string($root) || !class_exists($root)) {
                continue;
            }

            foreach (array_values(ClassInfo::subclassesFor($root, false)) as $className) {
                if (in_array($className, $denylist, true) || in_array($className, $manualClasses, true)) {
                    continue;
                }

                if (!(new \ReflectionClass($className))->isInstantiable()) {
                    continue;
                }

                $ref = ClassInfo::shortName($className);

                if (isset($manual[$ref]) || isset($discovered[$ref])) {
                    continue;
                }

                $discovered[$ref] = $className;
            }
        }

        return $discovered;
    }

    /**
     * The mandatory denylist plus any project-configured `discovery_exclude`
     * entries — an excluded root (mandatory or configured) also excludes its
     * subclasses.
     *
     * Public (not just used internally by `discoveredModels()`/
     * `isDiscoveredOnly()`/`findClassBasenameMatch()`):
     * {@see \Dynamic\ContentApi\Tasks\Support\ExposureScaffolder} (#115/#118)
     * reuses this rather than re-deriving its own denylist, so `Member`/
     * `Group`/etc. can never be scaffolded for write exposure by either code
     * path independently drifting out of sync with the other.
     */
    public function discoveryDenylist(): array
    {
        $configured = (array) static::config()->get('discovery_exclude');
        $roots = array_merge(self::MANDATORY_DISCOVERY_DENYLIST, $configured);
        $expanded = [];

        foreach (array_unique($roots) as $entry) {
            if (!is_string($entry) || !class_exists($entry)) {
                continue;
            }

            $expanded[] = $entry;
            $expanded = array_merge($expanded, array_values(ClassInfo::subclassesFor($entry, false)));
        }

        return array_unique($expanded);
    }

    /**
     * Whether $className is reachable ONLY via discovery — never through an
     * explicit `models` mapping (colymba's or ours) — and isn't denylisted.
     * Used to decide whether a class with no `api_access` of its own still
     * gets the discovery fallback verbs, versus genuinely not exposed.
     */
    protected function isDiscoveredOnly(string $className): bool
    {
        if (in_array($className, array_values($this->manualModels()), true)) {
            return false;
        }

        if (in_array($className, $this->discoveryDenylist(), true)) {
            return false;
        }

        foreach ((array) static::config()->get('discovery_roots') as $root) {
            if (!is_string($root) || !class_exists($root) || strcasecmp($className, $root) === 0) {
                // The root class itself is never "discovered" (matches
                // discoveredModels()'s includeBaseClass: false) — a root
                // pointed directly at a concrete/instantiable class (e.g.
                // SiteTree, per this class's own docblock example) must not
                // grant itself the discovery fallback via is_a() matching
                // self, only its subclasses.
                continue;
            }

            if (is_a($className, $root, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verbs granted by discovery alone (no explicit `api_access` on the
     * class). Currently binary: `'read'` or nothing — see
     * `$discovery_write_policy`'s docblock for why there's no write option.
     */
    protected function discoveryDefaultVerbs(): array
    {
        return static::config()->get('discovery_write_policy') === 'read' ? ['read'] : [];
    }

    /**
     * Maps HTTP-method style access tokens to verbs for config compatibility
     * with colymba-style `api_access: 'GET,POST'` values.
     */
    private const METHOD_VERB_MAP = [
        'GET' => 'read',
        'POST' => 'create',
        'PUT' => 'update',
        'PATCH' => 'update',
        'DELETE' => 'delete',
    ];

    /**
     * Resolves a polymorphic has_one's target class from a payload value's
     * "class" hint — shared by WriteApplicator::resolveRelation() and
     * PermissionPolicy::buildCreateContext(), which both independently
     * needed the same "polymorphic has_one payload value -> concrete FQCN"
     * resolution (#24).
     *
     * @throws ApiError PAYLOAD_INVALID when $value isn't an array carrying
     *   "class"; UNKNOWN_CLASS via resolve() when the hint doesn't map
     */
    public function resolvePolymorphicHint(string $relationName, mixed $value): string
    {
        if (!is_array($value) || !isset($value['class'])) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Relation "%s" is polymorphic and requires an explicit "class" hint.', $relationName)
            );
        }

        return $this->resolve((string) $value['class']);
    }

    /**
     * Resolve a short class reference to its FQCN.
     *
     * `$classRef` is a short ref, not an FQCN, so the two ways this can fail
     * are genuinely different and worth distinguishing in the message (#125):
     * a ref with no `models:` entry at all (the common case — a real class
     * that was simply never registered) vs. a `models:` entry that points at
     * an FQCN that doesn't autoload (a broken/stale config value, effectively
     * a server misconfiguration). Both still throw the same `UNKNOWN_CLASS`
     * code — the two existing tests (`SchemaTest`, `RecordsReadTest`) assert
     * that code/status on the unmapped-ref path, and splitting it into a new
     * code would be a breaking change to any caller branching on `error.code`
     * for a discoverability gap that doesn't need one. What changes is only
     * the message and `details`.
     *
     * @throws ApiError UNKNOWN_CLASS when unmapped, missing or not exposed
     */
    public function resolve(string $classRef): string
    {
        $models = $this->mergedModels();
        $className = $models[$classRef] ?? null;

        // Deliberately !empty(), not !== null — an empty-string config value
        // (`models: {ref: ''}`) is functionally unmapped, same as the
        // original `!$className` check treated it, not "registered to
        // nothing."
        if (!empty($className) && !class_exists($className)) {
            throw new ApiError(
                ErrorCode::UNKNOWN_CLASS,
                sprintf(
                    'Class reference "%s" is registered to "%s", but that class does not exist '
                        . '(autoload failure or stale "models:" config).',
                    $classRef,
                    $className
                ),
                [['field' => null, 'code' => 'CLASS_NOT_FOUND', 'message' => $className]]
            );
        }

        if (!$className) {
            $suggestion = $this->findClassBasenameMatch($classRef);

            // #125 review follow-up: the suggestion is worthless (or actively
            // wrong) in two cases the basename match alone can't see —
            // caught here, not inside findClassBasenameMatch(), since both
            // need $models, which that method deliberately doesn't take.
            if ($suggestion !== null) {
                // (a) already registered, just under a different ref — the
                // fix is "use that ref", not "add a models: entry", and
                // telling the caller to add one invites a duplicate alias.
                $existingRef = array_search($suggestion, $models, true);

                if ($existingRef !== false) {
                    throw new ApiError(
                        ErrorCode::UNKNOWN_CLASS,
                        sprintf(
                            'Unknown class reference "%s". "%s" is already registered, as ref '
                                . '"%s" — use that instead.',
                            $classRef,
                            $suggestion,
                            $existingRef
                        ),
                        [['field' => null, 'code' => 'CLASS_ALREADY_MAPPED', 'message' => (string) $existingRef]]
                    );
                }
            }

            throw new ApiError(
                ErrorCode::UNKNOWN_CLASS,
                $suggestion !== null
                    ? sprintf(
                        'Unknown class reference "%s". A class named "%s" exists (%s) but has no '
                            . '"models:" entry — add one in your content-api.yml.',
                        $classRef,
                        ClassInfo::shortName($suggestion),
                        $suggestion
                    )
                    : sprintf('Unknown class reference "%s".', $classRef),
                $suggestion !== null
                    ? [['field' => null, 'code' => 'CLASS_NOT_MAPPED', 'message' => $suggestion]]
                    : []
            );
        }

        return $className;
    }

    /**
     * Best-effort suggestion for `resolve()`'s "unmapped ref" branch: does a
     * `DataObject` subclass exist whose short (unqualified) name matches
     * `$classRef` case-insensitively? This is what actually cost real time on
     * `sheboygan-youth-sailing-installer` (#125) — a live, working
     * `SearchPage` class 404'd `UNKNOWN_CLASS` with nothing pointing at the
     * real fix (a missing `models:` entry), and the investigation took real
     * time specifically because the error read like a typo/routing problem
     * rather than a registration gap.
     *
     * (b) Never suggests a `discoveryDenylist()` class — caught here, not by
     * the caller. `Member`/`Group`/`Permission`/etc. are hardcoded-excluded
     * from discovery for exactly this reason (the class docblock: "a project
     * can't accidentally relax it away"), and a first version of this method
     * suggested them anyway — reviewed and caught before merge. Telling a
     * caller to register `Member` would both give actively wrong guidance
     * and turn `resolve()` into an existence oracle for classes the module
     * has specifically decided callers shouldn't be pointed at, regardless
     * of whether they already hold a valid token.
     *
     * Deliberately narrow otherwise: only matches an exact case-insensitive
     * basename, never a fuzzy/partial match — a wrong suggestion would be
     * worse than none. Returns the first match found; ambiguity between two
     * identically-named classes in different namespaces isn't worth
     * resolving further here, since either surviving result still correctly
     * signals "register this" (and (a) above already prefers an
     * already-registered match when there is one).
     */
    private function findClassBasenameMatch(string $classRef): ?string
    {
        $needle = strtolower($classRef);
        $denylist = $this->discoveryDenylist();

        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $candidate) {
            if (in_array($candidate, $denylist, true)) {
                continue;
            }

            if (strtolower(ClassInfo::shortName($candidate)) === $needle) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Reverse lookup: FQCN (or subclass instance's class) to its short ref.
     * Walks up the ancestry so subclasses serialize under their nearest
     * registered ancestor when not directly mapped.
     */
    public function refFor(string $className): ?string
    {
        $byClass = array_flip($this->mergedModels());

        $candidate = $className;
        while ($candidate) {
            if (isset($byClass[$candidate])) {
                return $byClass[$candidate];
            }
            $candidate = get_parent_class($candidate) ?: null;
        }

        return null;
    }

    /**
     * The verbs a class is exposed for. `content_api_access` wins over
     * `api_access` when both are set (colymba coexistence).
     *
     * `content_api_access` reliably distinguishes "never configured" from
     * "explicitly set, including to a falsy deny" via `Config::exists()` —
     * it's a module-specific key, so nothing else in the framework declares
     * it. `api_access` can't use the same check: `SilverStripe\ORM\DataObject`
     * itself declares `private static $api_access = false` as a *built-in*
     * config property (unrelated to this module, an older colymba-era
     * mechanism) — every DataObject subclass "has" that key whether or not a
     * project ever touched it, so `Config::exists($class, 'api_access')` is
     * always `true` framework-wide and can never mean "explicitly set" for
     * this key specifically. `api_access` is therefore read by *value*: a
     * project wanting to explicitly deny a class that `discovery_roots`
     * would otherwise pick up needs `content_api_access: false` or
     * `discovery_exclude`, not `api_access: false` — the latter is
     * indistinguishable from DataObject's own untouched default.
     *
     * A class with neither an explicit `content_api_access` nor a truthy
     * `api_access`, reached only via `discovery_roots`, falls back to
     * `discoveryDefaultVerbs()` instead of "not exposed". This is the single
     * source of truth every write/read path already calls, so the fallback
     * applies uniformly, not just to the schema listing.
     *
     * @return string[] subset of ClassRegistry::VERBS, empty = not exposed
     */
    public function accessVerbs(string $className): array
    {
        $config = Config::inst();

        if ($config->exists($className, 'content_api_access')) {
            return $this->parseAccess($config->get($className, 'content_api_access'));
        }

        $access = $config->get($className, 'api_access');

        if ($access === true) {
            return ClassRegistry::VERBS;
        }

        if (is_string($access) && trim($access) !== '') {
            return $this->parseAccess($access);
        }

        return $this->isDiscoveredOnly($className) ? $this->discoveryDefaultVerbs() : [];
    }

    /**
     * The verbs a class declares *itself*, ignoring anything inherited from
     * an ancestor. Same key precedence as accessVerbs() (`content_api_access`
     * wins over `api_access`), but read with `Config::UNINHERITED |
     * Config::EXCLUDE_EXTRA_SOURCES` and with no discovery fallback —
     * `EXCLUDE_EXTRA_SOURCES` matters here specifically (accessVerbs() has no
     * reason to use it): without it, a value contributed by another
     * *extension* applied to the class would count as that class's "own"
     * declaration too, same as `Extensible::getExtensionInstances()` uses
     * both flags together when resolving a class's own `extensions` config.
     *
     * accessVerbs() is deliberately inherited: a project declaring
     * `content_api_access` on `Page` exposes the whole page tree at the class
     * gate, which is usually what it wants. That makes the class gate useless
     * as a narrowing mechanism for a *record-level* permission grant, though
     * — an undeclared subclass still inherits the declared one's verbs there.
     * This is the uninherited counterpart a record-level grant extension
     * needs so it can never reach a class the project never named itself —
     * see `Security\ContentApiGrantExtension` and
     * `docs/en/04_security-model.md`.
     *
     * @return string[] subset of ClassRegistry::VERBS, empty = declares
     *   nothing of its own (whether or not it inherits something)
     */
    public function ownAccessVerbs(string $className): array
    {
        $config = Config::inst();
        $flags = Config::UNINHERITED | Config::EXCLUDE_EXTRA_SOURCES;

        $own = $config->get($className, 'content_api_access', $flags);

        if ($own !== null) {
            return $this->parseAccess($own);
        }

        $own = $config->get($className, 'api_access', $flags);

        return $own === null ? [] : $this->parseAccess($own);
    }

    /**
     * Parses an `api_access`/`content_api_access` value already confirmed
     * present into verbs: `true` = all, a CSV of colymba-style HTTP verbs
     * (or bare verb names) maps via `METHOD_VERB_MAP`, anything else
     * (`false`, `''`, ...) is an explicit deny.
     */
    protected function parseAccess(mixed $access): array
    {
        if ($access === true) {
            return ClassRegistry::VERBS;
        }

        if (!is_string($access) || trim($access) === '') {
            return [];
        }

        $verbs = [];

        foreach (explode(',', $access) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $verb = ClassRegistry::METHOD_VERB_MAP[strtoupper($token)] ?? strtolower($token);

            if (in_array($verb, ClassRegistry::VERBS, true) && !in_array($verb, $verbs, true)) {
                $verbs[] = $verb;
            }
        }

        return $verbs;
    }

    /**
     * All exposed classes: ref => ['class' => FQCN, 'verbs' => [...]].
     * Classes mapped in `models` but carrying no api_access are omitted.
     */
    public function allExposed(): array
    {
        $exposed = [];

        foreach ($this->mergedModels() as $ref => $className) {
            if (!class_exists($className)) {
                continue;
            }

            $verbs = $this->accessVerbs($className);

            if ($verbs === []) {
                continue;
            }

            $exposed[$ref] = [
                'class' => $className,
                'verbs' => $verbs,
            ];
        }

        return $exposed;
    }
}
