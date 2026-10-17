<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;

/**
 * Declares no access of its own and inherits ApiTestGrantUnreachableObject's
 * extendedCan()-skipping canEdit(). Granted only under a grant root, where
 * the reachability checker must then flag it (#231).
 */
class ApiTestGrantUnreachableInheritingObject extends ApiTestGrantUnreachableObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantUnreachableInheritingObject';
}
