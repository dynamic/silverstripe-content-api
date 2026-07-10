<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Unversioned test model. Records titled "Secret" refuse canView so list
 * filtering and record-level 403s can be exercised.
 */
class ApiTestObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestObject';

    private static array $db = [
        'Title' => 'Varchar',
        'Rank' => 'Int',
    ];

    private static array $has_one = [
        'Buddy' => ApiTestObject::class,
    ];

    private static array $extensions = [
        ExternalIdentifierExtension::class,
    ];

    private static bool $api_access = true;

    public function canView($member = null): bool
    {
        return $this->Title !== 'Secret';
    }

    public function canEdit($member = null): bool
    {
        return true;
    }

    public function canDelete($member = null): bool
    {
        return true;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return true;
    }
}
