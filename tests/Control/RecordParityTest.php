<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedGrandchildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedParentObject;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * End-to-end coverage for #120's `GET records/$ClassRef/$ID/parity` —
 * "does this record, and everything it $owns, match between draft and
 * live." `OwnedTreeWalkerTest` covers the walker in isolation; this file
 * covers the HTTP surface: field comparison, the liveExists/ok semantics,
 * the include/depth params, and authorization.
 */
class RecordParityTest extends ContentApiTestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->mintTokenFor('adminUser');
    }

    private function inDraft(callable $callback): mixed
    {
        return Versioned::withVersionedMode(function () use ($callback) {
            Versioned::set_stage(Versioned::DRAFT);

            return $callback();
        });
    }

    private function createAndPublish(string $class, array $fields = []): DataObject
    {
        return $this->inDraft(function () use ($class, $fields) {
            /** @var DataObject $record */
            $record = $class::create($fields);
            $record->write();
            $record->publishSingle();

            return $record;
        });
    }

    private function createDraftOnly(string $class, array $fields = []): DataObject
    {
        return $this->inDraft(function () use ($class, $fields) {
            /** @var DataObject $record */
            $record = $class::create($fields);
            $record->write();

            return $record;
        });
    }

    public function testAllParityWhenEverythingIsPublished(): void
    {
        $parent = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Parent']);
        $child = $this->createAndPublish(ApiTestOwnedChildObject::class, [
            'Title' => 'Child',
            'ParentID' => $parent->ID,
        ]);
        $this->createAndPublish(ApiTestOwnedGrandchildObject::class, [
            'Title' => 'Grandchild',
            'ParentID' => $child->ID,
        ]);

        $body = $this->decode($this->apiGet("records/ApiTestOwnedParent/{$parent->ID}/parity", $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertTrue($body['data']['liveExists']);
        $this->assertTrue($body['data']['ok']);
        $this->assertTrue($body['data']['fields']['Title']['match']);
        $this->assertCount(2, $body['data']['owned']);
        $this->assertSame([true, true], array_column($body['data']['owned'], 'live'));
        $this->assertNotEmpty($body['data']['report']);

        foreach ($body['data']['report'] as $entry) {
            $this->assertTrue($entry['ok'], $entry['message']);
        }
    }

    public function testFieldMismatchAfterPublishIsReported(): void
    {
        $record = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Original title']);

        $this->inDraft(function () use ($record) {
            $record->Title = 'Changed after publish';
            $record->write();
        });

        $body = $this->decode($this->apiGet("records/ApiTestOwnedParent/{$record->ID}/parity", $this->adminToken));

        $this->assertFalse($body['data']['ok']);
        $this->assertFalse($body['data']['fields']['Title']['match']);
        $this->assertSame('Original title', $body['data']['fields']['Title']['live']);
        $this->assertSame('Changed after publish', $body['data']['fields']['Title']['draft']);
    }

    /**
     * The exact bug class #120 exists to catch: a live root whose owned
     * tree has a draft-only piece two levels down (a page's element, and
     * that element's own owned child) — not caught by a single-level
     * check, per `DraftLiveParityTask`'s own docblock describing the real
     * bug it was revised to catch.
     */
    public function testDraftOnlyOwnedDescendantAtDepthTwoIsReportedAsAMismatch(): void
    {
        $parent = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Parent']);
        $child = $this->createAndPublish(ApiTestOwnedChildObject::class, [
            'Title' => 'Child',
            'ParentID' => $parent->ID,
        ]);
        $grandchild = $this->createDraftOnly(ApiTestOwnedGrandchildObject::class, [
            'Title' => 'Never published',
            'ParentID' => $child->ID,
        ]);

        $body = $this->decode($this->apiGet("records/ApiTestOwnedParent/{$parent->ID}/parity", $this->adminToken));

        $this->assertFalse($body['data']['ok']);

        // Filtered by className AND id — ids aren't globally unique across
        // classes (each table has its own AUTO_INCREMENT sequence), so
        // filtering on id alone risks matching the child's entry instead
        // of the grandchild's if the two happen to share a numeric id.
        $grandchildEntry = current(array_filter(
            $body['data']['owned'],
            fn (array $entry) => $entry['id'] === (int) $grandchild->ID
                && $entry['className'] === ApiTestOwnedGrandchildObject::class
        ));

        $this->assertNotFalse($grandchildEntry, 'the depth-2 grandchild must appear in the owned report');
        $this->assertSame(2, $grandchildEntry['depth']);
        $this->assertFalse($grandchildEntry['live']);
    }

    /**
     * A record that was never published is a legitimate state, not a
     * failure — every owned descendant being draft-only too is consistent
     * with an unpublished branch, not a mismatch.
     */
    public function testRootNeverPublishedIsNotAFailure(): void
    {
        $parent = $this->createDraftOnly(ApiTestOwnedParentObject::class, ['Title' => 'Never published']);
        $this->createDraftOnly(ApiTestOwnedChildObject::class, [
            'Title' => 'Also draft-only',
            'ParentID' => $parent->ID,
        ]);

        $body = $this->decode($this->apiGet("records/ApiTestOwnedParent/{$parent->ID}/parity", $this->adminToken));

        $this->assertFalse($body['data']['liveExists']);
        $this->assertTrue($body['data']['ok'], 'a consistently unpublished branch must not report as a failure');
        $this->assertSame([], $body['data']['fields']);

        foreach ($body['data']['owned'] as $entry) {
            $this->assertFalse($entry['live']);
        }

        foreach ($body['data']['report'] as $entry) {
            $this->assertTrue($entry['ok']);
        }
    }

    public function testIncludeNoneSkipsTheOwnedWalk(): void
    {
        $parent = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Parent']);
        $this->createDraftOnly(ApiTestOwnedChildObject::class, [
            'Title' => 'Would be a mismatch if walked',
            'ParentID' => $parent->ID,
        ]);

        $body = $this->decode(
            $this->apiGet("records/ApiTestOwnedParent/{$parent->ID}/parity?include=none", $this->adminToken)
        );

        $this->assertSame([], $body['data']['owned']);
        $this->assertTrue($body['data']['ok'], 'a skipped owned walk must not surface as a failure');
    }

    public function testDepthParamCapsTheOwnedWalk(): void
    {
        $parent = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Parent']);
        $child = $this->createAndPublish(ApiTestOwnedChildObject::class, [
            'Title' => 'Child',
            'ParentID' => $parent->ID,
        ]);
        $this->createAndPublish(ApiTestOwnedGrandchildObject::class, [
            'Title' => 'Grandchild',
            'ParentID' => $child->ID,
        ]);

        $body = $this->decode(
            $this->apiGet("records/ApiTestOwnedParent/{$parent->ID}/parity?depth=1", $this->adminToken)
        );

        $this->assertCount(1, $body['data']['owned'], 'depth=1 must stop after the child');
        $this->assertSame(1, $body['data']['owned'][0]['depth']);
    }

    public function testNonVersionedClassIsRejected(): void
    {
        $record = ApiTestObject::create(['Title' => 'Not versioned']);
        $record->write();

        $response = $this->apiGet("records/ApiTest/{$record->ID}/parity", $this->adminToken);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testUnknownRecordIs404(): void
    {
        $response = $this->apiGet('records/ApiTestOwnedParent/999999999/parity', $this->adminToken);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    /**
     * A member whose api_access doesn't cover the class at all is refused
     * before anything is fetched or leaked — ApiTestOwnedParentObject's
     * own `canView()` always returns true in this fixture, so the
     * record-level check alone can't be exercised here; this confirms the
     * class-level gate instead (`RecordParityTest::
     * testAForbiddenOwnedClassFailsTheWholeRequest` covers the same gate
     * on an OWNED class, not the root).
     */
    public function testForbiddenRootClassIs403(): void
    {
        $record = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Secret']);

        Config::modify()->set(ApiTestOwnedParentObject::class, 'api_access', false);

        $response = $this->apiGet("records/ApiTestOwnedParent/{$record->ID}/parity", $this->adminToken);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    /**
     * The same check-everything-before-emitting-anything precedent
     * `PublishOrchestrator::collectSubtreeTargets()` established: a
     * forbidden OWNED class must fail the whole request, not silently
     * omit that branch from the report.
     */
    public function testAForbiddenOwnedClassFailsTheWholeRequest(): void
    {
        $parent = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Parent']);
        $this->createAndPublish(ApiTestOwnedChildObject::class, [
            'Title' => 'Child',
            'ParentID' => $parent->ID,
        ]);

        Config::modify()->set(ApiTestOwnedChildObject::class, 'api_access', false);

        $response = $this->apiGet("records/ApiTestOwnedParent/{$parent->ID}/parity", $this->adminToken);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }
}
