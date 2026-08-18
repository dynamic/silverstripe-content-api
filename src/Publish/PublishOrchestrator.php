<?php

namespace Dynamic\ContentApi\Publish;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Verify\OwnedTreeWalker;
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * Owns every stage transition the API performs, so publish semantics are
 * explicit and predictable — the fixture workflow's draft-only writes and
 * publish-cascade ambiguity are the bugs this class exists to prevent.
 *
 * Publish modes: `none` (leave on draft), `single` (publishSingle),
 * `recursive` (publishRecursive — does NOT cascade into a page's owned
 * elemental blocks, confirmed identical on branches `1` and `2` (#91), so
 * composition-level cascades used to publish each written element
 * explicitly by hand instead — see `owns` below, which replaced that),
 * `subtree` (publishes the record, then every draft tree child,
 * depth-first — publishRecursive() does NOT cascade to `Hierarchy` tree
 * children either; it does cascade to other owned relations, just not the
 * Elemental one per above; a caller restructuring a 30-page subtree needs
 * `subtree` instead of 30 individual `single` calls), `owns` (publishes
 * every {@see OwnedTreeWalker}-reachable descendant, then the record
 * itself — #119's publish half, see {@see publishOwnedTree()}). See #71
 * and `docs/en/10_publishing-and-stages.md`.
 *
 * `subtree` authorization-checks every descendant it visits (#90), and
 * takes `$liveOnly` (skip a descendant branch — no publish, no further
 * recursion — that isn't already live, #102) and `$dryRun` (preview the
 * would-publish set without writing) via {@see publish()}. `owns`
 * authorization-checks every owned descendant the same way (#168) and
 * also takes `$dryRun`, but not `$liveOnly` — meaningless for an owned
 * (non-tree) cascade.
 */
class PublishOrchestrator
{
    use Injectable;

    private static array $dependencies = [
        'policy' => '%$' . PermissionPolicy::class,
        'ownedTreeWalker' => '%$' . OwnedTreeWalker::class,
    ];

    public ?PermissionPolicy $policy = null;

    public ?OwnedTreeWalker $ownedTreeWalker = null;

    public const MODES = ['none', 'single', 'recursive', 'subtree', 'owns'];

    /**
     * Publish modes valid at the whole-composition level. `single` is
     * deliberately excluded: a composition is inherently multi-record (page +
     * area + elements + children), so publishing "just one record" would leave
     * the rest on draft — the invisible half-published state this module
     * exists to prevent. Per-record `single` still applies inside batch ops.
     */
    public const COMPOSITION_MODES = ['none', 'recursive'];

    public const DELETE_MODES = ['archive', 'unpublish', 'hard'];

    /**
     * @throws ApiError PAYLOAD_INVALID on an unknown mode
     */
    public function assertValidMode(string $mode): void
    {
        if (!in_array($mode, PublishOrchestrator::MODES, true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Publish mode "%s" must be one of: %s.', $mode, implode(', ', PublishOrchestrator::MODES))
            );
        }
    }

    /**
     * Apply a publish mode to a freshly written record. No-op for `none` and
     * for unversioned classes. `$member` gates `subtree`'s and `owns`'s
     * per-descendant authorization (see {@see publishSubtree()},
     * {@see publishOwnedTree()}) — unused by the other modes, whose target
     * record the caller has already checked before calling here, but
     * required uniformly so every call site states who's publishing rather
     * than only the ones that currently need it.
     *
     * `$dryRun` applies to `subtree` and `owns`; ignored for the other
     * modes, where it'd be meaningless (a mode that publishes exactly the
     * one already-checked record you asked for has no descendants to
     * preview). `$liveOnly` applies to `subtree` only — `owns` walks an
     * owned-relation graph, not a `Hierarchy` tree, so "skip a branch
     * that isn't already live" doesn't translate; a caller who passes it
     * with `owns` is refused at the handler level rather than silently
     * ignored (see `RecordActionsHandler`).
     *
     * @return array<int, array{id: int, className: string}>|null a preview
     *   entry per record touched (or, under `dryRun`, would be touched)
     *   when `$mode` is `subtree` or `owns`; `null` for every other mode
     * @throws ApiError FORBIDDEN_CLASS | FORBIDDEN_RECORD if `$member` may
     *   not act on a descendant `subtree`/`owns` would otherwise publish
     */
    public function publish(
        DataObject $record,
        string $mode,
        Member $member,
        bool $liveOnly = false,
        bool $dryRun = false
    ): ?array {
        $this->assertValidMode($mode);

        if ($mode === 'none' || !$record->hasExtension(Versioned::class)) {
            return null;
        }

        if ($mode === 'subtree') {
            return $this->publishSubtree($record, $member, $liveOnly, $dryRun);
        }

        if ($mode === 'owns') {
            return $this->publishOwnedTree($record, $member, [], $dryRun);
        }

        if ($mode === 'recursive') {
            $record->publishRecursive();

            return null;
        }

        $record->publishSingle();

        return null;
    }

    /**
     * Publish the record itself, then every draft `Hierarchy` tree child,
     * depth-first. A no-op walk for a class that isn't hierarchical (the
     * publish itself still happens). Two passes, deliberately: first
     * {@see collectSubtreeTargets()} walks and authorization-checks the
     * *entire* subtree with no writes at all, then this method only starts
     * calling `publishSingle()` once that whole walk has come back clean —
     * matching {@see unpublish()}/{@see archive()}'s own check-everything-
     * before-touching-anything shape (`assertNoDescendants()` runs in full
     * before `doUnpublish()`/`doArchive()`), rather than discovering a
     * permission gap on descendant #12 after descendants #1-11 are already
     * live with no way to undo it.
     *
     * `$dryRun` runs the same authorization-checked collection pass and
     * returns its result, without this method ever calling `publishSingle()`.
     *
     * @return array<int, array{id: int, className: string}>
     * @throws ApiError FORBIDDEN_CLASS | FORBIDDEN_RECORD
     */
    protected function publishSubtree(DataObject $record, Member $member, bool $liveOnly, bool $dryRun): array
    {
        $targets = $this->collectSubtreeTargets($record, $member, $liveOnly, true);

        if (!$dryRun) {
            foreach ($targets as $target) {
                $target->publishSingle();
            }
        }

        return array_map(fn (DataObject $target) => $this->subtreeEntry($target), $targets);
    }

    /**
     * Deliberately re-reads children in DRAFT stage on every call rather
     * than trusting an ambient stage the caller may or may not have already
     * set — self-contained regardless of calling context.
     *
     * The root record is assumed to be the caller's own already-authorized
     * target, so only descendants — `$isRoot === false` — are checked here.
     * #90's gap was specifically that the subtree walk touched every
     * *descendant* with no equivalent check at all: a token scoped to
     * `Page` could publish a descendant of a subclass whose own
     * `api_access` only grants `read`, or a member without CMS access to a
     * specific child page could still publish it by publishing an ancestor
     * they can edit. That gap is closed for descendants.
     *
     * The root assumption itself holds precisely at `RecordActionsHandler`,
     * which checks `canEdit()` (`action` verb) on the exact record before
     * calling {@see publish()}. It used to be weaker everywhere else a
     * root could reach `publish()` without an explicit `action` check —
     * `RecordWriter::write()` only checked `update`, and
     * `PageHandler::convert()`/`CompositionService::convertPage()` checked
     * the *pre-conversion* record's `update`, never the *target* class's
     * verbs at all before publishing the converted instance as root. Fixed
     * in #114 for those three call sites, plus `CompositionService::compose()`'s
     * own page-level publish (`publishAll()`'s direct `$page->publishRecursive()`
     * call, reachable with no `convertTo` at all): `RecordWriter::write()`
     * now also checks the class-level `action` verb whenever its payload's
     * `publish` key isn't `none`; both conversion paths and `compose()`
     * itself now check the relevant class's `action` verb under the same
     * condition. This method itself still can't close the gap on its own —
     * it only ever sees the root after the caller's own decision has
     * already been made — so those checks live at each call site, not here.
     *
     * NOT covered by #114, and deliberately out of its scope: `publishAll()`
     * used to also publish the composition's *area* and every *element*
     * (and element child) via `publish($record, 'single', $member)` —
     * 'single' mode performs no authorization at all, and nothing upstream
     * checked `action` for those classes either (their own writes always
     * pass `"publish": "none"` explicitly, so `RecordWriter::write()`'s
     * #114 check never fired for them). That was the owned-relation
     * cascade #119 formalizes with real authorization — tracked as #168,
     * closed by routing both `CompositionService::publishAll()` and
     * `PageHandler::applyTemplate()` through {@see publishOwnedTree()}
     * instead of their own hand-rolled loops.
     *
     * `$liveOnly` (#102) skips a descendant branch entirely — no
     * collecting it as a target, no recursing into its own children, no
     * authorization check either, since it's never going to be touched —
     * when the descendant isn't already live. Without this, `subtree`
     * resurrects a page a caller deliberately took offline just because it
     * still has a live ancestor being restructured; see
     * `docs/en/10_publishing-and-stages.md` for the incident this traces
     * back to.
     *
     * @return DataObject[]
     * @throws ApiError FORBIDDEN_CLASS | FORBIDDEN_RECORD
     */
    protected function collectSubtreeTargets(DataObject $record, Member $member, bool $liveOnly, bool $isRoot): array
    {
        if (!$isRoot) {
            if ($liveOnly && !$record->isPublished()) {
                return [];
            }

            $this->assertDescendantPublishable($record, $member);
        }

        $targets = [$record];

        if (!$record->hasExtension(Hierarchy::class)) {
            return $targets;
        }

        $children = Versioned::withVersionedMode(function () use ($record) {
            Versioned::set_stage(Versioned::DRAFT);

            return iterator_to_array($record->AllChildren());
        });

        foreach ($children as $child) {
            $targets = array_merge($targets, $this->collectSubtreeTargets($child, $member, $liveOnly, false));
        }

        return $targets;
    }

    /**
     * @throws ApiError FORBIDDEN_CLASS | FORBIDDEN_RECORD
     */
    protected function assertDescendantPublishable(DataObject $record, Member $member): void
    {
        $className = get_class($record);

        $this->policy->checkClassAccess($className, 'action', $member);
        $this->policy->checkRecordAccess($record, 'action', $member);
    }

    /**
     * @return array{id: int, className: string}
     */
    protected function subtreeEntry(DataObject $record): array
    {
        return [
            'id' => (int) $record->ID,
            'className' => get_class($record),
        ];
    }

    /**
     * Publish `$root` plus every {@see OwnedTreeWalker}-reachable owned
     * descendant, with every one of them authorization-checked first —
     * #119's publish half, closing #168.
     *
     * `$additional` names records outside the walked `$owns` graph that
     * should be published (and authorization-checked) alongside it. This is
     * load-bearing, not defensive padding: Elemental's `ElementalPageExtension`
     * declares `$owns = ['ElementalArea']` and `ElementalArea` declares
     * `$owns = ['Elements']`, but `BaseElement` itself declares no `$owns`
     * at all — so an element's own has_many children (e.g. `ElementFeatures`
     * → `FeatureObject`) are unreachable by walk unless a project opts in.
     * `CompositionService::publishAll()` uses this to pass the exact
     * area/elements/children it just wrote, so the walk's own coverage gap
     * can't silently regress what its old hand-rolled loop used to publish.
     * `PageHandler::applyTemplate()` also passes the area/elements here, but
     * for a narrower reason — see that method's own docblock for why its
     * `$additional` isn't equivalent to `publishAll()`'s (it doesn't track
     * writes, and doesn't include element children; #174). Deduplicated
     * against the walked set, against itself, and against `$root` (a caller
     * passing the root here is very unlikely given the two current call
     * sites, but would otherwise both double-authorize and double-publish
     * it) — all on `ClassName:ID`.
     *
     * Every non-`$root` entry in `$additional` must be a `DataObject` that
     * `exists()` — anything else means the caller passed something it
     * shouldn't have (a wrong array shape, or a record it thinks it wrote
     * but didn't), and is refused loudly (`PAYLOAD_INVALID`) rather than
     * silently dropped, so that kind of bug can't masquerade as "nothing to
     * publish." An unversioned entry is the one genuinely expected case
     * (e.g. Essentials' `StatCounter`, a plain `DataObject` has_many child)
     * — dropped with a logged warning, matching {@see OwnedTreeWalker}'s
     * own handling of a misconfigured `$owns` entry.
     *
     * Same check-everything-before-writing-anything shape as
     * {@see publishSubtree()}: every target is authorization-checked
     * (class `action` verb + `canEdit()`, {@see assertDescendantPublishable()})
     * before any `publishSingle()` call, so a gap discovered on target #12
     * can't leave #1-11 live with no way to undo it. `$root` itself is
     * NOT checked here — same contract as {@see collectSubtreeTargets()}:
     * the root is assumed to be the caller's own already-authorized
     * target (checked at the call site, e.g. `RecordWriter::write()`'s
     * #114 `action`-verb gate, or `CompositionService::compose()`'s own
     * page-level check). Callers should wrap the whole call in a
     * transaction if a mid-cascade authorization failure shouldn't leave
     * an earlier, unrelated write persisted — see `CompositionHandler`
     * and `PageHandler::applyTemplate()`, both of which do.
     *
     * Descendants publish first, in walk order, then `$root` itself last
     * via `publishSingle()` — never `publishRecursive()`, since the walk
     * is already a superset of what `publishRecursive()` reaches (#71
     * established `publishRecursive()` misses Elemental entirely) and
     * mixing the two would double-publish with ambiguous ordering.
     *
     * `$root` itself must be `Versioned`. `RecordActionsHandler`/
     * `PageHandler::convert()` reach this via `publish()`'s own dispatch,
     * which already guards on that before ever reaching the `owns` branch —
     * but `CompositionService::publishAll()` and `PageHandler::applyTemplate()`
     * call this method directly, bypassing that guard entirely, so it is
     * load-bearing for them, not merely repeated for a hypothetical future
     * caller.
     *
     * @param DataObject[] $additional
     * @return array<int, array{id: int, className: string}> every record
     *   published (or, under `dryRun`, that would be published) — `$root`
     *   included, last
     * @throws ApiError PAYLOAD_INVALID | FORBIDDEN_CLASS | FORBIDDEN_RECORD
     */
    public function publishOwnedTree(
        DataObject $root,
        Member $member,
        array $additional = [],
        bool $dryRun = false
    ): array {
        $this->assertVersioned($root, 'publish the owned tree of');

        $targets = [];

        foreach ($this->ownedTreeWalker->walk($root) as $entry) {
            $key = get_class($entry['record']) . ':' . $entry['record']->ID;
            $targets[$key] = $entry['record'];
        }

        foreach ($additional as $record) {
            if (!$record instanceof DataObject) {
                throw new ApiError(
                    ErrorCode::PAYLOAD_INVALID,
                    sprintf(
                        '$additional must contain only DataObject instances, got %s.',
                        is_object($record) ? get_class($record) : gettype($record)
                    )
                );
            }

            if (!$record->exists()) {
                throw new ApiError(
                    ErrorCode::PAYLOAD_INVALID,
                    sprintf('$additional contains an unsaved %s — every target must already exist.', get_class($record))
                );
            }

            if (!$record->hasExtension(Versioned::class)) {
                Injector::inst()->get(LoggerInterface::class)->warning(sprintf(
                    '%s #%d passed to publishOwnedTree() via $additional is not Versioned — skipped.',
                    get_class($record),
                    $record->ID
                ));

                continue;
            }

            $key = get_class($record) . ':' . $record->ID;
            $targets[$key] ??= $record;
        }

        unset($targets[get_class($root) . ':' . $root->ID]);

        foreach ($targets as $target) {
            $this->assertDescendantPublishable($target, $member);
        }

        if (!$dryRun) {
            foreach ($targets as $target) {
                $target->publishSingle();
            }

            $root->publishSingle();
        }

        $entries = array_map(fn (DataObject $target) => $this->subtreeEntry($target), array_values($targets));
        $entries[] = $this->subtreeEntry($root);

        return $entries;
    }

    /**
     * Whether `force: true` on `unpublish`/`archive` for this class could
     * possibly bypass a real cascade risk — i.e. whether
     * `findDescendantIDs()`'s own `SiteTree` + `enforce_strict_hierarchy`
     * scoping (#89) could ever find something to strand for it. Class-level
     * only (no record needed), so callers can use it before fetching one.
     *
     * Exposed specifically so `RecordActionsHandler`'s force-unpublish
     * `delete`-verb gate (#80) can't drift out of sync with this scoping
     * the next time either is rescoped — a hardcoded copy of the same
     * `instanceof`/config check here and there would silently diverge if
     * this one ever changes. On a non-`SiteTree` class, or a project that
     * has turned `enforce_strict_hierarchy` off, `force: true` is already a
     * no-op (see #89) — requiring `delete` to use it there would demand a
     * verb for a bypass that was never going to happen, the same mistake
     * #89 fixed in the other direction.
     */
    public function forceCouldStrandDescendants(string $className): bool
    {
        return is_a($className, SiteTree::class, true)
            && (bool) SiteTree::config()->get('enforce_strict_hierarchy');
    }

    /**
     * Remove the record from the live stage, keeping the draft.
     *
     * Guards against a real, confirmed-live SilverStripe framework
     * behavior (#71, found during a real IA restructure — see
     * `docs/en/10_publishing-and-stages.md`): `SiteTree::onBeforeDelete()`
     * cascades to `AllChildren()` and deletes them too, whenever
     * `SiteTree.enforce_strict_hierarchy` is enabled (the framework
     * default). `doUnpublish()` deletes the record from the LIVE stage
     * internally, so on a `Hierarchy` record with ANY live children —
     * whether or not those children have moved in draft — unpublishing
     * silently cascades the whole live subtree away with it, recursively.
     * (An earlier version of this guard only checked for children whose
     * draft parent had diverged from live, matching the original bug
     * report's framing; that undercounted the real risk — a live child
     * that hasn't moved at all is cascade-deleted exactly the same way,
     * confirmed by a test written to prove the narrower guard sufficient,
     * which failed.) `$force` bypasses the guard for the case where the
     * cascade is actually intended.
     *
     * @throws ApiError UNPUBLISH_STRANDS_DESCENDANTS unless $force
     */
    public function unpublish(DataObject $record, bool $force = false): void
    {
        $this->assertVersioned($record, 'unpublish');

        if (!$force) {
            $this->assertNoDescendants($record, [Versioned::LIVE], 'Unpublishing');
        } else {
            $this->logForcedBypass($record, [Versioned::LIVE], 'Unpublishing');
        }

        $record->doUnpublish();
    }

    /**
     * @param string[] $stages checked as a union — descendants present in
     *   any of these stages are enough to refuse
     * @throws ApiError UNPUBLISH_STRANDS_DESCENDANTS
     */
    protected function assertNoDescendants(DataObject $record, array $stages, string $verbGerund): void
    {
        $descendantIDs = $this->findDescendantIDsInAnyStage($record, $stages);

        if ($descendantIDs === []) {
            return;
        }

        throw new ApiError(
            ErrorCode::UNPUBLISH_STRANDS_DESCENDANTS,
            sprintf(
                '%s %s #%d would also remove %d descendant(s) currently nested under it (ids: %s) '
                    . '— `enforce_strict_hierarchy` cascades a delete to every child a record '
                    . 'currently has in the stage(s) being deleted from, whether or not that child '
                    . 'has also been reparented in draft. Publish/move them to their intended new '
                    . 'home first if this record is being retired as part of a restructure, then '
                    . 'retry — or pass force to proceed anyway and accept the loss.',
                $verbGerund,
                get_class($record),
                (int) $record->ID,
                count($descendantIDs),
                $this->formatIDsForMessage($descendantIDs)
            )
        );
    }

    /**
     * The bypass itself is the caller's explicit, requested choice — not a
     * failure — but it's still the single riskiest operation this class
     * performs, and previously left no trace anywhere that it happened.
     * Logged at warning (not error) precisely because it's expected,
     * intentional behavior, not a fault.
     *
     * @param string[] $stages
     */
    protected function logForcedBypass(DataObject $record, array $stages, string $verbGerund): void
    {
        $descendantIDs = $this->findDescendantIDsInAnyStage($record, $stages);

        if ($descendantIDs === []) {
            return;
        }

        Injector::inst()->get(LoggerInterface::class)->warning(sprintf(
            '%s %s #%d with force=true, stranding %d descendant(s) (ids: %s).',
            $verbGerund,
            get_class($record),
            (int) $record->ID,
            count($descendantIDs),
            $this->formatIDsForMessage($descendantIDs)
        ));
    }

    /**
     * @param string[] $stages
     * @return int[]
     */
    protected function findDescendantIDsInAnyStage(DataObject $record, array $stages): array
    {
        $ids = [];

        foreach ($stages as $stage) {
            $ids = array_merge($ids, $this->findDescendantIDs($record, $stage));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Caps how many ids get interpolated into a message — a real restructure
     * can strand hundreds of descendants at once, and neither the decision
     * nor the message's actionability needs every single one spelled out;
     * `count()` already carries the true magnitude.
     *
     * @param int[] $ids
     */
    protected function formatIDsForMessage(array $ids, int $limit = 20): string
    {
        if (count($ids) <= $limit) {
            return implode(', ', $ids);
        }

        return implode(', ', array_slice($ids, 0, $limit)) . sprintf(', and %d more', count($ids) - $limit);
    }

    /**
     * IDs of every `Hierarchy` descendant of $record in the given stage.
     * Empty when {@see forceCouldStrandDescendants()} says this class has
     * no real cascade risk to begin with, or the record doesn't exist in
     * that stage.
     *
     * The scope check (#89) is intentionally narrower than "any
     * `Hierarchy` class": the cascade this guard exists to prevent —
     * `SiteTree::onBeforeDelete()` deleting every current `AllChildren()`
     * when a page is deleted from a stage — only fires on `SiteTree`
     * itself, and only when `SiteTree.enforce_strict_hierarchy` is enabled
     * (the framework default). `Hierarchy` itself declares no
     * `onBeforeDelete`/`onAfterDelete`, and `Versioned` only has
     * `onAfterDelete` — so a `Hierarchy`-extended non-`SiteTree` class, or
     * a project that has turned the config off, was previously refused
     * here (and required `force`) for a cascade that was never actually
     * going to happen. `forceCouldStrandDescendants()` is the one place
     * this decision is made — shared with `RecordActionsHandler`'s #80
     * force-unpublish `delete`-verb gate, so the two can't drift apart.
     *
     * Deliberately queries by $record's own concrete class
     * (`get_class($record)`), not `Hierarchy::getHierarchyBaseClass()` —
     * this is load-bearing, not an oversight. `Versioned::doUnpublish()`
     * itself resolves the live-stage row via `$owner::get()->byID($id)`
     * (late static binding on the *same* concrete class), and
     * `deleteFromStage()` clones `$owner` directly rather than re-querying
     * by class at all. So whenever a class-name mismatch between stages
     * (e.g. a page type conversion made in draft but not yet republished)
     * would cause this method to miss a stage's row, the corresponding
     * `doUnpublish()`/`deleteFromStage()` call misses that exact same row
     * for the exact same reason — there is no live/draft delete for this
     * method to have failed to guard against. Querying a broader base
     * class here would desync the two and could refuse (or worse, permit)
     * based on descendants the actual delete call can't reach anyway.
     *
     * @return int[]
     */
    protected function findDescendantIDs(DataObject $record, string $stage): array
    {
        if (!$this->forceCouldStrandDescendants(get_class($record))) {
            return [];
        }

        $baseClass = get_class($record);
        $id = (int) $record->ID;

        return Versioned::withVersionedMode(function () use ($baseClass, $id, $stage) {
            Versioned::set_stage($stage);
            $staged = DataObject::get($baseClass)->byID($id);

            if (!$staged) {
                return [];
            }

            // getDescendantIDList() is a Hierarchy mixin method, confirmed
            // present by the instanceof SiteTree check above — SiteTree
            // declares the Hierarchy extension, and PHPStan can't see that
            // this freshly-fetched instance carries it too.
            /** @var DataObject&Hierarchy $staged */
            return $staged->getDescendantIDList();
        });
    }

    /**
     * Remove the record from both stages (recoverable from version
     * history). Shares {@see unpublish()}'s guard: `doArchive()` calls
     * `doUnpublish()` internally (live-stage cascade risk), then also
     * deletes the draft-stage row directly (`deleteFromStage(DRAFT)`,
     * itself another `enforce_strict_hierarchy`-cascading delete) — so
     * archive risks stranding descendants in *either* stage, checked here
     * as the union of both.
     *
     * @throws ApiError UNPUBLISH_STRANDS_DESCENDANTS unless $force
     */
    public function archive(DataObject $record, bool $force = false): void
    {
        $this->assertVersioned($record, 'archive');

        $stages = [Versioned::LIVE, Versioned::DRAFT];

        if (!$force) {
            $this->assertNoDescendants($record, $stages, 'Archiving');
        } else {
            $this->logForcedBypass($record, $stages, 'Archiving');
        }

        $record->doArchive();
    }

    /**
     * Delete with an explicit mode. Versioned records accept `archive`
     * (default) or `unpublish` only — `hard` is rejected up front, so the
     * "must be one of" error never lists a mode this record can't actually
     * use. Unversioned classes accept all of DELETE_MODES, where every mode
     * converges on delete(). `unpublish` mode routes through
     * {@see unpublish()} — same stranded-descendants guard, same `$force`
     * override.
     *
     * @throws ApiError PAYLOAD_INVALID | UNPUBLISH_STRANDS_DESCENDANTS
     */
    public function delete(DataObject $record, string $mode, bool $force = false): void
    {
        $isVersioned = $record->hasExtension(Versioned::class);
        $validModes = $isVersioned ? ['archive', 'unpublish'] : PublishOrchestrator::DELETE_MODES;

        if (!in_array($mode, $validModes, true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf(
                    'Delete mode "%s" must be one of: %s.',
                    $mode,
                    implode(', ', $validModes)
                )
            );
        }

        if (!$isVersioned) {
            $record->delete();

            return;
        }

        match ($mode) {
            'archive' => $this->archive($record, $force),
            'unpublish' => $this->unpublish($record, $force),
            // Unreachable — $validModes above already rejected anything else.
            default => throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Delete mode "%s" must be one of: archive, unpublish.', $mode)
            ),
        };
    }

    private function assertVersioned(DataObject $record, string $action): void
    {
        if (!$record->hasExtension(Versioned::class)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Cannot %s %s — the class is not versioned.', $action, get_class($record))
            );
        }
    }
}
