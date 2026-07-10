<?php

namespace Dynamic\ContentApi\Publish;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Owns every stage transition the API performs, so publish semantics are
 * explicit and predictable — the fixture workflow's draft-only writes and
 * publish-cascade ambiguity are the bugs this class exists to prevent.
 *
 * Publish modes: `none` (leave on draft), `single` (publishSingle),
 * `recursive` (publishRecursive — note SS6 does NOT cascade a page's
 * publishRecursive into owned elemental blocks; composition-level cascades
 * publish each written element explicitly in M4).
 */
class PublishOrchestrator
{
    use Injectable;

    public const MODES = ['none', 'single', 'recursive'];

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

        if ($mode === 'recursive') {
            $record->publishRecursive();

            return;
        }

        $record->publishSingle();
    }

    /**
     * Remove the record from the live stage, keeping the draft.
     */
    public function unpublish(DataObject $record): void
    {
        $this->assertVersioned($record, 'unpublish');
        $record->doUnpublish();
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
     * (default) or `unpublish`; `hard` is only valid for unversioned classes,
     * where all modes converge on delete().
     *
     * @throws ApiError PAYLOAD_INVALID
     */
    public function delete(DataObject $record, string $mode): void
    {
        if (!in_array($mode, PublishOrchestrator::DELETE_MODES, true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf(
                    'Delete mode "%s" must be one of: %s.',
                    $mode,
                    implode(', ', PublishOrchestrator::DELETE_MODES)
                )
            );
        }

        if (!$record->hasExtension(Versioned::class)) {
            $record->delete();

            return;
        }

        match ($mode) {
            'archive' => $record->doArchive(),
            'unpublish' => $record->doUnpublish(),
            'hard' => throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf(
                    '%s is versioned — use mode "archive" (both stages, recoverable) or "unpublish".',
                    get_class($record)
                )
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
