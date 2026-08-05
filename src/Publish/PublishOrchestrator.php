<?php

namespace Dynamic\ContentApi\Publish;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\Versioned\Versioned;

/**
 * Owns every stage transition the API performs, so publish semantics are
 * explicit and predictable — the fixture workflow's draft-only writes and
 * publish-cascade ambiguity are the bugs this class exists to prevent.
 *
 * Publish modes: `none` (leave on draft), `single` (publishSingle),
 * `recursive` (publishRecursive — note SS6 does NOT cascade a page's
 * publishRecursive into owned elemental blocks; composition-level cascades
 * publish each written element explicitly in M4), `subtree` (publishes the
 * record, then every draft tree child, depth-first — publishRecursive()
 * does NOT cascade to `Hierarchy` tree children either, only owned
 * relations; a caller restructuring a 30-page subtree needs `subtree`
 * instead of 30 individual `single` calls). See #71 and
 * `docs/en/10_publishing-and-stages.md`.
 */
class PublishOrchestrator
{
    use Injectable;

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
     * for unversioned classes.
     */
    public function publish(DataObject $record, string $mode): void
    {
        $this->assertValidMode($mode);

        if ($mode === 'none' || !$record->hasExtension(Versioned::class)) {
            return;
        }

        if ($mode === 'subtree') {
            $this->publishSubtree($record);

            return;
        }

        if ($mode === 'recursive') {
            $record->publishRecursive();

            return;
        }

        $record->publishSingle();
    }

    /**
     * Publish the record itself, then every draft `Hierarchy` tree child,
     * depth-first. A no-op walk for a class that isn't hierarchical (the
     * publish itself still happens). Deliberately re-reads children in
     * DRAFT stage on every call rather than trusting an ambient stage the
     * caller may or may not have already set — self-contained regardless
     * of calling context.
     */
    protected function publishSubtree(DataObject $record): void
    {
        $record->publishSingle();

        if (!$record->hasExtension(Hierarchy::class)) {
            return;
        }

        $children = Versioned::withVersionedMode(function () use ($record) {
            Versioned::set_stage(Versioned::DRAFT);

            return iterator_to_array($record->AllChildren());
        });

        foreach ($children as $child) {
            $this->publishSubtree($child);
        }
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
            $this->assertNoLiveDescendants($record, 'Unpublishing');
        }

        $record->doUnpublish();
    }

    /**
     * @throws ApiError UNPUBLISH_STRANDS_DESCENDANTS
     */
    protected function assertNoLiveDescendants(DataObject $record, string $verbGerund): void
    {
        $descendantIDs = $this->findDescendantIDs($record, Versioned::LIVE);

        if ($descendantIDs === []) {
            return;
        }

        throw new ApiError(
            ErrorCode::UNPUBLISH_STRANDS_DESCENDANTS,
            sprintf(
                '%s %s #%d would also remove %d live descendant(s) still nested under it (ids: '
                    . '%s) — `enforce_strict_hierarchy` cascades a delete to every current child, '
                    . 'live or not. Publish them to their intended new parent first if this record '
                    . 'is being retired as part of a restructure, then retry — or pass force to '
                    . 'proceed anyway and accept the loss.',
                $verbGerund,
                get_class($record),
                (int) $record->ID,
                count($descendantIDs),
                implode(', ', $descendantIDs)
            )
        );
    }

    /**
     * IDs of every `Hierarchy` descendant of $record in the given stage.
     * Empty for a non-hierarchical class, one with no extension at all, or
     * a record that doesn't exist in that stage.
     *
     * @return int[]
     */
    protected function findDescendantIDs(DataObject $record, string $stage): array
    {
        if (!$record->hasExtension(Hierarchy::class)) {
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
            // present by the hasExtension() check above — PHPStan can't see
            // that guard applies to this freshly-fetched instance too.
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

        if (!$force) {
            $liveOrDraftDescendantIDs = array_unique(array_merge(
                $this->findDescendantIDs($record, Versioned::LIVE),
                $this->findDescendantIDs($record, Versioned::DRAFT)
            ));

            if ($liveOrDraftDescendantIDs !== []) {
                throw new ApiError(
                    ErrorCode::UNPUBLISH_STRANDS_DESCENDANTS,
                    sprintf(
                        'Archiving %s #%d would also remove %d descendant(s) still nested under '
                            . 'it in draft and/or live (ids: %s) — `enforce_strict_hierarchy` '
                            . 'cascades a delete to every current child in whichever stage is '
                            . 'being deleted from, and archive deletes from both. Move them out '
                            . 'from under this record first if that\'s not intended, or pass '
                            . 'force to proceed anyway and accept the loss.',
                        get_class($record),
                        (int) $record->ID,
                        count($liveOrDraftDescendantIDs),
                        implode(', ', $liveOrDraftDescendantIDs)
                    )
                );
            }
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
