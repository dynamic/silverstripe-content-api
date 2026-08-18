<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;

/**
 * A `SiteTree` subclass that ALSO declares an `$owns` relation to another
 * page of its own class — built for #119's `unpublishOwnedTree()` guard
 * regression: nothing in the framework or `OwnedTreeWalker` prevents an
 * owned relation from itself being a `SiteTree`, so the #71
 * stranded-descendants guard has to run on every walked target, not just
 * the caller's own root. Every other `$owns` fixture in this suite
 * (`ApiTestOwnedParentObject` and friends) is a plain `DataObject` with no
 * `Hierarchy` tree of its own, which is exactly why that gap went
 * uncovered — this stub is the one shape that can catch it.
 */
class ApiTestOwnedPageObject extends ApiTestPage implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestOwnedPageObject';

    private static array $has_one = [
        'OwnedPage' => ApiTestOwnedPageObject::class,
    ];

    private static array $owns = [
        'OwnedPage',
    ];

    public function canEdit($member = null): bool
    {
        return true;
    }
}
