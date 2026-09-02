<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * The unrelated table `ApiTestSideEffectObject::onBeforeWrite()` writes to
 * as a side effect — see that class's docblock. Never itself part of a
 * request payload, never has `api_access`; the whole point is that
 * `verifyRollback()`/`DryRunCompleteException` have no reason to know this
 * class exists at all (#203).
 */
class ApiTestSideEffectLog extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestSideEffectLog';

    private static array $db = [
        'Note' => 'Varchar',
    ];
}
