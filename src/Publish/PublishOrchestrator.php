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
     * Guards against the exact way this goes catastrophically wrong on a
     * `Hierarchy` tree (#71, confirmed live during a real IA restructure):
     * unpublishing a wrapper page whose still-live children were already
     * reparented elsewhere in draft silently drops the whole live subtree
     * under the wrapper — not just the wrapper itself — because those
     * children are still nested under it on LIVE even though draft no
     * longer agrees. `$force` bypasses the guard for the (rare, deliberate)
     * case where that's actually intended.
     *
     * @throws ApiError UNPUBLISH_STRANDS_DESCENDANTS unless $force
     */
    public function unpublish(DataObject $record, bool $force = false): void
    {
        $this->assertVersioned($record, 'unpublish');

        if (!$force) {
            $this->assertNoLiveDescendantsMovedInDraft($record);
        }

        $record->doUnpublish();
    }

    /**
     * @throws ApiError UNPUBLISH_STRANDS_DESCENDANTS
     */
    protected function assertNoLiveDescendantsMovedInDraft(DataObject $record): void
    {
        $strandedIDs = $this->findLiveDescendantsMovedInDraft($record);

        if ($strandedIDs === []) {
            return;
        }

        throw new ApiError(
            ErrorCode::UNPUBLISH_STRANDS_DESCENDANTS,
            sprintf(
                'Unpublishing %s #%d would also remove %d still-live descendant(s) that have '
                    . 'already moved to a different parent in draft (ids: %s) — publish them to '
                    . 'their new parent first, then unpublish this record. Pass force to '
                    . 'unpublish anyway and accept the loss.',
                get_class($record),
                (int) $record->ID,
                count($strandedIDs),
                implode(', ', $strandedIDs)
            )
        );
    }

    /**
     * IDs of records that are still live descendants of $record (reachable
     * from it via Hierarchy on the LIVE stage) but are no longer draft
     * descendants of it (reparented elsewhere, or removed from draft
     * entirely) — exactly the set doUnpublish() would silently strand.
     * Empty for a non-hierarchical class or one with no extension at all.
     *
     * @return int[]
     */
    protected function findLiveDescendantsMovedInDraft(DataObject $record): array
    {
        if (!$record->hasExtension(Hierarchy::class)) {
            return [];
        }

        $baseClass = get_class($record);
        $id = (int) $record->ID;

        $liveDescendantIDs = Versioned::withVersionedMode(function () use ($baseClass, $id) {
            Versioned::set_stage(Versioned::LIVE);
            $live = DataObject::get($baseClass)->byID($id);

            return $live ? $live->getDescendantIDList() : [];
        });

        if ($liveDescendantIDs === []) {
            return [];
        }

        return Versioned::withVersionedMode(function () use ($baseClass, $id, $liveDescendantIDs) {
            Versioned::set_stage(Versioned::DRAFT);
            $draft = DataObject::get($baseClass)->byID($id);
            $draftDescendantIDs = $draft ? $draft->getDescendantIDList() : [];

            return array_values(array_diff($liveDescendantIDs, $draftDescendantIDs));
        });
    }

    /**
     * Remove the record from both stages (recoverable from version history).
     */
    public function archive(DataObject $record): void
    {
        $this->assertVersioned($record, 'archive');
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
            'archive' => $record->doArchive(),
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
