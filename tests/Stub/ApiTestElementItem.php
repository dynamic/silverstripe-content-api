<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * has_many child of ApiTestElement (versioned, like real element children).
 */
class ApiTestElementItem extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestElementItem';

    private static array $db = [
        'Title' => 'Varchar',
        'SortOrder' => 'Int',
    ];

    private static array $has_one = [
        'Element' => ApiTestElement::class,
    ];

    private static array $extensions = [
        Versioned::class,
        ExternalIdentifierExtension::class,
    ];

    public function canView($member = null): bool
    {
        return true;
    }
}
