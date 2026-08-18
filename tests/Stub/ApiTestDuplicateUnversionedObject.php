<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Deliberately UNversioned intermediate for `walkDuplicates()` (#174), the
 * mirror of what {@see ApiTestUnversionedOwnedWrapperObject} does for
 * `walk()`.
 *
 * Declares no `$cascade_duplicates` and a has_many in `$owns`, so
 * `RecursivePublishable::onBeforeDuplicate()` substitutes the `$owns`-derived
 * list and `duplicate()` clones `Leaves` — even though this class isn't
 * `Versioned`. That hook is attached to `DataObject` itself, not to
 * `Versioned`, which is exactly what an earlier cut of `duplicatedRelations()`
 * got wrong: gating the fallback on `Versioned` pruned here and silently lost
 * the versioned leaf below.
 *
 * The has_many matters — the framework's fallback intersects `$owns` with
 * many_many/belongs_to/has_many only, so a has_one (which
 * `ApiTestUnversionedOwnedWrapperObject` uses) would not exercise this path.
 */
class ApiTestDuplicateUnversionedObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestDuplicateUnversioned';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Root' => ApiTestDuplicateRootObject::class,
    ];

    private static array $has_many = [
        'Leaves' => ApiTestDuplicateLeafObject::class . '.Wrapper',
    ];

    private static array $owns = [
        'Leaves',
    ];

    public function canView($member = null): bool
    {
        return true;
    }
}
