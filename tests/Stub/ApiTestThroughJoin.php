<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Join DataObject backing ApiTestObject's `ThroughTags` many_many through
 * relation to ApiTestTag — carries its own extra data (SortOrder,
 * IsCurrent) the same way many_many_extraFields does for `Tags`, but as
 * real $db fields on this join class rather than a config map.
 */
class ApiTestThroughJoin extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestThroughJoin';

    private static array $db = [
        'SortOrder' => 'Int',
        'IsCurrent' => 'Boolean',
    ];

    private static array $has_one = [
        'Owner' => ApiTestObject::class,
        'Tag' => ApiTestTag::class,
    ];
}
