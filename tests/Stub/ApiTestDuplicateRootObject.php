<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Root of the three-level fixture `OwnedTreeWalkerTest` uses to pin
 * {@see \Dynamic\ContentApi\Verify\OwnedTreeWalker::walkDuplicates()} against
 * what `DataObject::duplicate()` really creates (#174). Shaped so the two
 * walk modes disagree in both directions:
 *
 * - `Shared` is a **many_many** named in `$cascade_duplicates` AND in
 *   `$owns`. `walk()` must follow it; `walkDuplicates()` must not, because
 *   `duplicateManyManyRelation()` link-copies rather than clones, so those
 *   records pre-date the duplicate.
 * - `Nested` is a has_many named in `$cascade_duplicates` only, so only
 *   `walkDuplicates()` reaches it — and through it, the depth-2 fallback
 *   case on {@see ApiTestDuplicateChildObject}.
 */
class ApiTestDuplicateRootObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestDuplicateRoot';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_many = [
        'Nested' => ApiTestDuplicateChildObject::class,
    ];

    private static array $many_many = [
        'Shared' => ApiTestVersionedObject::class,
    ];

    private static array $owns = [
        'Shared',
    ];

    private static array $cascade_duplicates = [
        'Shared',
        'Nested',
    ];

    private static array $extensions = [
        Versioned::class,
    ];

    public function canView($member = null): bool
    {
        return true;
    }

    public function canEdit($member = null): bool
    {
        return true;
    }
}
