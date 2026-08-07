<?php

namespace Dynamic\ContentApi\Control\Handlers;

use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Shared by every read-side handler that needs to run a callback against a
 * specific Versioned stage — `RecordsHandler` (draft/live list & single
 * reads) and `ParityHandler` (#120, which reads BOTH stages of the same
 * record). Extracted from `RecordsHandler`'s own former private method
 * rather than duplicated, since both need the identical no-op-for-
 * unversioned-classes guard.
 */
trait StageAwareTrait
{
    /**
     * Run a callable in the requested Versioned stage; no-op wrapper for
     * unversioned classes.
     */
    protected function withStage(string $className, string $stage, callable $callback): mixed
    {
        if (!DataObject::singleton($className)->hasExtension(Versioned::class)) {
            return $callback();
        }

        return Versioned::withVersionedMode(function () use ($stage, $callback) {
            Versioned::set_stage($stage === 'live' ? Versioned::LIVE : Versioned::DRAFT);

            return $callback();
        });
    }
}
