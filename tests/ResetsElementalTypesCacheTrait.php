<?php

namespace Dynamic\ContentApi\Tests;

use DNADesign\Elemental\Extensions\ElementalAreasExtension;

/**
 * Shared by {@see ContentApiTestCase} and any `SapphireTest` (not
 * `FunctionalTest`) that also exercises `ElementalAreasExtension::
 * getElementalTypes()` directly, e.g. `ElementPlacementPolicyTest`.
 *
 * `getElementalTypes()` caches its result in a static on this branch's
 * elemental (6.2+ only) — a leftover cache entry here can leak between
 * tests unless cleared via `::reset()`. That static cache doesn't exist at
 * all pre-6.2, including every 5.x release branch `1` depends on, so
 * `::reset()` itself is undefined there — the `method_exists()` guard makes
 * this call branch-neutral: a real reset here, a correct no-op on branch
 * `1` (nothing was ever cached to leak).
 */
trait ResetsElementalTypesCacheTrait
{
    protected static function resetElementalTypesCache(): void
    {
        if (method_exists(ElementalAreasExtension::class, 'reset')) {
            ElementalAreasExtension::reset();
        }
    }
}
