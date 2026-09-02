<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * #197's real-world shape: declares its own `api_access` and
 * `api_writable_fields` — a class fully configured for content-API writes
 * — but extends a plain `DataObject` ancestor and carries no
 * `ContentApiGrantExtension` anywhere in its hierarchy. Confirmed on two
 * projects: a table-row/table-cell model and an image-slide/video-slide
 * model, neither extending `SiteTree`/`BaseElement`, so neither picked up
 * the extension the module's docs assume every class gets by default.
 * `GrantExtensionReachabilityChecker::check()` is structurally blind to
 * this — it only ever examines a class that already carries the extension.
 * Used by `GrantExtensionReachabilityCheckerTest` to prove
 * `checkMissingGrantExtension()` catches what `check()` can't.
 */
class ApiTestGrantMissingExtensionObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantMissingExtensionObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static string $api_access = 'GET,POST,PUT';

    private static array $api_writable_fields = ['Title'];
}
