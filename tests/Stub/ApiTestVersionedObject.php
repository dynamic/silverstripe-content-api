<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Versioned test model for stage-aware read tests.
 */
class ApiTestVersionedObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestVersionedObject';

    private static array $db = [
        'Title' => 'Varchar',
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
