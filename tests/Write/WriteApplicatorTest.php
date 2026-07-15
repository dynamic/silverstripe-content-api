<?php

namespace Dynamic\ContentApi\Tests\Write;

use Dynamic\ContentApi\Tests\Stub\ApiTestCascadeObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPolyObject;
use Dynamic\ContentApi\Write\WriteApplicator;
use SilverStripe\Dev\SapphireTest;

class WriteApplicatorTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        ApiTestPolyObject::class,
        ApiTestCascadeObject::class,
    ];

    public function testTrustedChannelMaySetPolymorphicClassColumnDirectly(): void
    {
        // #25's "reject a direct {Name}Class payload key" rule exists to
        // stop untrusted request input from setting an arbitrary raw class
        // string with no ClassRegistry validation — it must not also block
        // the trusted $internalFields channel (the same one that already
        // writes ParentID/Sort), which by definition never carries
        // request-derived values.
        $applicator = WriteApplicator::create();
        $poly = ApiTestPolyObject::create(['Title' => 'Trusted Class write']);

        $applicator->applyFields($poly, ['Title' => 'Trusted Class write'], [
            'OwnerClass' => ApiTestCascadeObject::class,
        ]);

        $this->assertSame(ApiTestCascadeObject::class, $poly->OwnerClass);
    }
}
