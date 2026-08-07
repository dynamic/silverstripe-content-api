<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Middle tier of the #120 owned-tree fixture — see
 * ApiTestOwnedGrandchildObject's docblock. Owns its own `Grandchildren`
 * has_many, so a walk through this class exercises depth >= 2 — the exact
 * shape `DraftLiveParityTask`'s first, single-level version originally
 * missed (a page's CTA block's own owned cards, and their own owned links,
 * a fourth level down, per that task's docblock).
 */
class ApiTestOwnedChildObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestOwnedChildObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Parent' => ApiTestOwnedParentObject::class,
        'FeaturedGrandchild' => ApiTestOwnedGrandchildObject::class,
    ];

    private static array $has_many = [
        'Grandchildren' => ApiTestOwnedGrandchildObject::class,
    ];

    private static array $owns = [
        'Grandchildren',
        'FeaturedGrandchild',
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
