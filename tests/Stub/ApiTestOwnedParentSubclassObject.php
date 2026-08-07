<?php

namespace Dynamic\ContentApi\Tests\Stub;

use SilverStripe\Dev\TestOnly;

/**
 * A subclass of `ApiTestOwnedParentObject`, existing solely for
 * `RecordParityTest`'s regression coverage of a real bug found by code
 * review: a record converted to a different class on draft only (`POST
 * pages/$ID/convert` with `publish: "none"`) has a live row whose
 * `ClassName` is the OLD class — querying the live row through THIS
 * (narrower) class's own subclass set, rather than the record's true base
 * class, silently fails to find it, since a subclass's `subclassesFor()`
 * never includes its own ancestor.
 */
class ApiTestOwnedParentSubclassObject extends ApiTestOwnedParentObject implements TestOnly
{
    private static string $table_name = 'ContentApi_ApiTestOwnedParentSubclassObject';
}
