<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\Versioned\Versioned;

/**
 * A versioned, tree-shaped model that is NOT a SiteTree — for #89, which
 * needs a `Hierarchy`-extended class distinct from `SiteTree` to prove the
 * descendant-cascade guard no longer over-applies to it. `SiteTree` is the
 * only class `SiteTree::onBeforeDelete()`'s cascade actually fires on
 * ({@see \Dynamic\ContentApi\Publish\PublishOrchestrator::findDescendantIDs()}),
 * so unpublishing/archiving this class with live/draft children must never
 * require `force`.
 */
class ApiTestHierarchyObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestHierarchyObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $extensions = [
        Hierarchy::class,
        Versioned::class,
    ];

    private static bool $api_access = true;

    public function canView($member = null): bool
    {
        return true;
    }

    public function canEdit($member = null): bool
    {
        return true;
    }

    public function canDelete($member = null): bool
    {
        return true;
    }
}
