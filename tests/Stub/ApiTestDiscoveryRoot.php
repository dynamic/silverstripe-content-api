<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Root of a small class hierarchy used only to test ClassRegistry's
 * discovery_roots mechanism in isolation from real elemental/SiteTree
 * classes. Carries no api_access of its own — a discovery test root must be
 * exposed (or not) purely by discovery, not by an explicit grant.
 */
class ApiTestDiscoveryRoot extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestDiscoveryRoot';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    public function canView($member = null): bool
    {
        return true;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return true;
    }
}
