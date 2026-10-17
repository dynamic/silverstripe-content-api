<?php

namespace Dynamic\ContentApi\Verify;

use SilverStripe\Core\Config\Config;
use SilverStripe\Versioned\Versioned;

/**
 * Primes Versioned's version-number cache for a set of records on both
 * stages, so a per-row canView() (Versioned::canViewVersioned()) or
 * stagesDiffer() reads the cache instead of issuing a query per row (#244).
 *
 * Batched, so a large list never builds one huge `IN (...)`: versioned 3's
 * priming loop is quadratic in the batch size. Branch `1`'s copy also
 * caches misses for versioned 2's older priming method; versioned 3's
 * prepopulateVersionNumberCacheForStage() already does.
 */
class VersionCache
{
    private const BATCH = 500;

    /**
     * @param int[] $ids
     */
    public static function prime(string $className, array $ids): void
    {
        if ($ids === [] || !Config::inst()->get(Versioned::class, 'prepopulate_versionnumber_cache')) {
            return;
        }

        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), VersionCache::BATCH) as $batch) {
            foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
                Versioned::prepopulateVersionNumberCacheForStage($className, $stage, $batch);
            }
        }
    }
}
