<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * #131 `FingerprintService` "related" fixture WITHOUT `Versioned` — a
 * non-versioned class has no stage concept at all, so it's always
 * effectively live; its own reachability is governed entirely by its
 * owner page. Covers the `$isVersioned === false` branch in
 * `FingerprintService::buildRelated()`, distinct from
 * `ApiTestFingerprintRelatedObject` (which IS versioned).
 */
class ApiTestFingerprintNonVersionedRelatedObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestFPNonVersionedRelated';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Page' => SiteTree::class,
    ];

    private static bool $api_access = true;

    public function canView($member = null): bool
    {
        return true;
    }
}
