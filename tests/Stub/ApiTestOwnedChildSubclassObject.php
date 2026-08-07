<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;

/**
 * A subclass of `ApiTestOwnedChildObject` — the owned-descendant-side
 * counterpart to `ApiTestOwnedParentSubclassObject`, for `RecordParityTest`
 * coverage proving the base-class query fix applies to `compareOwned()`
 * (each owned entry's live-existence check), not only `compareFields()`
 * (the root's).
 */
class ApiTestOwnedChildSubclassObject extends ApiTestOwnedChildObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestOwnedChildSubclassObject';
}
