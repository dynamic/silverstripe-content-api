<?php

namespace Dynamic\ContentApi\Verify;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Registry\ClassRegistry;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
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
 * Two corrections versus the prototype:
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
    ];

    public ?ClassRegistry $registry = null;

    /**
     * @param array<int, string>|null $classRefs restrict the OUTPUT to these
     *   refs — `'pages'` plus any `related_classes` short ref; `null`
     *   includes every section. Narrowing this never affects internal path
     *   resolution: a related ref requested without `'pages'` still
     *   resolves owner paths through the same index a full scan would use,
     *   it just omits the `pages` section itself from the response.
     * @return array{
     *   pages: array<int, array<string, mixed>>,
     *   related: array<string, array{records: array<int, array<string, mixed>>, unresolved: int}>,
     *   totals: array<string, array{draft: int, live: int}>,
     *   violations: array<int, array<string, mixed>>,
     *   skipped: array<int, string>
     * }
     */
    public function build(?array $classRefs, bool $includeIds): array
    {
        $skipped = [];
        $totals = [];
        $violations = [];

        $pages = [];
        $paths = [];
        $liveIds = [];
        $parents = [];
        $classNames = [];

        $wantsPages = $classRefs === null || in_array('pages', $classRefs, true);

        // The page path index is built whenever SiteTree is readable at
        // all — independent of whether the `pages` SECTION itself is in
        // the output — because every `related` row resolves its owner
        // path through this same index. `classes=` narrowing to a related
        // ref only must shrink the response, not silently break owner
        // resolution for the sections that WERE requested (an empty index
        // would report every related row `unresolved`, which is wrong,
        // not just incomplete).
        if (!class_exists(SiteTree::class)) {
            if ($wantsPages) {
                $skipped[] = 'pages';
            }
        } elseif ($this->registry->accessVerbs(SiteTree::class) === [] && !$this->siteTreeSubclassExposed()) {
            if ($wantsPages) {
                $skipped[] = 'pages';
            }
        } else {
            [$builtPages, $paths, $liveIds, $parents, $classNames, $pageTotals] = $this->buildPages($includeIds);

            if ($wantsPages) {
                $pages = $builtPages;
                $totals['pages'] = $pageTotals;
                $violations = array_merge(
                    $violations,
                    $this->pageViolations($paths, $liveIds, $parents, $classNames)
                );
            }
        }

        $related = [];

        foreach ((array) static::config()->get('related_classes') as $ref => $ownerColumn) {
            if ($classRefs !== null && !in_array($ref, $classRefs, true)) {
                continue;
            }

            $className = $this->tryResolve($ref);

            if ($className === null || $this->registry->accessVerbs($className) === []) {
                $skipped[] = $ref;
                continue;
            }

            [$section, $classTotals, $classViolations] = $this->buildRelated(
                $ref,
                $className,
                (string) $ownerColumn,
                $paths,
                $liveIds,
                $parents,
                $includeIds
            );

            $related[$ref] = $section;
            $totals[$ref] = $classTotals;
            $violations = array_merge($violations, $classViolations);
        }

        sort($skipped);

        return [
            'pages' => $pages,
            'related' => $related,
            'totals' => $totals,
            'violations' => $violations,
            'skipped' => $skipped,
        ];
    }

    /**
     * Whether the site declares api_access on a SiteTree SUBCLASS but not
     * on SiteTree itself — accessVerbs() resolves through an INHERITED
     * config lookup (see ClassRegistry's own docblock), so a project that
     * only sets access on, say, `Page` (not the `SiteTree` base) still
     * means every SiteTree row is meant to be readable; checking the base
     * class alone would report the whole pages section as unreadable.
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
        } catch (ApiError) {
            return null;
        }
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>,
     *   2: array<int, true>, 3: array<int, int>, 4: array<int, string>,
     *   5: array{draft: int, live: int}}
     */
    protected function buildPages(bool $includeIds): array
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

        $liveIds = array_fill_keys(
            array_map('intval', Versioned::get_by_stage(SiteTree::class, Versioned::LIVE)->column('ID')),
            true
        );

        $paths = [];
        $parents = [];

        foreach ($byId as $id => $page) {
            $parents[$id] = (int) $page->ParentID;
            $paths[$id] = $this->pathFor($id, $byId);
        }

        $rows = [];
        $classNames = [];

        foreach ($byId as $id => $page) {
            $classNames[$id] = $page->ClassName;

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

        $totals = ['draft' => count($byId), 'live' => count($liveIds)];

        return [$rows, $paths, $liveIds, $parents, $classNames, $totals];
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
     * parity endpoint).
     *
     * @param array<int, string> $paths
     * @param array<int, true> $liveIds
     * @param array<int, int> $parents
     * @param array<int, string> $classNames
     * @return array<int, array{className: string, path: string, blockedBy: array<int, string>}>
     */
    protected function pageViolations(array $paths, array $liveIds, array $parents, array $classNames): array
    {
        $violations = [];

        foreach ($paths as $id => $path) {
            if (!isset($liveIds[$id])) {
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
     * Every non-live ancestor above `$id`, sorted — shared between
     * `pageViolations()` (a live page directly checking its own
     * ancestors) and `buildRelated()` (a live related record's owner
     * page's ancestors, one hop further up). Independent of whether the
     * page at `$id` itself is live — the caller decides what "blocked"
     * means for whatever it's checking.
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
     * @return array{0: array{records: array<int, array<string, mixed>>, unresolved: int},
     *   1: array{draft: int, live: int},
     *   2: array<int, array{className: string, path: string, blockedBy: array<int, string>}>}
     */
    protected function buildRelated(
        string $ref,
        string $className,
        string $ownerColumn,
        array $pagePaths,
        array $pageLiveIds,
        array $pageParents,
        bool $includeIds
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

        $rows = [];
        $unresolved = 0;
        $violations = [];
        $total = 0;

        foreach ($records as $record) {
            $total++;
            $id = (int) $record->ID;
            $ownerId = (int) $record->getField($ownerColumn);
            $ownerPath = $pagePaths[$ownerId] ?? null;
            $isLive = isset($liveIds[$id]);

            if ($ownerPath === null) {
                $unresolved++;
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
                // page in that position would be.
                $ownerBlockedBy = isset($pageLiveIds[$ownerId])
                    ? $this->ancestorBlockers($ownerId, $pagePaths, $pageLiveIds, $pageParents)
                    : [$ownerPath];

                if ($ownerBlockedBy !== []) {
                    $violations[] = [
                        'className' => $className,
                        'path' => $ownerPath,
                        'blockedBy' => $ownerBlockedBy,
                    ];
                }
            }
        }

        usort($rows, static fn (array $a, array $b) => $a['ownerPath'] <=> $b['ownerPath']);

        return [
            ['records' => $rows, 'unresolved' => $unresolved],
            ['draft' => $total, 'live' => count($liveIds)],
            $violations,
        ];
    }
}
