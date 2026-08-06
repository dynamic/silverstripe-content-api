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
 */
class ApiTestGrantExtraSourceObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantExtraSourceObject';

    private static string $content_api_access = 'read,create,action';

    private static array $extensions = [
        ApiTestGrantExtraSourceExtension::class,
    ];
}
