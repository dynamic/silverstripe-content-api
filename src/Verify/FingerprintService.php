<?php

namespace Dynamic\ContentApi\Verify;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\PermissionPolicy;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * Builds a deterministic, path-keyed content fingerprint (#131) — the
 * cross-environment drift-detection primitive `ContentApiFingerprintTask`
 * (a hand-rolled BuildTask on `sheboygan-youth-sailing-installer`) was for
 * a real production restructure, generalized into the API itself so it's
 * reachable via MCP rather than a bespoke per-project task.
 *
 * Deliberately **path-keyed, not id-keyed**: numeric ids churn across a
 * sync/rebuild/environment boundary (a fresh database, a re-seeded fixture
 * run), but a page's tree path doesn't. Diffing two fingerprints (the same
 * environment before/after a batch, or two different environments ahead of
 * a replay) is meant to be a plain text/structural diff — see
 * `docs/en/16_verification.md`.
 *
 * Corrections versus the prototype:
 * - **The reachability invariant is actually asserted.** The prototype's
 *   own fingerprint output already contained the two contradicting lines
 *   (a live page directly below a draft-only ancestor) that caused a real
 *   production 404 — nothing ever checked them against each other; a
 *   human had to notice. `violations` computes exactly that check:
 *   `SiteTree::get_by_link()` resolves a URL per path segment in the
 *   current stage, so ANY non-live segment 404s everything below it,
 *   regardless of that record's own live status.
 * - **No raw numeric id leaks into an unresolved owner path.** The
 *   prototype's own known weakness: when a related record's owner
 *   couldn't be resolved to a page, it fell back to a raw `id:N` key —
 *   reintroducing exactly the environment-dependent id the whole
 *   fingerprint exists to avoid. Unresolved owners are counted, never
 *   individually identified by id, unless `includeIds` is set.
 * - **The response respects the same class- and record-level ACL every
 *   other read endpoint does.** Class-level: a row's own class must
 *   actually declare `read` (not merely some verb — `content_api_access:
 *   'create'` on a class must not make it readable here any more than it
 *   does on `GET records/$ClassRef`), checked per row rather than once for
 *   the whole `SiteTree` hierarchy — an explicit deny on one subclass
 *   (`content_api_access: false`) must not be overridden by a broader
 *   ancestor exposure. Record-level: `PermissionPolicy::canViewRecord()`
 *   per row, same as `RecordsHandler::readList()` — a draft-only page is
 *   invisible to a caller without `VIEW_DRAFT_CONTENT` here exactly as it
 *   is via `GET records/$ClassRef/$ID`. A bare path SEGMENT string
 *   appearing inside another row's `blockedBy`/`ownerPath` is not treated
 *   as a disclosure on the same level as a full row — the violation
 *   wouldn't be actionable without naming which ancestor to publish.
 */
class FingerprintService
{
    use Configurable;
    use Injectable;

    /**
     * Cycle guard for the `ParentID` walk building each page's path —
     * independent of whether a cycle is actually possible in `SiteTree`
     * (`enforce_strict_hierarchy` and the CMS's own tree UI both resist
     * one), matching this module's established convention elsewhere
     * (`OwnedTreeWalker`) of never trusting that alone.
     */
    private static int $max_path_depth = 50;

    /**
     * Related (non-`SiteTree`) classes to fingerprint alongside pages,
     * each keyed by its own owning page's raw FK column — e.g.
     * `'HeroSlide' => 'PageID'` for a hero-image relation whose FK points
     * directly at the owning page. A project declares its own via YAML;
     * empty by default. Deliberately a single direct FK column, not a
     * multi-hop relation walk — matches the real prototype's own
     * `SlideImage.PageID` shape exactly, and keeps this a read primitive
     * rather than a second `$owns`-style walker (see `OwnedTreeWalker`
     * for that).
     */
    private static array $related_classes = [];

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'policy' => '%$' . PermissionPolicy::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?PermissionPolicy $policy = null;

    /**
     * @param array<int, string>|null $classRefs restrict the OUTPUT to these
     *   refs — `'pages'` plus any `related_classes` short ref; `null`
     *   includes every section. Narrowing this never affects internal path
     *   resolution: a related ref requested without `'pages'` still
     *   resolves owner paths through the same index a full scan would use,
     *   it just omits the `pages` section itself from the response. An
     *   unrecognized ref is rejected (`PAYLOAD_INVALID`) rather than
     *   silently producing an empty section — a typo'd ref here is the
     *   worst possible failure for a verification primitive (a false
     *   "no drift" reading).
     * @throws ApiError PAYLOAD_INVALID for an unrecognized ref in $classRefs
     * @return array{
     *   pages: array<int, array<string, mixed>>,
     *   related: array<string, array{
     *     records: array<int, array<string, mixed>>,
     *     unresolved: int,
     *     unresolvedIds?: array<int, int>
     *   }>,
     *   totals: array<string, array{draft: int, live: int}>,
     *   violations: array<int, array<string, mixed>>,
     *   skipped: array<int, string>
     * }
     */
    public function build(?array $classRefs, bool $includeIds, Member $member): array
    {
        $this->assertKnownRefs($classRefs);

        $skipped = [];
        $totals = [];
        $violations = [];

        $pages = [];
        $paths = [];
        $liveIds = [];
        $parents = [];
        $classNames = [];
        $visiblePageIds = [];

        $wantsPages = $classRefs === null || in_array('pages', $classRefs, true);

        // The page path index is built whenever SiteTree is readable at
        // all — independent of whether the `pages` SECTION itself is in
        // the output, and independent of `$wantsPages` — because every
        // `related` row resolves its owner path through this same index,
        // and `skipped` must report `pages` as unreadable whenever the
        // index genuinely couldn't be built, even if the caller never
        // asked for the `pages` section in the first place (otherwise a
        // `classes=SomeRelatedRef`-only request against a site with no
        // SiteTree access reports every related row `unresolved` with an
        // empty `skipped` — indistinguishable from genuinely broken owner
        // FKs).
        if (!class_exists(SiteTree::class)) {
            $skipped[] = 'pages';
        } elseif (
            !in_array('read', $this->registry->accessVerbs(SiteTree::class), true)
            && !$this->siteTreeSubclassExposed()
        ) {
            $skipped[] = 'pages';
        } else {
            [$builtPages, $paths, $liveIds, $parents, $classNames, $pageTotals, $visiblePageIds]
                = $this->buildPages($includeIds, $member);

            // Computed unconditionally — NOT gated on $wantsPages.
            // `violations` is the entire reason this endpoint exists;
            // `classes=` narrowing the OUTPUT sections must not also
            // narrow the one check that would catch a real reachability
            // break. `?classes=SomeRelatedRef` (excluding `pages`) must
            // not silently stop reporting a live page stuck behind a
            // draft-only ancestor — the same "a typo'd ref must not
            // produce a false 'no drift' reading" reasoning that makes an
            // unknown ref a hard rejection applies here too.
            $violations = array_merge(
                $violations,
                $this->pageViolations($paths, $liveIds, $parents, $classNames, $visiblePageIds)
            );

            if ($wantsPages) {
                $pages = $builtPages;
                $totals['pages'] = $pageTotals;
            }
        }

        $related = [];

        foreach ((array) static::config()->get('related_classes') as $ref => $ownerColumn) {
            $className = $this->tryResolve($ref);

            if ($className === null || !in_array('read', $this->registry->accessVerbs($className), true)) {
                $skipped[] = $ref;
                continue;
            }

            // A misconfigured owner column must not proceed silently.
            // ownerRelationTargetsSiteTree() rejects three distinct
            // mistakes: a typo'd column name; the has_one RELATION name
            // used instead of its real FK column (e.g. "Page" instead of
            // "PageID" — getField() on an unknown field returns null for
            // a typo, but resolves to the owning DataObject itself for
            // the relation-name mistake, whose (int) cast is 1, silently
            // mis-attributing every record to whatever page happens to
            // have id 1); and a column that genuinely exists and IS a
            // has_one FK, but doesn't point at SiteTree at all (e.g. a
            // plausible copy/paste of the wrong Int/FK column) — a
            // fieldSpec()-only check accepts that silently, since it only
            // confirms the column exists, not what it actually points at.
            // Since $pagePaths is built purely from SiteTree, a column
            // that isn't a SiteTree-targeting FK could never resolve to a
            // real owner path anyway — every record would land in
            // `unresolved` (or worse, collide with an unrelated page id)
            // regardless of what the column legitimately contains.
            if (!$this->ownerRelationTargetsSiteTree($className, (string) $ownerColumn)) {
                $skipped[] = $ref;
                continue;
            }

            [$section, $classTotals, $classViolations] = $this->buildRelated(
                $className,
                (string) $ownerColumn,
                $paths,
                $liveIds,
                $parents,
                $includeIds,
                $member
            );

            // Violations always merge in, same reasoning as pages above —
            // `classes=` restricts which SECTIONS appear, never which
            // reachability problems get reported.
            $violations = array_merge($violations, $classViolations);

            if ($classRefs === null || in_array($ref, $classRefs, true)) {
                $related[$ref] = $section;
                $totals[$ref] = $classTotals;
            }
        }

        sort($skipped);
        ksort($related);

        return [
            'pages' => $pages,
            'related' => $related,
            'totals' => $totals,
            'violations' => $this->finalizeViolations($violations),
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<int, string>|null $classRefs
     * @throws ApiError PAYLOAD_INVALID
     */
    protected function assertKnownRefs(?array $classRefs): void
    {
        if ($classRefs === null) {
            return;
        }

        $known = array_merge(['pages'], array_keys((array) static::config()->get('related_classes')));
        $unknown = array_diff($classRefs, $known);

        if ($unknown !== []) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Unknown classes ref(s): %s.', implode(', ', $unknown))
            );
        }
    }

    /**
     * Whether the site declares api_access on a SiteTree SUBCLASS but not
     * on SiteTree itself — accessVerbs() resolves through an INHERITED
     * config lookup (see ClassRegistry's own docblock), so a project that
     * only sets access on, say, `Page` (not the `SiteTree` base) still
     * means every SiteTree row is meant to be readable; checking the base
     * class alone would report the whole pages section as unreadable. This
     * is only the section-level "is it worth building this at all" fast
     * path — `buildPages()` still checks each row's OWN class individually,
     * since this broad check alone can't tell an explicit per-subclass deny
     * apart from ordinary inheritance.
     */
    protected function siteTreeSubclassExposed(): bool
    {
        foreach ($this->registry->allExposed() as $info) {
            if (is_a($info['class'], SiteTree::class, true) && in_array('read', $info['verbs'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function tryResolve(string $classRef): ?string
    {
        try {
            return $this->registry->resolve($classRef);
            // ClassRegistry::resolve() only ever throws UNKNOWN_CLASS
            // today — the ref itself still surfaces via `skipped` either
            // way, so a future ApiError variant silently landing here
            // wouldn't hide the failure, only its specific reason.
        } catch (ApiError) {
            return null;
        }
    }

    /**
     * Whether `$ownerColumn` is genuinely a has_one FK on `$className`
     * pointing at `SiteTree` (or a subclass) — not merely an existing
     * column. `$pagePaths` is built purely from `SiteTree`, so any column
     * that isn't a SiteTree-targeting FK could never resolve a real owner
     * path regardless of what it legitimately contains.
     */
    protected function ownerRelationTargetsSiteTree(string $className, string $ownerColumn): bool
    {
        if (!str_ends_with($ownerColumn, 'ID')) {
            return false;
        }

        $relationName = substr($ownerColumn, 0, -2);
        $relationClass = DataObject::getSchema()->hasOneComponent($className, $relationName);

        return $relationClass !== null && is_a($relationClass, SiteTree::class, true);
    }

    /**
     * Class-level (this row's OWN class, not just "some SiteTree subclass
     * somewhere") plus record-level ACL, matching `RecordsHandler::
     * readList()`'s per-row `canViewRecord()` filtering — a draft-only
     * page invisible via `GET records/$ClassRef/$ID` to a caller without
     * `VIEW_DRAFT_CONTENT` must be equally invisible here.
     */
    protected function isPageVisible(SiteTree $page, Member $member): bool
    {
        return in_array('read', $this->registry->accessVerbs($page->ClassName), true)
            && $this->policy->canViewRecord($page, $member);
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>,
     *   2: array<int, true>, 3: array<int, int>, 4: array<int, string>,
     *   5: array{draft: int, live: int}, 6: array<int, true>}
     */
    protected function buildPages(bool $includeIds, Member $member): array
    {
        /** @var array<int, SiteTree> $byId */
        $byId = [];

        // Forced to DRAFT explicitly rather than trusting the ambient
        // reading mode: the whole design is "enumerate the full draft
        // superset, then mark each row live-or-not" via the separate
        // get_by_stage(LIVE) lookup below. A caller/controller context
        // whose ambient mode is already Live (the common case for a plain
        // request, no `?stage=` override) would otherwise make this query
        // silently return ONLY live rows — every draft-only page vanishing
        // from the enumeration entirely, not just from the `live` flag.
        Versioned::withVersionedMode(function () use (&$byId) {
            Versioned::set_stage(Versioned::DRAFT);

            foreach (SiteTree::get() as $page) {
                $byId[(int) $page->ID] = $page;
            }
        });

        // The full, unfiltered Live id set — used for PATH MATH (this
        // page's own live status, and every ancestor-blocker computation
        // for a visible descendant) regardless of whether this specific
        // page ends up in the visible OUTPUT. Filtering this by visibility
        // would silently corrupt path/violation computation for a VISIBLE
        // descendant of a page this particular caller can't see.
        $liveIds = array_fill_keys(
            array_map('intval', Versioned::get_by_stage(SiteTree::class, Versioned::LIVE)->column('ID')),
            true
        );

        // isPageVisible() below calls canViewRecord() per row, which for
        // a SiteTree record routes through Versioned::canViewVersioned()
        // — that issues its own version-number lookup query per record
        // (more for any record whose draft/live versions differ) unless
        // primed. Pre-populating both stages' caches turns what would be
        // O(pages) queries across a whole-site scan into two.
        $ids = array_keys($byId);
        Versioned::prepopulate_versionnumber_cache(SiteTree::class, Versioned::DRAFT, $ids);
        Versioned::prepopulate_versionnumber_cache(SiteTree::class, Versioned::LIVE, $ids);

        $paths = [];
        $parents = [];

        foreach ($byId as $id => $page) {
            $parents[$id] = (int) $page->ParentID;
            $paths[$id] = $this->pathFor($id, $byId);
        }

        $rows = [];
        $classNames = [];
        $visible = [];

        foreach ($byId as $id => $page) {
            $classNames[$id] = $page->ClassName;

            if (!$this->isPageVisible($page, $member)) {
                continue;
            }

            $visible[$id] = true;

            $row = [
                'path' => $paths[$id],
                'className' => $page->ClassName,
                'sort' => (int) $page->Sort,
                'showInMenus' => (bool) $page->ShowInMenus,
                'showInSearch' => (bool) $page->ShowInSearch,
                'live' => isset($liveIds[$id]),
                'externalId' => $page->hasField('FixtureIdentifier') ? ($page->FixtureIdentifier ?: null) : null,
            ];

            if ($includeIds) {
                $row = ['id' => $id] + $row;
            }

            $rows[] = $row;
        }

        // Sorted ordering is the whole point — the artifact is diffed
        // across gates and across environments as plain text/structure,
        // and byte-stable output must not depend on insertion order (row
        // creation order, autoincrement order) that differs between
        // environments even when the content itself is identical.
        usort($rows, static fn (array $a, array $b) => $a['path'] <=> $b['path']);

        // Counted over $visible, not $byId — the same #20 precedent as
        // the per-record ACL check above: a total that includes pages
        // this caller can't view discloses exactly how many hidden pages
        // exist (and makes `totals` disagree with `pages`/`violations`
        // for any non-admin caller, which reads as drift between two
        // fingerprints from callers with different permissions even when
        // nothing actually changed).
        $totals = [
            'draft' => count($visible),
            // Intersected against $visible rather than $byId: a page
            // deleted from DRAFT while still published
            // (`deleteFromStage(DRAFT)` on a Live row) has no entry in
            // `$byId` — and therefore no path, no row in `pages` — at
            // all, so it must not inflate `live` either.
            'live' => count(array_intersect_key($liveIds, $visible)),
        ];

        return [$rows, $paths, $liveIds, $parents, $classNames, $totals, $visible];
    }

    /**
     * In-memory `ParentID` walk to a `/segment/segment` path — no N+1
     * `Parent()` queries, since `$byId` already holds every page.
     * `max_path_depth` bounds it independent of whether a real cycle is
     * possible.
     */
    protected function pathFor(int $id, array $byId): string
    {
        $chain = [];
        $currentId = $id;
        $guard = 0;
        $maxDepth = (int) static::config()->get('max_path_depth');

        while ($currentId && isset($byId[$currentId]) && $guard++ < $maxDepth) {
            array_unshift($chain, (string) $byId[$currentId]->URLSegment);
            $currentId = (int) $byId[$currentId]->ParentID;
        }

        return '/' . implode('/', $chain);
    }

    /**
     * A live page whose path contains a non-live ancestor is unreachable
     * regardless of its own live status — `SiteTree::get_by_link()`
     * resolves per path segment in the current stage, so one draft-only
     * segment 404s every live page below it. Only a LIVE page can be
     * "blocked" this way; a draft-only page having a draft-only ancestor
     * is simply consistent, not a violation (same reasoning as #120's
     * parity endpoint). A page this caller can't view at all is skipped
     * here too — a violation entry would disclose its path/class the same
     * way a `pages` row would.
     *
     * @param array<int, string> $paths
     * @param array<int, true> $liveIds
     * @param array<int, int> $parents
     * @param array<int, string> $classNames
     * @param array<int, true> $visible
     * @return array<int, array{className: string, path: string, blockedBy: array<int, string>}>
     */
    protected function pageViolations(
        array $paths,
        array $liveIds,
        array $parents,
        array $classNames,
        array $visible
    ): array {
        $violations = [];

        foreach ($paths as $id => $path) {
            if (!isset($liveIds[$id]) || !isset($visible[$id])) {
                continue;
            }

            $blockedBy = $this->ancestorBlockers($id, $paths, $liveIds, $parents);

            if ($blockedBy !== []) {
                $violations[] = [
                    'className' => $classNames[$id] ?? SiteTree::class,
                    'path' => $path,
                    'blockedBy' => $blockedBy,
                ];
            }
        }

        return $violations;
    }

    /**
     * Every non-live ancestor STRICTLY ABOVE `$id` (never including `$id`
     * itself), sorted — shared between `pageViolations()` (a live page
     * checking its own ancestors) and `buildRelated()` (a live related
     * record's owner page's ancestors, one hop further up — which also
     * separately folds the owner's OWN non-live status back in when it
     * applies, since this helper only ever walks upward from it).
     * Independent of whether the page at `$id` itself is live — the
     * caller decides what "blocked" means for whatever it's checking.
     *
     * @param array<int, string> $paths
     * @param array<int, true> $liveIds
     * @param array<int, int> $parents
     * @return array<int, string>
     */
    protected function ancestorBlockers(int $id, array $paths, array $liveIds, array $parents): array
    {
        $blockedBy = [];
        $ancestorId = $parents[$id] ?? 0;
        $guard = 0;
        $maxDepth = (int) static::config()->get('max_path_depth');

        while ($ancestorId && isset($paths[$ancestorId]) && $guard++ < $maxDepth) {
            if (!isset($liveIds[$ancestorId])) {
                $blockedBy[] = $paths[$ancestorId];
            }

            $ancestorId = $parents[$ancestorId] ?? 0;
        }

        sort($blockedBy);

        return $blockedBy;
    }

    /**
     * @param array<int, string> $pagePaths
     * @param array<int, true> $pageLiveIds
     * @param array<int, int> $pageParents
     * @return array{0: array{
     *     records: array<int, array<string, mixed>>,
     *     unresolved: int,
     *     unresolvedIds?: array<int, int>
     *   },
     *   1: array{draft: int, live: int},
     *   2: array<int, array{className: string, path: string, blockedBy: array<int, string>}>}
     */
    protected function buildRelated(
        string $className,
        string $ownerColumn,
        array $pagePaths,
        array $pageLiveIds,
        array $pageParents,
        bool $includeIds,
        Member $member
    ): array {
        $isVersioned = DataObject::has_extension($className, Versioned::class);

        $liveIds = array_fill_keys(
            array_map('intval', $isVersioned
                ? Versioned::get_by_stage($className, Versioned::LIVE)->column('ID')
                : []),
            true
        );

        // Same reasoning as buildPages(): force DRAFT explicitly so this
        // enumerates the full draft superset regardless of the ambient
        // reading mode, rather than silently narrowing to Live-only rows
        // when Versioned. A non-versioned class has no stage concept, so
        // a plain get() is correct as-is.
        $records = [];

        if ($isVersioned) {
            Versioned::withVersionedMode(function () use ($className, &$records) {
                Versioned::set_stage(Versioned::DRAFT);

                foreach (DataObject::get($className) as $record) {
                    $records[] = $record;
                }
            });
        } else {
            foreach (DataObject::get($className) as $record) {
                $records[] = $record;
            }
        }

        if ($isVersioned) {
            // Same reasoning as buildPages(): canViewRecord() below
            // routes through Versioned::canViewVersioned() per record.
            $recordIds = array_map(static fn (DataObject $r) => (int) $r->ID, $records);
            Versioned::prepopulate_versionnumber_cache($className, Versioned::DRAFT, $recordIds);
            Versioned::prepopulate_versionnumber_cache($className, Versioned::LIVE, $recordIds);
        }

        $rows = [];
        $unresolved = 0;
        $unresolvedIds = [];
        $violations = [];
        $total = 0;
        $liveTotal = 0;

        foreach ($records as $record) {
            // Checked FIRST, before anything is counted anywhere —
            // matching RecordsHandler::readList()'s #20 precedent that
            // filtering after counting/pagination leaks the hidden-record
            // count. A record this caller can't view must not appear in
            // `unresolved`/`unresolvedIds` either: a broken owner FK on a
            // record the caller has no visibility into is not this
            // caller's problem to see, and `includeIds=1` must not expose
            // its id via `unresolvedIds` just because it happened to also
            // be unresolved.
            //
            // Class-level check here is on the record's own ACTUAL class
            // (`$record->ClassName`), not the `$className` this method was
            // called with — `DataObject::get($className)` instantiates
            // each row as its true persisted subclass, and the caller's
            // single `accessVerbs($className)` check in build() only
            // covers the CONFIGURED related_classes class, exactly the
            // same "explicit per-subclass deny overridden by a broader
            // ancestor exposure" gap isPageVisible() exists to close for
            // pages.
            if (
                !in_array('read', $this->registry->accessVerbs($record->ClassName), true)
                || !$this->policy->canViewRecord($record, $member)
            ) {
                continue;
            }

            $total++;
            $id = (int) $record->ID;
            $ownerId = (int) $record->getField($ownerColumn);
            $ownerPath = $pagePaths[$ownerId] ?? null;

            // A non-versioned record has no stage concept at all — it
            // always exists, so treating it as "live" is correct; its
            // reachability is governed entirely by its owner page.
            // Leaving this `false` (the pre-fix behavior) made the
            // reachability check — the entire point of this endpoint —
            // never run at all for a non-versioned related class.
            $isLive = $isVersioned ? isset($liveIds[$id]) : true;

            if ($isLive) {
                $liveTotal++;
            }

            if ($ownerPath === null) {
                $unresolved++;

                if ($includeIds) {
                    $unresolvedIds[] = $id;
                }

                continue;
            }

            $row = [
                'ownerPath' => $ownerPath,
                'className' => $className,
                'live' => $isLive,
                'externalId' => $record->hasField('FixtureIdentifier') ? ($record->FixtureIdentifier ?: null) : null,
            ];

            if ($includeIds) {
                $row = ['id' => $id] + $row;
            }

            $rows[] = $row;

            if ($isLive) {
                // "Blocked" means either the owner page itself isn't live,
                // or it is but one of ITS ancestors isn't — a live related
                // record whose owner page is live but sits under a
                // draft-only grandparent is unreachable the same way a
                // page in that position would be. When the owner itself
                // isn't live, its own path is folded back in alongside any
                // non-live ancestors above it — ancestorBlockers() only
                // ever walks STRICTLY above the id it's given.
                $ownerBlockedBy = $this->ancestorBlockers($ownerId, $pagePaths, $pageLiveIds, $pageParents);

                if (!isset($pageLiveIds[$ownerId])) {
                    $ownerBlockedBy[] = $ownerPath;
                    sort($ownerBlockedBy);
                }

                if ($ownerBlockedBy !== []) {
                    $violations[] = [
                        'className' => $className,
                        'path' => $ownerPath,
                        'blockedBy' => $ownerBlockedBy,
                    ];
                }
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b) => [$a['ownerPath'], (string) ($a['externalId'] ?? '')]
                <=> [$b['ownerPath'], (string) ($b['externalId'] ?? '')]
        );

        $section = ['records' => $rows, 'unresolved' => $unresolved];

        if ($includeIds) {
            sort($unresolvedIds);
            $section['unresolvedIds'] = $unresolvedIds;
        }

        return [
            $section,
            ['draft' => $total, 'live' => $liveTotal],
            $violations,
        ];
    }

    /**
     * Multiple related records sharing one blocked owner page produce
     * identical `{className, path, blockedBy}` triples — the entry
     * identifies the OWNER page's path, not any individual record, so
     * duplicates carry no additional information. Deduped and sorted here
     * once, centrally, rather than in each of `pageViolations()`/
     * `buildRelated()` — both already produce entries with the same shape.
     *
     * @param array<int, array{className: string, path: string, blockedBy: array<int, string>}> $violations
     * @return array<int, array{className: string, path: string, blockedBy: array<int, string>}>
     */
    protected function finalizeViolations(array $violations): array
    {
        $seen = [];
        $deduped = [];

        foreach ($violations as $violation) {
            $key = $violation['className'] . "\0" . $violation['path'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $violation;
        }

        usort(
            $deduped,
            static fn (array $a, array $b) => [$a['path'], $a['className']] <=> [$b['path'], $b['className']]
        );

        return $deduped;
    }
}
