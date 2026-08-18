<?php

namespace Dynamic\ContentApi\Publish;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Security\PermissionPolicy;
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
 * composition-level cascades publish each written element explicitly in
 * M4), `subtree` (publishes the record, then every draft tree child,
 * depth-first — publishRecursive() does NOT cascade to `Hierarchy` tree
 * children either; it does cascade to other owned relations, just not the
 * Elemental one per above; a caller restructuring a 30-page subtree needs
 * `subtree` instead of 30 individual `single` calls). See #71 and
 * `docs/en/10_publishing-and-stages.md`.
 *
 * `subtree` alone also authorization-checks every descendant it visits
 * (#90), and takes `$liveOnly` (skip a descendant branch — no publish, no
 * further recursion — that isn't already live, #102) and `$dryRun`
 * (preview the would-publish set without writing) via {@see publish()}.
 */
class PublishOrchestrator
{
    use Injectable;

    private static array $dependencies = [
        'policy' => '%$' . PermissionPolicy::class,
    ];

    public ?PermissionPolicy $policy = null;

    public const MODES = ['none', 'single', 'recursive', 'subtree'];

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
     * for unversioned classes. `$member` gates `subtree`'s per-descendant
     * authorization (see {@see publishSubtree()}) — unused by the other
     * modes, whose target record the caller has already checked before
     * calling here, but required uniformly so every call site states who's
     * publishing rather than only the ones that currently need it.
     *
     * `$liveOnly` and `$dryRun` apply to `subtree` only; both are ignored
     * for the single-record modes, where they'd be meaningless (a mode
     * that publishes exactly the one already-checked record you asked for
     * has no descendants to filter or preview).
     *
     * @return array<int, array{id: int, className: string}>|null a preview
     *   entry per record touched (or, under `dryRun`, would be touched)
     *   when `$mode` is `subtree`; `null` for every other mode
     * @throws ApiError FORBIDDEN_CLASS | FORBIDDEN_RECORD if `$member` may
     *   not act on a descendant `subtree` would otherwise publish
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
     * calling {@see publish()}. It's weaker at `RecordWriter` (checks
     * `update`, not `action` — a class granting `update` but withholding
     * `action` could still have its root published via `subtree` through a
     * write) and at `PageHandler::convert()` (checks the *pre-conversion*
     * record's `update`, never the *target* class's verbs at all before
     * publishing the converted instance as root). Both gaps predate this
     * method and apply equally to every mode, not just `subtree` — but
     * `subtree` raises the stakes from one record to a whole tree. Tracked
     * as #114; not one this method can close on its own since it only ever
     * sees the root after that decision has already been made.
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
     * Empty for a record that isn't a `SiteTree` with
     * `enforce_strict_hierarchy` enabled, or one that doesn't exist in that
     * stage.
     *
     * The scope check is intentionally narrower than "any `Hierarchy`
     * class" (#89): the cascade this guard exists to prevent —
     * `SiteTree::onBeforeDelete()` deleting every current
     * `AllChildren()` when a page is deleted from a stage — only fires on
     * `SiteTree` itself, and only when `SiteTree::config()->get('enforce_
     * strict_hierarchy')` is true (the framework default; note this reads
     * `SiteTree`'s own config, not `static::config()`, so a subclass
     * override doesn't change whether the cascade actually runs). `Hierarchy`
     * itself declares no `onBeforeDelete`/`onAfterDelete`, and `Versioned`
     * only has `onAfterDelete` — so a `Hierarchy`-extended non-`SiteTree`
     * class, or a project that has turned the config off, was previously
     * refused here (and required `force`) for a cascade that was never
     * actually going to happen.
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
        if (!$record instanceof SiteTree || !SiteTree::config()->get('enforce_strict_hierarchy')) {
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
