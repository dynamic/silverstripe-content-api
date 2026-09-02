<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Negative control for `checkMissingGrantExtension()`: declares `read`
 * only, no write verb (`create`/`update`/`delete`/`action`), and carries
 * no `ContentApiGrantExtension`. Must NOT be flagged — a read-only class
 * has nothing for a missing grant extension to break (nothing to 403 on),
 * and flagging it would point at the wrong fix: the real gap on a
 * read-only class is a missing `api_access` write grant, not a missing
 * extension. Proves `checkMissingGrantExtension()` gates on write verbs
 * specifically, not on "any exposure at all".
 */
class ApiTestGrantMissingExtensionReadOnlyObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantMissingExtReadOnlyObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static string $api_access = 'GET';
}
