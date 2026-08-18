<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Depth-1 child of {@see ApiTestDuplicateRootObject}, and the fixture for the
 * `$owns`-fallback case (#174).
 *
 * Declares **no** `$cascade_duplicates` at all while being `Versioned` — so
 * `RecursivePublishable::onBeforeDuplicate()` substitutes
 * `$owns ∩ (many_many + belongs_to + has_many)` and `duplicate()` clones
 * `Leaves` anyway. A `walkDuplicates()` that just read `$cascade_duplicates`
 * would stop here and silently lose the grandchildren; nothing else covers
 * them either, because a page-level `$owns` walk stops at an element that
 * declares no `$owns`.
 *
 * `Note` is a has_one in `$owns` that the fallback must NOT pick up — the
 * framework excludes has_one from that intersection on purpose, since an
 * owned has_one can be shared non-exclusively by clone and original.
 */
class ApiTestDuplicateChildObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestDuplicateChild';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Root' => ApiTestDuplicateRootObject::class,
        'Note' => ApiTestVersionedObject::class,
    ];

    private static array $has_many = [
        'Leaves' => ApiTestDuplicateLeafObject::class,
    ];

    private static array $owns = [
        'Leaves',
        'Note',
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
