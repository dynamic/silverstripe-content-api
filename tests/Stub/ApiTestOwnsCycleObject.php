<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Identity\ExternalIdentifierExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Self-referential `$owns` stub for `OwnedTreeWalkerTest`'s cycle-guard
 * coverage — two records pointing at each other via `Next` (both declared
 * `$owns`) form a genuine two-node cycle, which nothing in SilverStripe
 * itself prevents.
 */
class ApiTestOwnsCycleObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestOwnsCycleObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Next' => ApiTestOwnsCycleObject::class,
    ];

    private static array $owns = [
        'Next',
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
