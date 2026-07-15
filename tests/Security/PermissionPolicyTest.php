<?php

namespace Dynamic\ContentApi\Tests\Security;

use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPolyObject;
use SilverStripe\Core\Injector\Injector;

class PermissionPolicyTest extends ContentApiTestCase
{
    public function testBuildCreateContextHydratesAnExternalIdShapedHasOne(): void
    {
        // A tenant-scoped canCreate() implementation needs the related
        // record hydrated into the context regardless of whether the
        // payload named it by numeric id or by externalId — resolveRelation()
        // itself accepts both shapes as equally valid, so silently skipping
        // externalId here would be a fail-open gap for any canCreate() that
        // inspects the parent (e.g. checking a tenant/owner match).
        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $policy = Injector::inst()->get(PermissionPolicy::class);
        $method = new \ReflectionMethod($policy, 'buildCreateContext');
        $method->setAccessible(true);

        $context = $method->invoke($policy, ApiTestPolyObject::class, [
            'Owner' => ['class' => 'ApiTest', 'externalId' => 'alpha'],
        ]);

        $this->assertArrayHasKey('Owner', $context);
        $this->assertInstanceOf(ApiTestObject::class, $context['Owner']);
        $this->assertSame((int) $owner->ID, (int) $context['Owner']->ID);
    }
}
