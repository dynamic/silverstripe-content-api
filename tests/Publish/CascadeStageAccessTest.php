<?php

namespace Dynamic\ContentApi\Tests\Publish;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestEditAllowingExtension;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedParentObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPublishDenyingExtension;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * #226/#235: a cascade checks the record-level publish method on every
 * record it reaches, not only the root. The root here allows edit (so it
 * passes) and the owned child allows edit but denies canPublish().
 */
class CascadeStageAccessTest extends ContentApiTestCase
{
    protected static $required_extensions = [
        ApiTestOwnedParentObject::class => [ApiTestEditAllowingExtension::class],
        ApiTestOwnedChildObject::class => [ApiTestPublishDenyingExtension::class],
    ];

    /**
     * @return array{0: ApiTestOwnedParentObject, 1: ApiTestOwnedChildObject}
     */
    private function tree(): array
    {
        $parent = ApiTestOwnedParentObject::create(['Title' => 'Cascade Parent']);
        $parent->write();
        $child = ApiTestOwnedChildObject::create(['Title' => 'Cascade Child', 'ParentID' => $parent->ID]);
        $child->write();

        return [$parent, $child];
    }

    private function isLive(string $class, int $id): bool
    {
        return Versioned::get_by_stage($class, Versioned::LIVE)->byID($id) !== null;
    }

    public function testRecursiveRefusesAnOwnedRecordThatDeniesCanPublish(): void
    {
        $this->assertCascadeRefuses('recursive');
    }

    public function testOwnsRefusesAnOwnedRecordThatDeniesCanPublish(): void
    {
        $this->assertCascadeRefuses('owns');
    }

    private function assertCascadeRefuses(string $mode): void
    {
        [$parent, $child] = $this->tree();
        $member = $this->objFromFixture(Member::class, 'apiUser');

        try {
            PublishOrchestrator::create()->publish($parent, $mode, $member);
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertSame('FORBIDDEN_RECORD', $error->toArray()['code']);
            $this->assertStringContainsString('publish', $error->getMessage());
        }

        $this->assertFalse($this->isLive(ApiTestOwnedParentObject::class, (int) $parent->ID));
        $this->assertFalse($this->isLive(ApiTestOwnedChildObject::class, (int) $child->ID));
    }
}
