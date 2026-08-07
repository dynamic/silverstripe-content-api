<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

/**
 * #131 `FingerprintService` "related" fixture with a REAL `canView()`
 * denial (ADMIN only) — `ApiTestFingerprintRelatedObject`'s own
 * `canView()` always returns `true`, which can't exercise the per-row
 * record-level ACL filter `FingerprintService::buildRelated()` applies via
 * `PermissionPolicy::canViewRecord()`. This stub exists specifically to
 * prove that filter actually denies.
 */
class ApiTestFingerprintRestrictedRelatedObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestFPRestrictedRelated';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Page' => SiteTree::class,
    ];

    private static array $extensions = [
        Versioned::class,
    ];

    private static bool $api_access = true;

    public function canView($member = null): bool
    {
        return $member instanceof Member && Permission::checkMember($member, 'ADMIN');
    }
}
