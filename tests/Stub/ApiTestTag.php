<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * many_many target of ApiTestObject (with SortOrder extra field).
 */
class ApiTestTag extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestTag';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $belongs_many_many = [
        'Objects' => ApiTestObject::class,
    ];

    public function canView($member = null): bool
    {
        return true;
    }
}
