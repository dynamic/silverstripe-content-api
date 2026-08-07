<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedChildSubclassObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedGrandchildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedParentObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedParentSubclassObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
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
     * Code-review regression test for a critical bug: a record converted
     * to a different class on draft only (`POST pages/$ID/convert` with
     * `publish: "none"` is the module's own real write path for this) has
     * a live row whose ClassName is still the OLD class. Querying that
     * live row through the requested (now-different, narrower) class's
     * own subclass set — rather than the record's true base class —
     * silently fails to find it: a subclass's own subclass set never
     * includes its own ancestor. Before the fix, this reported
     * `liveExists: false` / `ok: true` for a record that was genuinely
     * live and genuinely divergent — the exact false-clean this endpoint
     * exists to prevent.
     */
    public function testFieldMismatchSurvivesAClassChangeThatOnlyHappenedOnDraft(): void
    {
        $record = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Still the old class on live']);

        $this->inDraft(function () use ($record) {
            $converted = $record->newClassInstance(ApiTestOwnedParentSubclassObject::class);
            $converted->write();
        });

        // Queried via the NEW (narrower) class — matching what a caller
        // would naturally do after converting a record, and the exact
        // shape that hid the bug (a query via the base class' own broader
        // subclass set would have masked it).
        $body = $this->decode(
            $this->apiGet("records/ApiTestOwnedParentSubclass/{$record->ID}/parity", $this->adminToken)
        );

        $this->assertTrue(
            $body['data']['liveExists'],
            'the live row must still be found even though its class no longer matches the requested class'
        );
        $this->assertFalse($body['data']['fields']['ClassName']['match']);
        $this->assertSame(ApiTestOwnedParentObject::class, $body['data']['fields']['ClassName']['live']);
        $this->assertSame(ApiTestOwnedParentSubclassObject::class, $body['data']['fields']['ClassName']['draft']);
    }

    /**
     * Same underlying bug as the test above, one level down: the fix
     * applies independently to `compareOwned()`'s per-entry live check,
     * not only `compareFields()`'s root check. An owned child converted
     * to a different class on draft only must still report `live: true`
     * — reverting only the owned-side fix (leaving the root-side fix in
     * place) leaves this specific case broken while every root-only test
     * still passes.
     */
    public function testAnOwnedDescendantConvertedOnDraftOnlyStillReportsLive(): void
    {
        $parent = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Parent']);
        $child = $this->createAndPublish(ApiTestOwnedChildObject::class, [
            'Title' => 'Still the old class on live',
            'ParentID' => $parent->ID,
        ]);

        $this->inDraft(function () use ($child) {
            $converted = $child->newClassInstance(ApiTestOwnedChildSubclassObject::class);
            $converted->write();
        });

        $body = $this->decode($this->apiGet("records/ApiTestOwnedParent/{$parent->ID}/parity", $this->adminToken));

        $this->assertTrue(
            $body['data']['owned'][0]['live'],
            'the owned child\'s live row must still be found even though its class no longer matches'
        );
        $this->assertTrue($body['data']['ok']);
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

    /**
     * Code-review regression test: the mirror image of the more common
     * "root live, descendant draft-only" bug — a live owned descendant
     * under a non-live root is stranded content (the root's own publish
     * history should have carried it, or never published it at all), and
     * must be reported as a mismatch too, not silently treated as "root
     * isn't live, so nothing here can be a problem."
     */
    public function testALiveOwnedDescendantUnderANonLiveRootIsReportedAsAMismatch(): void
    {
        $parent = $this->createDraftOnly(ApiTestOwnedParentObject::class, ['Title' => 'Never published']);
        $this->createAndPublish(ApiTestOwnedChildObject::class, [
            'Title' => 'Published independently — stranded',
            'ParentID' => $parent->ID,
        ]);

        $body = $this->decode($this->apiGet("records/ApiTestOwnedParent/{$parent->ID}/parity", $this->adminToken));

        $this->assertFalse($body['data']['liveExists']);
        $this->assertFalse($body['data']['ok'], 'a live owned descendant under a non-live root must be flagged');
        $this->assertTrue($body['data']['owned'][0]['live']);
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

    /**
     * Code-review regression test: a non-numeric `?depth=` value must not
     * silently coerce to `(int) 'garbage' === 0`, which would disable the
     * owned walk entirely with no indication why — the exact failure mode
     * a typo'd depth param produces.
     */
    public function testInvalidDepthParamsAreRejected(): void
    {
        $parent = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Parent']);

        foreach (['not-a-number', '-5'] as $badDepth) {
            $response = $this->apiGet(
                "records/ApiTestOwnedParent/{$parent->ID}/parity?depth={$badDepth}",
                $this->adminToken
            );

            $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
        }
    }

    /**
     * The companion case: `?depth=` with an empty value is treated the
     * same as omitting the param entirely (falls back to
     * `OwnedTreeWalker`'s configured default) — it must NOT be rejected
     * the way a genuinely malformed value is.
     */
    public function testAnEmptyDepthParamFallsBackToTheDefaultRatherThanBeingRejected(): void
    {
        $parent = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Parent']);
        $this->createAndPublish(ApiTestOwnedChildObject::class, [
            'Title' => 'Child',
            'ParentID' => $parent->ID,
        ]);

        $body = $this->decode(
            $this->apiGet("records/ApiTestOwnedParent/{$parent->ID}/parity?depth=", $this->adminToken)
        );

        $this->assertNull($body['error']);
        $this->assertCount(1, $body['data']['owned']);
    }

    public function testInvalidIncludeParamIsRejected(): void
    {
        $parent = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Parent']);

        $response = $this->apiGet(
            "records/ApiTestOwnedParent/{$parent->ID}/parity?include=everything",
            $this->adminToken
        );

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    /**
     * Code-review regression test: `resolveInclude()` originally used
     * `?:`, which treats the STRING "0" as falsy the same way an empty
     * string is — `?include=0` would have silently fallen back to
     * "owned" instead of being rejected as the malformed value it
     * genuinely is. `resolveDepth()`'s `=== ''` check never had this
     * problem; `resolveInclude()` needed the same fix.
     */
    public function testIncludeParamOfLiteralZeroIsRejectedNotSilentlyTreatedAsOwned(): void
    {
        $parent = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Parent']);

        $response = $this->apiGet("records/ApiTestOwnedParent/{$parent->ID}/parity?include=0", $this->adminToken);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    /**
     * The companion case to the test above: `?include=` with an empty
     * value is treated the same as omitting the param entirely (falls
     * back to `owned`) — it must NOT be rejected the way a genuinely
     * malformed value is. Mirrors `testAnEmptyDepthParamFallsBackTo
     * TheDefaultRatherThanBeingRejected` for the sibling param.
     */
    public function testAnEmptyIncludeParamFallsBackToOwnedRatherThanBeingRejected(): void
    {
        $parent = $this->createAndPublish(ApiTestOwnedParentObject::class, ['Title' => 'Parent']);
        $this->createAndPublish(ApiTestOwnedChildObject::class, [
            'Title' => 'Child',
            'ParentID' => $parent->ID,
        ]);

        $body = $this->decode(
            $this->apiGet("records/ApiTestOwnedParent/{$parent->ID}/parity?include=", $this->adminToken)
        );

        $this->assertNull($body['error']);
        $this->assertCount(1, $body['data']['owned']);
    }

    /**
     * `compareFields()` filters the configured default field list to
     * whichever fields the class actually declares — `ApiTestVersionedObject`
     * (Title/Status only) is missing four of the six defaults
     * (ParentID/ShowInMenus/URLSegment/Sort), so this is the one fixture in
     * the suite that can actually exercise the skip-if-absent branch rather
     * than every default field trivially matching every root class used
     * elsewhere.
     */
    public function testFieldComparisonSkipsFieldsTheClassDoesNotDeclare(): void
    {
        $record = $this->createAndPublish(ApiTestVersionedObject::class, ['Title' => 'Sparse fields']);

        $body = $this->decode($this->apiGet("records/ApiTestVersioned/{$record->ID}/parity", $this->adminToken));

        $this->assertArrayHasKey('Title', $body['data']['fields']);
        $this->assertArrayNotHasKey('ParentID', $body['data']['fields']);
        $this->assertArrayNotHasKey('ShowInMenus', $body['data']['fields']);
        $this->assertArrayNotHasKey('URLSegment', $body['data']['fields']);
        $this->assertArrayNotHasKey('Sort', $body['data']['fields']);
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
