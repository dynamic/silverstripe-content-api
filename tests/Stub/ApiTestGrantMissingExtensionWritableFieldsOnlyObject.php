<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Negative control for `checkMissingGrantExtension()`: declares
 * `api_writable_fields` but no `api_access`/`content_api_access` at all,
 * and carries no `ContentApiGrantExtension`. Must NOT be flagged — with no
 * `api_access` grant the class isn't exposed for writes through this API
 * regardless of `api_writable_fields` (`WriteApplicator`'s allowlist only
 * matters once a class is already exposed), so there is nothing here for a
 * missing grant extension to break either. Proves `checkMissingGrantExtension()`
 * gates on `ownAccessVerbs()` — not on `api_writable_fields` presence alone.
 */
class ApiTestGrantMissingExtensionWritableFieldsOnlyObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantMissingExtWritableOnlyObj';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $api_writable_fields = ['Title'];
}
