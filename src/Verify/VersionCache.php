<?php

namespace Dynamic\ContentApi\Verify;

use Closure;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Primes Versioned's version-number cache for a set of records on both
 * stages, so a per-row canView() (Versioned::canViewVersioned()) or
 * stagesDiffer() reads the cache instead of issuing a query per row (#244).
 *
 * Batched, so a large list never builds one huge `IN (...)` (MySQL refuses
 * more than 65,535 placeholders, and versioned 3's priming loop is
 * quadratic in the batch size). Uses prepopulateVersionNumberCacheForStage()
 * where it exists (versioned 3.1+), which caches a miss too; the older
 * prepopulate_versionnumber_cache() caches only the ids it finds, so here a
 * missing id (a draft-only record, on the live stage) is cached as "none"
 * so its lookup doesn't fall through to a query.
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

        $modern = method_exists(Versioned::class, 'prepopulateVersionNumberCacheForStage');
        $method = $modern ? 'prepopulateVersionNumberCacheForStage' : 'prepopulate_versionnumber_cache';
        $baseClass = DataObject::getSchema()->baseDataClass($className);

        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), VersionCache::BATCH) as $batch) {
            foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
                call_user_func([Versioned::class, $method], $className, $stage, $batch);

                if (!$modern) {
                    VersionCache::cacheMisses($baseClass, $stage, $batch);
                }
            }
        }
    }

    /**
     * @param int[] $ids
     */
    private static function cacheMisses(string $baseClass, string $stage, array $ids): void
    {
        // The cache is a protected static on Versioned; 0 reads back as
        // "no version on this stage".
        Closure::bind(static function () use ($baseClass, $stage, $ids) {
            foreach ($ids as $id) {
                Versioned::$cache_versionnumber[$baseClass][$stage][$id] ??= 0;
            }
        }, null, Versioned::class)();
    }
}
