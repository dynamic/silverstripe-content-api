<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Root of the #120 owned-tree fixture — see ApiTestOwnedGrandchildObject's
 * docblock for why this three-level `$owns` hierarchy exists at all (no
 * real class in this module declares `$owns`).
 */
class ApiTestOwnedParentObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestOwnedParentObject';

    private static array $db = [
        'Title' => 'Varchar',
        'ParentID' => 'Int',
        'ShowInMenus' => 'Boolean',
        'URLSegment' => 'Varchar',
        'Sort' => 'Int',
    ];

    private static array $has_many = [
        'Children' => ApiTestOwnedChildObject::class,
    ];

    private static array $owns = [
        'Children',
    ];

    private static array $extensions = [
        Versioned::class,
        ExternalIdentifierExtension::class,
    ];

    private static bool $api_access = true;

    public function canView($member = null): bool
    {
        return true;
    }
}
