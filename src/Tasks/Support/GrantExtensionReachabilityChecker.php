<?php

namespace Dynamic\ContentApi\Tasks\Support;

use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use Throwable;

/**
 * Diagnostic for #103: `ContentApiGrantExtension` grants a verb's `can*()`
 * hook via `DataObject::extendedCan()`, which only takes effect if the
 * owning class's own `can*()` implementation actually calls `extendedCan()`
 * somewhere in its chain — `SiteTree`'s own `canView()`/`canEdit()`/
 * `canCreate()`/`canDelete()` all do, but a subclass that hard-overrides
 * one (FoxyStripe's `ProductPage` is the real-world case #103 was filed
 * against) can skip straight to its own logic, silently defeating the
 * grant with no error and nothing in the module's own diagnostics until
 * now.
 *
 * Reflection-based, not a live behavioral probe: for every concrete
 * `DataObject` subclass that both carries `ContentApiGrantExtension` and
 * declares its own (uninherited) `content_api_access`/`api_access` for a
 * verb — the exact same population {@see ContentApiGrantExtension::grant()}
 * itself checks — resolves the `can*()` method PHP will actually call
 * (following normal inheritance) and reads its declaring class's source
 * for a literal `extendedCan(` call, ignoring comments and string
 * literals so a mention in prose (or a real call that's been commented
 * out) can't produce a false "reachable" result.
 *
 * Necessarily a heuristic, not a proof: misses a call routed through an
 * intermediate helper method one level removed from the `can*()` method
 * itself. When the resolved method is defined on a trait rather than a
 * class, the source scan still reads the trait's real body correctly, but
 * the reported "declaring class" is the class that `use`s the trait, not
 * the trait itself — cosmetic, not a correctness gap in what gets
 * flagged. Chosen over a live behavioral probe (attach a forced-veto
 * extension at runtime, check whether a scratch record's answer reflects
 * it) because that approach needs a real `Member` holding
 * `CONTENT_API_ACCESS` to be meaningful, and constructing and safely
 * discarding one is real complexity and blast radius for a read-only
 * diagnostic that should be safe to run against a live database. If
 * reflection's blind spots turn out to matter in practice, that tradeoff
 * is worth revisiting.
 */
