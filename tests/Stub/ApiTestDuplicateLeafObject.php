<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Depth-2 grandchild of {@see ApiTestDuplicateRootObject}, reached only via
 * {@see ApiTestDuplicateChildObject}'s `$owns` fallback (#174). Versioned, so
 * the walk emits it — that emission is the whole assertion.
 */
class ApiTestDuplicateLeafObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestDuplicateLeaf';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Child' => ApiTestDuplicateChildObject::class,
    ];

    private static array $extensions = [
        Versioned::class,
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
