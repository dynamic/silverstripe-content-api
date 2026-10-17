<?php

namespace Dynamic\ContentApi\Verify;

use SilverStripe\Versioned\Versioned;

/**
 * Primes Versioned's version-number cache for a set of records on both
 * stages, so a per-row canView() (Versioned::canViewVersioned()) or
 * stagesDiffer() reads the cache instead of issuing a query per row (#244).
 * Uses prepopulateVersionNumberCacheForStage() where it exists (versioned
 * 3.1+), else the older prepopulate_versionnumber_cache().
 */
class VersionCache
{
    /**
     * @param int[] $ids
     */
    public static function prime(string $className, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $method = method_exists(Versioned::class, 'prepopulateVersionNumberCacheForStage')
            ? 'prepopulateVersionNumberCacheForStage'
            : 'prepopulate_versionnumber_cache';

        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            call_user_func([Versioned::class, $method], $className, $stage, $ids);
        }
    }
}