class GrantExtensionReachabilityChecker
{
    use Injectable;

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
    ];

    public ?ClassRegistry $registry = null;

    /**
     * Verb => the `can*()` hook {@see ContentApiGrantExtension} answers it
     * through. `update`/`action` both resolve to `canEdit()` — matches
     * {@see ContentApiGrantExtension::canEdit()}'s own `grant(['update',
     * 'action'], ...)` call.
     */
    private const VERB_METHODS = [
        'read' => 'canView',
        'create' => 'canCreate',
        'update' => 'canEdit',
        'action' => 'canEdit',
        'delete' => 'canDelete',
    ];

    /**
     * @return array<int, array{class: string, verbs: string[], method: string, declaringClass: string}>
     *   one entry per (class, method) pair the extension would grant
     *   at least one verb for but whose resolved `can*()` method's source
     *   has no `extendedCan(` call — `verbs` lists every declared verb
     *   that maps to that method, not just the first
     */
    public function check(): array
    {
        $findings = [];
        $verbsByMethod = [];

        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $className) {
            if (!$this->isConcrete($className) || !$this->carriesGrantExtension($className)) {
                continue;
            }

            foreach ($this->registry->ownAccessVerbs($className) as $verb) {
                $method = self::VERB_METHODS[$verb] ?? null;

                if ($method === null || !method_exists($className, $method)) {
                    continue;
                }

                // read/update/action can all point at the same resolved
                // method on one class — group every verb that reaches it
                // under one (class, method) entry rather than reporting
                // (and re-checking) each separately.
                $verbsByMethod[$className . '::' . $method][] = $verb;
            }
        }

        foreach ($verbsByMethod as $dedupeKey => $verbs) {
            [$className, $method] = explode('::', $dedupeKey, 2);

            if ($this->methodCallsExtendedCan($className, $method)) {
                continue;
            }

            $findings[] = [
                'class' => $className,
                'verbs' => $verbs,
                'method' => $method,
                'declaringClass' => $this->declaringClass($className, $method),
            ];
        }

        return $findings;
    }

    protected function isConcrete(string $className): bool
    {
        try {
            return (new ReflectionClass($className))->isInstantiable();
        } catch (ReflectionException) {
            return false;
        }
    }

    /**
     * Inherited, not uninherited: an extension applied to an ancestor
     * (`SiteTree`, `BaseElement`) is genuinely active on every subclass —
     * matches `ContentApiGrantExtension`'s own real runtime behavior, not
     * the class-access-declaration check below (which is deliberately
     * uninherited). `DataObject::has_extension()` — not a raw `extensions`
     * Config read — because a raw read would both false-positive (an
     * `extensions` entry contributed by a *different* extension applied to
     * the class, which `Extensible::getExtensionInstances()` itself
     * resolves with `Config::EXCLUDE_EXTRA_SOURCES` and would never
     * actually instantiate) and false-negative (a project subclassing
     * `ContentApiGrantExtension`, or a `%$ServiceName`/`Extension('args')`
     * config form, or a case variant) against what the framework actually
     * applies. `ClassRegistry::ownAccessVerbs()`'s own docblock names
     * `EXCLUDE_EXTRA_SOURCES` as load-bearing for exactly this reason.
     */
    protected function carriesGrantExtension(string $className): bool
    {
        // has_extension() resolves non-name/subclass matches (%$Service
        // forms, e.g.) by actually instantiating the class's extensions via
        // Injector — a broken project extension's constructor or missing
        // service elsewhere in the manifest must not abort this read-only
        // diagnostic for every other class.
        try {
            return DataObject::has_extension($className, ContentApiGrantExtension::class);
        } catch (Throwable) {
            return false;
        }
    }

    protected function methodCallsExtendedCan(string $className, string $method): bool
    {
        $source = $this->methodSource($className, $method);

        // Couldn't read the source (unusual — e.g. a compiled/phar class)
        // — not this checker's job to guess, so don't flag a false
        // positive over a limitation of the check itself.
        return $source === null
            || preg_match('/extendedCan\s*\(/', $this->stripCommentsAndStrings($source)) === 1;
    }

    /**
     * Drops comments and string-literal contents (keeping everything else,
     * including whitespace and real code) so a mention of `extendedCan(`
     * in prose — or inside a commented-out call — can't produce a false
     * "reachable" result, exactly the failure mode this checker's own
     * test suite hit while being written: a docblock comment describing
     * the bug used the method name in prose and was initially read as a
     * real call. `T_ENCAPSED_AND_WHITESPACE` (interpolated `"..."` bodies,
     * heredocs, nowdocs) is stripped alongside `T_CONSTANT_ENCAPSED_STRING`
     * (plain single/double-quoted literals) — both are string content, not
     * code, and either can carry a prose mention just as a comment can.
     */
    protected function stripCommentsAndStrings(string $source): string
    {
        $tokens = token_get_all('<?php ' . $source);
        $stripped = '';
        $stringTokens = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], $stringTokens, true)) {
                    continue;
                }

                $stripped .= $token[1];
            } else {
                $stripped .= $token;
            }
        }

        return $stripped;
    }

    protected function declaringClass(string $className, string $method): string
    {
        try {
            return (new ReflectionClass($className))->getMethod($method)->getDeclaringClass()->getName();
        } catch (ReflectionException) {
            return $className;
        }
    }

    protected function methodSource(string $className, string $method): ?string
    {
        try {
            $reflectionMethod = (new ReflectionClass($className))->getMethod($method);
        } catch (ReflectionException) {
            return null;
        }

        return $this->extractSource($reflectionMethod);
    }

    protected function extractSource(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();

        if ($file === false || !is_readable($file)) {
            return null;
        }

        $lines = file($file);
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($lines === false || $start === false || $end === false) {
            return null;
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
