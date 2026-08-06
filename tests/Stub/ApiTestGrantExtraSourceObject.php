<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Carries {@see ApiTestGrantExtraSourceExtension} — NOT
 * `ContentApiGrantExtension` directly — and declares its own access.
 * `Config::inst()->get(static::class, 'extensions')` (no exclude flag)
 * shows `ContentApiGrantExtension` here too, contributed by the applied
 * extension's own static config, exactly the extra-source pollution
 * `DataObject::has_extension()` is supposed to exclude.
 * `GrantExtensionReachabilityCheckerTest` uses this to prove the checker
 * doesn't false-positive on it — `Extensible::getExtensionInstances()`
 * never actually instantiates `ContentApiGrantExtension` for this class,
 * so there's no real grant to check reachability for.
 *
 * `canEdit()` deliberately hard-overrides without calling
 * `extendedCan()` — same shape as {@see ApiTestGrantUnreachableObject}
 * — so `carriesGrantExtension()`'s extra-source exclusion is the *only*
 * thing standing between this class and a finding. Without that
 * override, `canEdit()` would inherit `DataObject::canEdit()`, which
 * itself calls `extendedCan()`, and the class would produce no finding
 * regardless of whether `carriesGrantExtension()` correctly excludes
 * extra sources or not — the test wouldn't actually prove the fix.
 */
class ApiTestGrantExtraSourceObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantExtraSourceObject';

    private static string $content_api_access = 'read,create,action';

    private static array $extensions = [
        ApiTestGrantExtraSourceExtension::class,
    ];

    public function canEdit($member = null): ?bool
    {
        return false;
    }
}
