<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Deliberately UNversioned, sitting between a versioned owner and a
 * versioned leaf in `OwnedTreeWalkerTest`'s "walk through, don't emit, an
 * unversioned intermediate" coverage — `RecursivePublishable::
 * rollbackRelations()` recurses INTO an unversioned owned record's own
 * owned relations (it only skips reporting draft/live state for something
 * that has none), and the walker must match that rather than pruning the
 * whole branch there.
 */
class ApiTestUnversionedOwnedWrapperObject extends DataObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestUnversionedOwnedWrapperObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Leaf' => ApiTestOwnedGrandchildObject::class,
    ];

    private static array $owns = [
        'Leaf',
    ];

    public function canView($member = null): bool
    {
        return true;
    }
}
