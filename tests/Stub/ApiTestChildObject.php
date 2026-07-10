<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * has_many child of ApiTestObject for relation-write tests.
 */
class ApiTestChildObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestChildObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Parent' => ApiTestObject::class,
    ];

    public function canView($member = null): bool
    {
        return true;
    }

    public function canEdit($member = null): bool
    {
        return true;
    }
}
