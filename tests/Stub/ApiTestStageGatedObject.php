<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Versioned model any member may view, edit and delete but never publish or
 * unpublish — a project whose canPublish()/canUnpublish() is stricter than
 * canEdit() (#235).
 */
class ApiTestStageGatedObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestStageGatedObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $extensions = [
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

    public function canPublish($member = null): bool
    {
        return false;
    }

    public function canUnpublish($member = null): bool
    {
        return false;
    }
}
