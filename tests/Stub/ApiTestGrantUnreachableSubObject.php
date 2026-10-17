<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;

/**
 * Delegates `canEdit()` to a parent whose own `canEdit()` never reaches the
 * grant, so following `parent::` still ends unreachable and the checker
 * must still flag it (#245).
 */
class ApiTestGrantUnreachableSubObject extends ApiTestGrantUnreachableObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestGrantUnreachableSubObject';

    private static string $content_api_access = 'read,update';

    public function canEdit($member = null): ?bool
    {
        return parent::canEdit($member);
    }
}
