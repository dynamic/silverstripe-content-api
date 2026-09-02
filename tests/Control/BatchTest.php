<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Batch\BatchProcessor;
use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestDeprecatingObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Tests\Stub\ApiTestMultiRelationalPolyObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestPolyObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestTag;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use Dynamic\ContentApi\Tests\Stub\ForceUnverifiedRollbackBatchProcessor;
use DNADesign\Elemental\Models\ElementalArea;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;

class BatchTest extends ContentApiTestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->mintTokenFor('adminUser');

        // See tearDown() — reset on both sides of the test so a prior test's
        // leftover cache entry (this class caches per page class name
        // regardless of which test file touched it) can never leak in, not
        // just so this test doesn't leak one out.
        static::resetElementalTypesCache();
    }

    protected function tearDown(): void
    {
        // ElementalAreasExtension::getElementalTypes() caches per page class
        // name in a static that Config::modify()'s automatic rollback
        // doesn't touch.
        static::resetElementalTypesCache();

        parent::tearDown();
    }

    public function testMixedOperations(): void
    {
        $existing = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTest',
                    'externalId' => 'b-new',
                    'fields' => ['Title' => 'Batch New'],
                ],
                ['op' => 'upsert', 'class' => 'ApiTest', 'externalId' => 'alpha', 'fields' => ['Rank' => 50]],
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $existing->ID,
                    'fields' => ['Title' => 'Alpha v2'],
                ],
                ['op' => 'delete', 'class' => 'ApiTest', 'externalId' => 'b-new'],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);

        $statuses = array_column($body['data']['results'], 'status');
        $this->assertSame(['created', 'updated', 'updated', 'deleted'], $statuses);
        $this->assertSame(
            ['created' => 1, 'updated' => 2, 'deleted' => 1, 'skipped' => 0, 'errors' => 0],
            $body['data']['summary']
        );

        $this->assertSame('Alpha v2', ApiTestObject::get()->byID($existing->ID)->Title);
        $this->assertSame(50, (int) ApiTestObject::get()->byID($existing->ID)->Rank);
        $this->assertNull(ApiTestObject::get()->filter('FixtureIdentifier', 'b-new')->first());
    }

    public function testErrorIsolationWithoutAtomic(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'create', 'class' => 'ApiTest', 'externalId' => 'iso-1', 'fields' => ['Title' => 'First']],
                ['op' => 'create', 'class' => 'ApiTest', 'externalId' => 'iso-2', 'fields' => ['Bogus' => 1]],
                ['op' => 'create', 'class' => 'ApiTest', 'externalId' => 'iso-3', 'fields' => ['Title' => 'Third']],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error'], 'transport succeeds even with op errors');

        $results = $body['data']['results'];
        $this->assertSame(['created', 'error', 'created'], array_column($results, 'status'));
        $this->assertSame('UNKNOWN_FIELD', $results[1]['error']['code']);
        $this->assertSame(1, $body['data']['summary']['errors']);

        $this->assertNotNull(ApiTestObject::get()->filter('FixtureIdentifier', 'iso-3')->first());
    }

    /**
     * Regression: RecordWriter::write() used to persist the record before
     * applying relations, so a relation that fails to resolve (e.g. a
     * nonexistent related id) left a half-written draft record behind even
     * though the op reports "error" — corrupting the retry-failed-indices
     * contract a non-atomic batch caller relies on. The record write and its
     * relation writes must land or fail together.
     */
    public function testCreateOpLeavesNoRecordWhenARelationFailsToResolve(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', ['Children']);

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTest',
                    'externalId' => 'relation-fail',
                    'fields' => ['Title' => 'Should Not Persist'],
                    'relations' => [
                        'Children' => ['mode' => 'set', 'items' => [999999999]],
                    ],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error'], 'transport succeeds even with op errors');
        $this->assertSame('NOT_FOUND', $body['data']['results'][0]['error']['code']);
        $this->assertNull(
            ApiTestObject::get()->filter('FixtureIdentifier', 'relation-fail')->first(),
            'a relation failure must roll back the record write too, not just report an error'
        );
    }

    public function testAtomicBatchRollsBack(): void
    {
        $response = $this->apiPost('batch', [
            'atomic' => true,
            'operations' => [
                ['op' => 'create', 'class' => 'ApiTest', 'externalId' => 'atomic-1', 'fields' => ['Title' => 'First']],
                ['op' => 'create', 'class' => 'ApiTest', 'externalId' => 'atomic-2', 'fields' => ['Bogus' => 1]],
            ],
        ], $this->adminToken);

        $body = $this->assertErrorCode($response, 'VALIDATION_FAILED', 422);

        $this->assertTrue($body['error']['details'][0]['rolledBack']);
        $this->assertNull(
            ApiTestObject::get()->filter('FixtureIdentifier', 'atomic-1')->first(),
            'successful op before the failure must be rolled back'
        );
    }

    /**
     * Companion coverage for #70, NOT a regression test for the output-
     * buffering fix itself — SilverStripe's FunctionalTest invokes the
     * controller in-process and inspects the returned HTTPResponse object
     * directly, bypassing the real PHP output stream a live web request
     * concatenates stray echo output onto, so this harness can't observe
     * that specific corruption (see ContentApiControllerOutputBufferingTest
     * for the test that actually exercises the buffering, and does fail
     * without the fix). What this test does confirm: a deprecation notice
     * from application code (dynamic/foxystripe's real
     * ProductPage::onBeforeWrite() calling trim() on a nullable field is
     * the real-world shape) doesn't otherwise disrupt normal batch write
     * semantics — the op still reports 'created' and the record is really
     * there.
     */
    public function testDeprecationNoticeDuringWriteDoesNotCorruptTheResponse(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestDeprecating',
                    'externalId' => 'deprecating-1',
                    'fields' => ['Title' => 'Triggers a deprecation on write'],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame(['created'], array_column($body['data']['results'], 'status'));
        $this->assertNotNull(ApiTestDeprecatingObject::get()->filter('FixtureIdentifier', 'deprecating-1')->first());
    }

    /**
     * Same scope note as the test above (companion coverage, not a
     * buffering regression test — see ContentApiControllerOutputBufferingTest
     * for that). Through the atomic path with a genuine op failure
     * alongside the deprecation: confirms a deprecating op doesn't prevent
     * verifyRollback() from doing its job — the deprecating op's own row
     * is confirmed truly gone afterward (not just claimed gone), matching
     * the real committed-atomic-batch-still-rolls-back case.
     */
    public function testDeprecationNoticeInsideAnAtomicBatchThatGenuinelyFailsStillReportsAccurately(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'atomic' => true,
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestDeprecating',
                    'externalId' => 'deprecating-atomic-1',
                    'fields' => ['Title' => 'Triggers a deprecation on write'],
                ],
                [
                    'op' => 'create',
                    'class' => 'ApiTest',
                    'externalId' => 'deprecating-atomic-2',
                    'fields' => ['Bogus' => 1],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertTrue($body['error']['details'][0]['rolledBack']);
        $this->assertNull(
            ApiTestDeprecatingObject::get()->filter('FixtureIdentifier', 'deprecating-atomic-1')->first(),
            'the deprecating op must be genuinely rolled back, not just reported as rolled back'
        );
    }

    /**
     * End-to-end coverage of the ROLLBACK_UNVERIFIED response path itself
     * (BatchProcessorRollbackVerificationTest covers verifyRollback() in
     * isolation, but nothing else exercises process()'s catch block taking
     * the $verified === false branch). The framework's real rollback
     * mechanism works correctly under normal conditions — confirmed by
     * testAtomicBatchRollsBack above — so nothing in a real request can
     * force a genuine failed-verification outcome; ForceUnverifiedRollback
     * BatchProcessor swaps in to force it instead.
     */
    public function testUnverifiedRollbackReportsDistinctlyFromAVerifiedOne(): void
    {
        Injector::inst()->registerService(
            ForceUnverifiedRollbackBatchProcessor::create(),
            BatchProcessor::class
        );

        $body = $this->decode($this->apiPost('batch', [
            'atomic' => true,
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTest',
                    'externalId' => 'unverified-1',
                    'fields' => ['Title' => 'First'],
                ],
                ['op' => 'create', 'class' => 'ApiTest', 'externalId' => 'unverified-2', 'fields' => ['Bogus' => 1]],
            ],
        ], $this->adminToken));

        $this->assertSame('ROLLBACK_UNVERIFIED', $body['error']['code']);
        $this->assertSame(500, $body['error']['status']);
        $this->assertFalse($body['error']['details'][0]['rolledBack']);
        $this->assertStringContainsString('could not be verified', $body['error']['message']);
    }

    public function testDefaultPublishApplies(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'defaultPublish' => 'single',
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestVersioned',
                    'externalId' => 'b-live',
                    'fields' => ['Title' => 'Batch Live'],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertTrue($body['data']['results'][0]['stage']['live']);

        $record = ApiTestVersionedObject::get()->filter('FixtureIdentifier', 'b-live')->first();
        $this->assertTrue($record->isPublished());
    }

    /**
     * #119/#168: `owns` is reachable as a per-op `publish` value the same
     * way `single`/`recursive`/`subtree` already are — no allowlist of its
     * own on the batch surface, inherited from `PublishOrchestrator::MODES`
     * via `RecordWriter::write()`'s `assertValidMode()` call. A class with
     * no `$owns` declared behaves like `single` (see
     * `PublishOrchestratorTest::testOwnsModeOnAClassWithNoOwnedRelationsJustPublishesTheRecordItself`),
     * so this only needs to confirm the mode string itself isn't rejected
     * and the record does publish.
     */
    public function testDefaultPublishAcceptsOwnsMode(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'defaultPublish' => 'owns',
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestVersioned',
                    'externalId' => 'b-owns-live',
                    'fields' => ['Title' => 'Batch Owns Live'],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertTrue($body['data']['results'][0]['stage']['live']);

        $record = ApiTestVersionedObject::get()->filter('FixtureIdentifier', 'b-owns-live')->first();
        $this->assertTrue($record->isPublished());
    }

    /**
     * #114: `defaultPublish` (or a per-op "publish" key) reaches
     * RecordWriter::write() the same as any single-record write — a class
     * granting `create` but not `action` must refuse a batch op that
     * inherits a non-"none" publish mode, not just single-record writes.
     */
    public function testDefaultPublishRequiresTheActionVerb(): void
    {
        Config::modify()->set(ApiTestVersionedObject::class, 'api_access', 'read,create,update');

        $body = $this->decode($this->apiPost('batch', [
            'defaultPublish' => 'single',
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestVersioned',
                    'externalId' => 'b-forbidden',
                    'fields' => ['Title' => 'Batch Forbidden'],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error'], 'the batch call itself must succeed — only the op fails');
        $this->assertSame('error', $body['data']['results'][0]['status']);
        $this->assertSame('FORBIDDEN_CLASS', $body['data']['results'][0]['error']['code']);
    }

    /**
     * Write-pipeline coverage absorbed from the removed HTTP CRUD endpoints —
     * batch ops run the same RecordWriter/WriteApplicator path.
     */
    public function testUpsertIsSparse(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'upsert', 'class' => 'ApiTest', 'externalId' => 'alpha', 'fields' => ['Rank' => 42]],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $this->assertSame(42, (int) $record->Rank);
        $this->assertSame('Alpha', $record->Title, 'unsent fields untouched');
    }

    public function testCreateWithDuplicateExternalIdConflicts(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'create', 'class' => 'ApiTest', 'externalId' => 'alpha', 'fields' => ['Title' => 'Dupe']],
            ],
        ], $this->adminToken));

        $this->assertSame('ALREADY_EXISTS', $body['data']['results'][0]['error']['code']);
    }

    public function testProtectedFieldRejected(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'upsert', 'class' => 'ApiTest', 'externalId' => 'alpha', 'fields' => ['ID' => 999]],
            ],
        ], $this->adminToken));

        $this->assertSame('READONLY_FIELD', $body['data']['results'][0]['error']['code']);
    }

    /**
     * A write into an Enum column used to accept any string — DBEnum never
     * validates on setValue(), and MySQL itself doesn't reject an
     * out-of-list ENUM value, it silently coerces it to the empty string.
     * The wrong-case value (a very natural mistake — the schema's real
     * value is lowercase "published") got a 200 and a permanently wrong
     * field, with no signal anything was wrong (confirmed live — 46
     * elements, essentials project). Proven here against actual DB state,
     * not just the error code: the field must be unchanged after the
     * rejected write.
     */
    public function testEnumFieldRejectsAnOutOfListValue(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $priorStatus = $record->Status;

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'fields' => ['Status' => 'Published'],
                ],
            ],
        ], $this->adminToken));

        $result = $body['data']['results'][0];
        $this->assertSame('INVALID_VALUE', $result['error']['code']);
        $detailMessage = $result['error']['details'][0]['message'];
        $this->assertStringContainsString('draft', $detailMessage);
        $this->assertStringContainsString('published', $detailMessage);

        $record = ApiTestObject::get()->byID($record->ID);
        $this->assertSame($priorStatus, $record->Status);
    }

    public function testEnumFieldAcceptsAValueFromItsDeclaredList(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'fields' => ['Status' => 'published'],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('updated', $body['data']['results'][0]['status']);
        $this->assertSame('published', ApiTestObject::get()->byID($record->ID)->Status);
    }

    /**
     * DBMultiEnum extends DBEnum and stores a comma-joined list of
     * independently-valid values, not one — validating the whole joined
     * string against enumValues() directly would reject every legitimate
     * multi-value write. A real regression caught before merge, not a
     * live incident.
     */
    public function testMultiEnumFieldAcceptsACommaJoinedListOfValidValues(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'fields' => ['Colors' => 'red,blue'],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('updated', $body['data']['results'][0]['status']);
        $this->assertSame('red,blue', ApiTestObject::get()->byID($record->ID)->Colors);
    }

    public function testMultiEnumFieldRejectsAValueOutsideItsList(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'fields' => ['Colors' => 'red,purple'],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('INVALID_VALUE', $body['data']['results'][0]['error']['code']);
    }

    public function testAllowlistPolicy(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_write_policy', 'allowlist');
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title']);

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'upsert', 'class' => 'ApiTest', 'externalId' => 'alpha', 'fields' => ['Rank' => 5]],
                ['op' => 'upsert', 'class' => 'ApiTest', 'externalId' => 'alpha', 'fields' => ['Title' => 'Renamed']],
            ],
        ], $this->adminToken));

        $results = $body['data']['results'];
        $this->assertSame('READONLY_FIELD', $results[0]['error']['code']);
        $this->assertSame('updated', $results[1]['status']);
        $this->assertSame('Renamed', $this->objFromFixture(ApiTestObject::class, 'one')->Title);
    }

    public function testBareAllowlistIsEnforcedOnTrustedPath(): void
    {
        // Regression for #15: a class in the default `guarded` policy with a
        // non-empty api_writable_fields (no explicit api_write_policy) must
        // still be gated on the batch/compositions surface — a bare allowlist
        // is not a silent no-op here just because it isn't the colymba guard.
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title']);

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'upsert', 'class' => 'ApiTest', 'externalId' => 'alpha', 'fields' => ['Rank' => 5]],
                [
                    'op' => 'upsert',
                    'class' => 'ApiTest',
                    'externalId' => 'alpha',
                    'fields' => ['Title' => 'Renamed bare'],
                ],
            ],
        ], $this->adminToken));

        $results = $body['data']['results'];
        $this->assertSame('READONLY_FIELD', $results[0]['error']['code'], 'Rank rejected by the bare allowlist');
        $this->assertSame('updated', $results[1]['status']);
        $this->assertSame('Renamed bare', $this->objFromFixture(ApiTestObject::class, 'one')->Title);
    }

    public function testBatchCannotSmuggleFieldsThroughInternalFieldsKey(): void
    {
        // Module #27 hardening: WriteApplicator's trusted `internalFields`
        // channel bypasses api_writable_fields (see CompositionTest for the
        // legitimate use — a composition element's server-derived ParentID).
        // BatchProcessor forwards the raw client operation object straight
        // into RecordWriter, so a client naming a top-level "internalFields"
        // key in its JSON must have zero effect — RecordWriter takes it as a
        // dedicated method parameter that only in-process callers populate,
        // never read from the request payload itself.
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title']);

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'upsert',
                    'class' => 'ApiTest',
                    'externalId' => 'alpha',
                    'fields' => ['Title' => 'Still gated'],
                    'internalFields' => ['Rank' => 999],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('updated', $body['data']['results'][0]['status']);
        $this->assertSame(
            1,
            (int) $this->objFromFixture(ApiTestObject::class, 'one')->Rank,
            'a client-submitted "internalFields" key must never reach the trusted channel'
        );
    }

    public function testBatchIsEnvironmentGated(): void
    {
        // Batch shares checkPopulationAllowed() with compositions
        // (CompositionTest::testCompositionIsEnvironmentGated covers that
        // surface) but had no direct assertion that the gate is actually
        // wired into BatchHandler, only that EnvironmentGate's own parsing
        // is correct in isolation (EnvironmentGateTest).
        Config::modify()->set(EnvironmentGate::class, 'population_enabled_environments', []);

        $response = $this->apiPost('batch', [
            'operations' => [
                ['op' => 'create', 'class' => 'ApiTest', 'externalId' => 'gated-new', 'fields' => ['Title' => 'x']],
            ],
        ], $this->adminToken);

        $this->assertErrorCode($response, 'ENV_FORBIDDEN', 403);
    }

    public function testValidationFailureMapsPerField(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'upsert', 'class' => 'ApiTest', 'externalId' => 'alpha', 'fields' => ['Title' => 'Invalid']],
            ],
        ], $this->adminToken));

        $error = $body['data']['results'][0]['error'];
        $this->assertSame('VALIDATION_FAILED', $error['code']);
        $this->assertSame('Title', $error['details'][0]['field']);
    }

    public function testHasOneWriteVariants(): void
    {
        $one = $this->objFromFixture(ApiTestObject::class, 'one');
        $two = $this->objFromFixture(ApiTestObject::class, 'two');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                // integer ID form
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $one->ID,
                    'fields' => ['Buddy' => (int) $two->ID],
                ],
                // externalId object form (idempotent — same target)
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $one->ID,
                    'fields' => ['Buddy' => ['externalId' => 'beta']],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame((int) $two->ID, (int) ApiTestObject::get()->byID($one->ID)->BuddyID);

        // null clears
        $this->apiPost('batch', [
            'operations' => [
                ['op' => 'update', 'class' => 'ApiTest', 'id' => (int) $one->ID, 'fields' => ['Buddy' => null]],
            ],
        ], $this->adminToken);
        $this->assertSame(0, (int) ApiTestObject::get()->byID($one->ID)->BuddyID);
    }

    public function testPolymorphicHasOneCreateWithClassHint(): void
    {
        // Regression for #17: creating a record with a polymorphic has_one
        // (ApiTestPolyObject.Owner => DataObject::class) used to 500 —
        // PermissionPolicy::buildCreateContext() called
        // DataObject::get_by_id(DataObject::class, ...) while hydrating the
        // canCreate() context, before the field-apply logic ever ran.
        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'upsert',
                    'class' => 'ApiTestPoly',
                    'externalId' => 'poly-new',
                    'fields' => [
                        'Title' => 'Poly record',
                        'Owner' => ['class' => 'ApiTest', 'id' => (int) $owner->ID],
                    ],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error'], 'create must not 500');
        $this->assertSame('created', $body['data']['results'][0]['status']);

        $created = ApiTestPolyObject::get()->filter('FixtureIdentifier', 'poly-new')->first();
        $this->assertNotNull($created);
        $this->assertSame((int) $owner->ID, (int) $created->OwnerID, 'FK column set');
        $this->assertSame(ApiTestObject::class, $created->OwnerClass, 'companion Class column set');

        // GET round-trips the class alongside the id rather than a bare int.
        $read = $this->decode($this->apiGet("records/ApiTestPoly/{$created->ID}", $this->adminToken));
        $this->assertSame(
            ['id' => (int) $created->ID, 'class' => 'ApiTest'],
            $read['data']['relations']['Owner']
        );
    }

    /**
     * Regression coverage for #34: a has_one declared via the array/
     * `multirelational` form (`['class' => DataObject::class,
     * 'multirelational' => true]`, required when the same polymorphic
     * relation is shared by more than one reciprocal has_many) must behave
     * identically to the plain-string polymorphic form — every module call
     * site reads the spec through `hasOne()`, which the framework already
     * normalizes to the bare class string before any module code sees it,
     * so this mirrors testPolymorphicHasOneCreateWithClassHint() above
     * verbatim against ApiTestMultiRelationalPolyObject.
     */
    public function testMultiRelationalPolymorphicHasOneCreateWithClassHint(): void
    {
        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'upsert',
                    'class' => 'ApiTestMultiRelationalPoly',
                    'externalId' => 'multi-poly-new',
                    'fields' => [
                        'Title' => 'Multirelational poly record',
                        'Owner' => ['class' => 'ApiTest', 'id' => (int) $owner->ID],
                    ],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error'], 'create must not 500 or TypeError on resolveRelation()');
        $this->assertSame('created', $body['data']['results'][0]['status']);

        // Regression for #34 code review: a successful write of a
        // multirelational has_one must not report as unqualified success —
        // the reciprocal has_many side won't see this record until
        // {Name}Relation is set some other way, and this API has no way to
        // set that column, so the caller needs a way to discover that
        // limitation rather than the response looking fully functional.
        $this->assertNotEmpty(
            $body['data']['results'][0]['warnings'] ?? [],
            'expected a warning about the reciprocal has_many gap'
        );
        $this->assertSame('FEATURE_UNAVAILABLE', $body['data']['results'][0]['warnings'][0]['code']);

        $created = ApiTestMultiRelationalPolyObject::get()->filter('FixtureIdentifier', 'multi-poly-new')->first();
        $this->assertNotNull($created);
        $this->assertSame((int) $owner->ID, (int) $created->OwnerID, 'FK column set');
        $this->assertSame(ApiTestObject::class, $created->OwnerClass, 'companion Class column set');

        $read = $this->decode($this->apiGet("records/ApiTestMultiRelationalPoly/{$created->ID}", $this->adminToken));
        $this->assertSame(
            ['id' => (int) $owner->ID, 'class' => 'ApiTest'],
            $read['data']['relations']['Owner']
        );
    }

    /**
     * Regression for #34 code review: the plain-string polymorphic form
     * (not multirelational) must NOT get this warning — only the
     * multirelational form has an unset reciprocal has_many column at all.
     */
    public function testPlainPolymorphicHasOneCreateDoesNotGetTheMultiRelationalWarning(): void
    {
        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'upsert',
                    'class' => 'ApiTestPoly',
                    'externalId' => 'plain-poly-no-warning',
                    'fields' => [
                        'Title' => 'Plain poly record',
                        'Owner' => ['class' => 'ApiTest', 'id' => (int) $owner->ID],
                    ],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertArrayNotHasKey('warnings', $body['data']['results'][0]);
    }

    /**
     * Regression for #34 code review: a multirelational polymorphic
     * has_one gets a third physical column, {Name}Relation
     * (DBPolymorphicRelationAwareForeignKey's own composite field,
     * SilverStripe's internal disambiguator for which reciprocal has_many
     * a record belongs to), that only exists for this form — unlike
     * {Name}Class, nothing in this module ever legitimately resolves a
     * value for it, so it must never be settable as a bare payload key,
     * even though it's a real column isFieldWritable() would otherwise
     * allow under the default 'guarded' policy.
     */
    public function testMultiRelationalPolymorphicRelationColumnIsNeverDirectlyWritable(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'upsert',
                    'class' => 'ApiTestMultiRelationalPoly',
                    'externalId' => 'multi-poly-relation-column',
                    'fields' => [
                        'Title' => 'Attempted Relation write',
                        'OwnerRelation' => 'AnythingAtAll',
                    ],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('READONLY_FIELD', $body['data']['results'][0]['error']['code']);
        $this->assertNull(
            ApiTestMultiRelationalPolyObject::get()->filter('FixtureIdentifier', 'multi-poly-relation-column')->first(),
            'the whole payload must reject, nothing partially applied'
        );
    }

    /**
     * Regression for #34 code review: the flat `fields` map must not leak
     * a polymorphic has_one's companion {Name}Class/{Name}Relation columns
     * — SchemaService already excludes them from its own field listing and
     * WriteApplicator/WriteGuardExtension make them entirely unwritable, so
     * RecordSerializer must be consistent rather than exposing the raw
     * internal FQCN and relation-disambiguator string redundantly
     * alongside the proper `relations.Owner` shape.
     */
    public function testCompanionColumnsAreExcludedFromTheFlatFieldsMap(): void
    {
        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'upsert',
                    'class' => 'ApiTestMultiRelationalPoly',
                    'externalId' => 'multi-poly-fields-leak',
                    'fields' => [
                        'Title' => 'No leaked companion columns',
                        'Owner' => ['class' => 'ApiTest', 'id' => (int) $owner->ID],
                    ],
                ],
            ],
        ], $this->adminToken));

        $created = ApiTestMultiRelationalPolyObject::get()
            ->filter('FixtureIdentifier', 'multi-poly-fields-leak')
            ->first();
        $read = $this->decode($this->apiGet("records/ApiTestMultiRelationalPoly/{$created->ID}", $this->adminToken));

        $this->assertArrayNotHasKey('OwnerID', $read['data']['fields']);
        $this->assertArrayNotHasKey('OwnerClass', $read['data']['fields']);
        $this->assertArrayNotHasKey('OwnerRelation', $read['data']['fields']);
        $this->assertSame(
            ['id' => (int) $owner->ID, 'class' => 'ApiTest'],
            $read['data']['relations']['Owner']
        );
    }

    public function testPolymorphicHasOneWithoutClassHintIsRejectedNotCrashed(): void
    {
        // A bare {"id": n} (or plain int) on a polymorphic has_one is
        // ambiguous — the fix rejects it with a machine-readable error
        // instead of reaching DataObject::get_by_id(DataObject::class, ...)
        // and 500ing.
        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestPoly',
                    'externalId' => 'poly-no-hint',
                    'fields' => [
                        'Title' => 'Poly record',
                        'Owner' => ['id' => (int) $owner->ID],
                    ],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error'], 'batch envelope itself must not 500');
        $this->assertSame('PAYLOAD_INVALID', $body['data']['results'][0]['error']['code']);
    }

    public function testPolymorphicHasOneViaBareFkColumnIsAlsoRejected(): void
    {
        // Regression: a payload naming the relation by its raw FK column
        // ("OwnerID") rather than the relation name ("Owner") must not
        // bypass the polymorphic class-hint requirement — both keys route
        // through the same resolveRelation() check.
        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestPoly',
                    'externalId' => 'poly-fk-column',
                    'fields' => [
                        'Title' => 'Poly record',
                        'OwnerID' => (int) $owner->ID,
                    ],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error'], 'batch envelope itself must not 500');
        $this->assertSame('PAYLOAD_INVALID', $body['data']['results'][0]['error']['code']);
        $this->assertNull(ApiTestPolyObject::get()->filter('FixtureIdentifier', 'poly-fk-column')->first());
    }

    public function testPolymorphicClassColumnIsIndependentlyGated(): void
    {
        // #25: the companion Class column must be checked on its own, not
        // just ride along with the FK's writability check — protecting
        // OwnerClass specifically must reject the write even though Owner
        // (and so OwnerID) is allowed.
        Config::modify()->set(ApiTestPolyObject::class, 'api_writable_fields', ['Title', 'Owner']);
        Config::modify()->set(ApiTestPolyObject::class, 'api_protected_fields', ['OwnerClass']);

        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestPoly',
                    'externalId' => 'poly-protected-class',
                    'fields' => [
                        'Title' => 'Poly record',
                        'Owner' => ['class' => 'ApiTest', 'id' => (int) $owner->ID],
                    ],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error'], 'batch envelope itself must not 500');
        $this->assertSame('READONLY_FIELD', $body['data']['results'][0]['error']['code']);
        $this->assertNull(ApiTestPolyObject::get()->filter('FixtureIdentifier', 'poly-protected-class')->first());
    }

    public function testDirectClassColumnPayloadKeyIsAlwaysRejected(): void
    {
        // Even with OwnerClass explicitly allowlisted, a raw payload key
        // naming the companion column directly must be rejected — it can
        // only ever be set as a side effect of resolving "Owner" itself,
        // never as an arbitrary client-supplied string with no
        // ClassRegistry validation at all.
        Config::modify()->set(ApiTestPolyObject::class, 'api_writable_fields', ['Title', 'OwnerClass']);

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestPoly',
                    'externalId' => 'poly-direct-class',
                    'fields' => [
                        'Title' => 'Poly record',
                        'OwnerClass' => ApiTestObject::class,
                    ],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error'], 'batch envelope itself must not 500');
        $this->assertSame('READONLY_FIELD', $body['data']['results'][0]['error']['code']);
        $this->assertNull(ApiTestPolyObject::get()->filter('FixtureIdentifier', 'poly-direct-class')->first());
    }

    public function testRelationModesAndExtraFields(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', ['Children', 'Tags']);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $childOne = $this->objFromFixture(ApiTestChildObject::class, 'childOne');
        $childTwo = $this->objFromFixture(ApiTestChildObject::class, 'childTwo');
        $tag = $this->objFromFixture(ApiTestTag::class, 'tagOne');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'relations' => [
                        'Children' => ['mode' => 'set', 'items' => [(int) $childOne->ID, (int) $childTwo->ID]],
                        'Tags' => [
                            'mode' => 'add',
                            'items' => [['id' => (int) $tag->ID, 'extraFields' => ['SortOrder' => 3]]],
                        ],
                    ],
                ],
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'relations' => [
                        'Children' => ['mode' => 'remove', 'items' => [(int) $childOne->ID]],
                    ],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame(1, $record->Children()->count());
        $this->assertSame(3, (int) $record->Tags()->first()->SortOrder);
    }

    /**
     * A many_many `through` relation accepts the same {"id","extraFields"}
     * write shape as the classic many_many_extraFields case — confirms the
     * write path needed no code change: WriteApplicator already resolves
     * the through target class and ManyManyThroughList::add() writes
     * extraFields onto the join record.
     */
    public function testThroughRelationExtraFieldsRoundTripOnWrite(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', ['ThroughTags']);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $tag = $this->objFromFixture(ApiTestTag::class, 'tagOne');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'relations' => [
                        'ThroughTags' => [
                            'mode' => 'add',
                            'items' => [
                                ['id' => (int) $tag->ID, 'extraFields' => ['SortOrder' => 4, 'IsCurrent' => true]],
                            ],
                        ],
                    ],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);

        $join = $record->ThroughTags()->first()->getJoin();
        $this->assertSame(4, (int) $join->SortOrder);
        $this->assertTrue((bool) $join->IsCurrent);
    }

    public function testUnlistedAndUnknownRelations(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', []);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'relations' => ['Children' => ['mode' => 'set', 'items' => []]],
                ],
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'relations' => ['Nope' => ['mode' => 'set', 'items' => []]],
                ],
            ],
        ], $this->adminToken));

        $results = $body['data']['results'];
        $this->assertSame('READONLY_FIELD', $results[0]['error']['code']);
        $this->assertSame('UNKNOWN_RELATION', $results[1]['error']['code']);
    }

    /**
     * #191: a has_one named under `relations` (a natural mistake — a
     * has_one FK belongs under `fields`) used to be silently dropped —
     * `applyRelations()` never runs until AFTER the record's own write, so
     * on a class whose own validation would otherwise pass, the write
     * "succeeded" with the has_one left completely untouched and no error
     * anywhere. Must now be rejected up front, before any write happens —
     * proven here by asserting the record's FK is unchanged, not just by
     * the error code.
     */
    public function testHasOneNamedUnderRelationsIsRejectedNotSilentlyDropped(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $buddy = $this->objFromFixture(ApiTestObject::class, 'two');
        $priorBuddyID = (int) $record->BuddyID;

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'relations' => ['Buddy' => (int) $buddy->ID],
                ],
            ],
        ], $this->adminToken));

        $result = $body['data']['results'][0];
        $this->assertSame('PAYLOAD_INVALID', $result['error']['code']);
        $this->assertStringContainsString('has_one', $result['error']['message']);
        $this->assertStringContainsString('"fields"', $result['error']['message']);

        $record = ApiTestObject::get()->byID($record->ID);
        $this->assertSame(
            $priorBuddyID,
            (int) $record->BuddyID,
            'the has_one FK must be left untouched, not silently written or silently ignored'
        );
    }

    /**
     * validateRelationSpec() special-cases the FK-suffixed form of a
     * has_one name ("BuddyID") the same way as the bare relation name
     * ("Buddy") — a separate branch in the same condition, so it needs its
     * own test rather than assuming the bare-name test above covers it.
     */
    public function testHasOneFkSuffixedKeyUnderRelationsIsAlsoRejected(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $buddy = $this->objFromFixture(ApiTestObject::class, 'two');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'relations' => ['BuddyID' => (int) $buddy->ID],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('PAYLOAD_INVALID', $body['data']['results'][0]['error']['code']);
    }

    /**
     * assertRelationsValid() runs before applyFields() in
     * RecordWriter::write() specifically so a has_one-under-relations
     * mistake is rejected before anything is applied at all — proven here
     * with a co-occurring valid field change in the same operation, not
     * relations alone, so a future reordering of the two calls (they
     * aren't adjacent in write()) that let the field write through first
     * would be caught by this test.
     */
    public function testHasOneUnderRelationsRejectionAlsoBlocksACoOccurringFieldWrite(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $buddy = $this->objFromFixture(ApiTestObject::class, 'two');
        $priorTitle = $record->Title;

        $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'fields' => ['Title' => 'Should never land'],
                    'relations' => ['Buddy' => (int) $buddy->ID],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame(
            $priorTitle,
            ApiTestObject::get()->byID($record->ID)->Title,
            'the co-occurring field write must not apply when relations validation rejects the operation'
        );
    }

    public function testCreateVerbDenied(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', 'read,update');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'create', 'class' => 'ApiTest', 'externalId' => 'nope', 'fields' => ['Title' => 'X']],
            ],
        ], $this->adminToken));

        $this->assertSame('FORBIDDEN_CLASS', $body['data']['results'][0]['error']['code']);
    }

    public function testCanCreateDenied(): void
    {
        // populateUser holds POPULATE (batch gate) but not ADMIN —
        // ApiTestVersionedObject's default canCreate() requires admin.
        $token = $this->mintTokenFor('populateUser');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'create', 'class' => 'ApiTestVersioned', 'fields' => ['Title' => 'Nope']],
            ],
        ], $token));

        $this->assertSame('FORBIDDEN_RECORD', $body['data']['results'][0]['error']['code']);
    }

    public function testBatchRequiresPopulatePermission(): void
    {
        $response = $this->apiPost('batch', [
            'operations' => [
                ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Title' => 'Nope']],
            ],
        ], $this->mintTokenFor('apiUser'));

        $this->assertErrorCode($response, 'FORBIDDEN', 403);
    }

    public function testEmptyBatchIsRejected(): void
    {
        $response = $this->apiPost('batch', ['operations' => []], $this->adminToken);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    /**
     * Regression for #4: the "must be one of" message must never list a mode
     * the target class can't actually use — a versioned record only accepts
     * archive/unpublish, so "hard" must not appear even in the error text.
     */
    public function testDeleteInvalidModeMessageOmitsHardForVersionedClass(): void
    {
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'delete', 'class' => 'ApiTestVersioned', 'id' => (int) $record->ID, 'mode' => 'bogus'],
            ],
        ], $this->adminToken));

        $error = $body['data']['results'][0]['error'];
        $this->assertSame('PAYLOAD_INVALID', $error['code']);
        $this->assertSame(
            'Delete mode "bogus" must be one of: archive, unpublish.',
            $error['message']
        );
    }

    public function testDeleteHardRejectedForVersionedClass(): void
    {
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'delete', 'class' => 'ApiTestVersioned', 'id' => (int) $record->ID, 'mode' => 'hard'],
            ],
        ], $this->adminToken));

        $error = $body['data']['results'][0]['error'];
        $this->assertSame('PAYLOAD_INVALID', $error['code']);
        $this->assertNotNull(
            ApiTestVersionedObject::get()->byID($record->ID),
            'a rejected hard-delete must leave the record untouched'
        );
    }

    public function testDeleteArchiveAcceptedForVersionedClass(): void
    {
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'delete', 'class' => 'ApiTestVersioned', 'id' => (int) $record->ID, 'mode' => 'archive'],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame('deleted', $body['data']['results'][0]['status']);
    }

    /**
     * #75: an atomic batch's archive-mode delete result is now verified the
     * same way a created result always was — genuinely restored to DRAFT
     * after a real rollback, not just claimed. Follows
     * testAtomicBatchRollsBack()'s pattern above.
     */
    public function testAtomicBatchWithAnArchiveDeleteRollsBackAndVerifiesTheRestoredRecord(): void
    {
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $response = $this->apiPost('batch', [
            'atomic' => true,
            'operations' => [
                ['op' => 'delete', 'class' => 'ApiTestVersioned', 'id' => (int) $record->ID, 'mode' => 'archive'],
                ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Bogus' => 1]],
            ],
        ], $this->adminToken);

        $body = $this->assertErrorCode($response, 'VALIDATION_FAILED', 422);

        $this->assertTrue($body['error']['details'][0]['rolledBack']);
        $this->assertNotNull(
            ApiTestVersionedObject::get()->byID($record->ID),
            'the archive-mode delete must have been genuinely rolled back, not just reported as rolled back'
        );
    }

    /**
     * #127, end to end: an atomic batch containing an `update` op that
     * genuinely rolls back must report `rolledBack: true`, with the field
     * actually back at its pre-write value — not just a claim.
     */
    public function testAtomicBatchWithAnUpdateRollsBackAndVerifiesTheRevertedField(): void
    {
        $record = ApiTestObject::create(['Title' => 'Original title']);
        $record->write();

        $response = $this->apiPost('batch', [
            'atomic' => true,
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'fields' => ['Title' => 'Would-be new title'],
                ],
                ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Bogus' => 1]],
            ],
        ], $this->adminToken);

        $body = $this->assertErrorCode($response, 'VALIDATION_FAILED', 422);

        $this->assertTrue($body['error']['details'][0]['rolledBack']);
        $this->assertSame(
            'Original title',
            ApiTestObject::get()->byID($record->ID)->Title,
            'the update must have been genuinely rolled back, not just reported as rolled back'
        );
    }

    /**
     * #127's documented residual gap, end to end: an `update` op whose
     * payload declares only `relations` (no `fields`) has no pre-image to
     * verify against. That must surface as ROLLBACK_UNVERIFIED, not a
     * false-positive `rolledBack: true` for a check that never ran.
     */
    public function testAtomicBatchWithARelationsOnlyUpdateCannotVerifyRollback(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', ['Children']);

        $record = ApiTestObject::create(['Title' => 'Unrelated to the relation change']);
        $record->write();

        $response = $this->apiPost('batch', [
            'atomic' => true,
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'relations' => ['Children' => ['mode' => 'set', 'items' => []]],
                ],
                ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Bogus' => 1]],
            ],
        ], $this->adminToken);

        $this->assertErrorCode($response, 'ROLLBACK_UNVERIFIED', 500);
    }

    /**
     * Code-review regression test for #127: an `upsert` op that resolves
     * to a record CREATED earlier in the same atomic batch (same external
     * id, still uncommitted) reports 'updated', not 'created'. After a
     * genuine rollback that record is correctly gone entirely — the
     * 'updated' branch must not independently re-fail on "the record is
     * missing"; the 'created' branch already asserts that's the correct
     * state. Before the fix this reported ROLLBACK_UNVERIFIED for a batch
     * that had, in fact, rolled back cleanly.
     */
    public function testAtomicBatchWithAnUpsertCreateThenUpsertUpdateOfTheSameRecordRollsBackCleanly(): void
    {
        $response = $this->apiPost('batch', [
            'atomic' => true,
            'operations' => [
                [
                    'op' => 'upsert',
                    'class' => 'ApiTest',
                    'externalId' => 'created-then-updated',
                    'fields' => ['Title' => 'First write'],
                ],
                [
                    'op' => 'upsert',
                    'class' => 'ApiTest',
                    'externalId' => 'created-then-updated',
                    'fields' => ['Title' => 'Second write, same record'],
                ],
                ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Bogus' => 1]],
            ],
        ], $this->adminToken);

        $body = $this->assertErrorCode($response, 'VALIDATION_FAILED', 422);

        $this->assertTrue(
            $body['error']['details'][0]['rolledBack'],
            'a record created and then updated within the same rolled-back batch must not report ROLLBACK_UNVERIFIED'
        );
        $this->assertNull(ApiTestObject::get()->filter('FixtureIdentifier', 'created-then-updated')->first());
    }

    /**
     * Code-review regression test for #127: two `update` ops touching the
     * SAME record in one atomic batch must be checked against the record's
     * state before the EARLIEST of the two writes, not against each op's
     * own local snapshot — a genuine rollback restores the record all the
     * way back to before the first write, which necessarily contradicts
     * the second op's own (later) pre-image. Before the fix this reported
     * ROLLBACK_UNVERIFIED for a batch that had, in fact, rolled back
     * cleanly (confirmed by the Title assertion below).
     */
    public function testAtomicBatchWithTwoUpdatesToTheSameRecordRollsBackToTheEarliestState(): void
    {
        $record = ApiTestObject::create(['Title' => 'Original title']);
        $record->write();

        $response = $this->apiPost('batch', [
            'atomic' => true,
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'fields' => ['Title' => 'Second title'],
                ],
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'fields' => ['Title' => 'Third title'],
                ],
                ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Bogus' => 1]],
            ],
        ], $this->adminToken);

        $body = $this->assertErrorCode($response, 'VALIDATION_FAILED', 422);

        $this->assertTrue(
            $body['error']['details'][0]['rolledBack'],
            'two updates to the same record within one rolled-back batch must not report ROLLBACK_UNVERIFIED'
        );
        $this->assertSame('Original title', ApiTestObject::get()->byID($record->ID)->Title);
    }

    /**
     * Companion to the test above: an unpublish-mode delete on a Hierarchy
     * class must still report a genuinely-verified rollback — adding
     * delete verification must not turn every unpublish-mode delete into a
     * spurious ROLLBACK_UNVERIFIED, since DRAFT is untouched by an unpublish
     * either way and verifyRollback() must recognize that and skip it.
     */
    public function testAtomicBatchWithAnUnpublishDeleteStillReportsAVerifiedRollback(): void
    {
        $page = ApiTestPage::create(['Title' => 'Unpublish Rollback Target']);
        $page->write();
        $page->publishRecursive();

        $response = $this->apiPost('batch', [
            'atomic' => true,
            'operations' => [
                ['op' => 'delete', 'class' => 'ApiTestPage', 'id' => (int) $page->ID, 'mode' => 'unpublish'],
                ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Bogus' => 1]],
            ],
        ], $this->adminToken);

        $body = $this->assertErrorCode($response, 'VALIDATION_FAILED', 422);

        $this->assertTrue(
            $body['error']['details'][0]['rolledBack'],
            'an unpublish-mode delete must not be misreported as ROLLBACK_UNVERIFIED'
        );
    }

    /**
     * #71's stranded-descendants guard, wired end to end through the batch
     * delete op — every other test exercising it goes through
     * PublishOrchestrator directly or the record-action HTTP endpoint;
     * ApiTestVersionedObject (this file's usual delete-mode stub) has no
     * Hierarchy extension, so the guard is a no-op for it and none of the
     * existing batch delete tests actually exercise the
     * BatchProcessor -&gt; RecordWriter -&gt; PublishOrchestrator::delete()
     * force-threading chain against a class the guard applies to at all.
     */
    public function testBatchDeleteWithUnpublishModeRoutesThroughTheDescendantGuard(): void
    {
        $wrapper = ApiTestPage::create(['Title' => 'Batch Delete Wrapper']);
        $wrapper->write();
        $wrapper->publishRecursive();

        $child = ApiTestPage::create(['Title' => 'Batch Delete Child', 'ParentID' => $wrapper->ID]);
        $child->write();
        $child->publishRecursive();

        $refused = $this->decode($this->apiPost('batch', [
            'operations' => [
                ['op' => 'delete', 'class' => 'ApiTestPage', 'id' => (int) $wrapper->ID, 'mode' => 'unpublish'],
            ],
        ], $this->adminToken));

        $this->assertSame('UNPUBLISH_STRANDS_DESCENDANTS', $refused['data']['results'][0]['error']['code']);
        $this->assertTrue(
            (bool) Versioned::get_by_stage(ApiTestPage::class, Versioned::LIVE)->filter('ID', $wrapper->ID)->exists(),
            'the batch op reporting an error result must not have actually unpublished the wrapper'
        );

        $forced = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'delete',
                    'class' => 'ApiTestPage',
                    'id' => (int) $wrapper->ID,
                    'mode' => 'unpublish',
                    'force' => true,
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('deleted', $forced['data']['results'][0]['status']);
        $this->assertFalse(
            (bool) Versioned::get_by_stage(ApiTestPage::class, Versioned::LIVE)->filter('ID', $wrapper->ID)->exists()
        );
    }

    /**
     * #90: RecordWriter::write() now runs PublishOrchestrator::publish()
     * inside the same DB transaction as the field write and relation
     * writes — found necessary via /review-pr on #113, since a subtree
     * descendant authorization failure used to leave the field write
     * already committed while the batch op reported "error",
     * indistinguishable from "nothing happened". Confirms both halves:
     * the op reports the refusal, and the field write it happened
     * alongside is rolled back with it.
     */
    public function testBatchUpdateWithSubtreePublishRollsBackTheFieldWriteOnADescendantAuthorizationFailure(): void
    {
        $root = ApiTestPage::create(['Title' => 'Transaction Test Root']);
        $root->write();

        $child = ApiTestPage::create(['Title' => 'Transaction Test Child', 'ParentID' => $root->ID]);
        $child->write();

        // Root's own class-level write check uses the 'update' verb, which
        // stays granted — only 'action' (what the subtree walk checks on
        // descendants) is withdrawn, so the field write proceeds and only
        // the descendant-authorization check inside publish() fails.
        Config::modify()->set(ApiTestPage::class, 'api_access', 'read,create,update');

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTestPage',
                    'id' => (int) $root->ID,
                    'fields' => ['Title' => 'Should Not Land'],
                    'publish' => 'subtree',
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('error', $body['data']['results'][0]['status']);
        $this->assertSame('FORBIDDEN_CLASS', $body['data']['results'][0]['error']['code']);

        $this->assertSame(
            'Transaction Test Root',
            ApiTestPage::get()->byID($root->ID)->Title,
            'a batch op reporting an error must not have left its field write standing — the whole ' .
                'write+publish unit must roll back together, not just the publish half'
        );
    }

    /**
     * #64: the composition endpoint isn't the only way to attach an
     * element to an area — a plain batch/upsert create can set `ParentID`
     * directly too (BaseElement has no `api_writable_fields` here, so the
     * default `guarded` policy leaves has_one FKs, including `ParentID`,
     * writable). Elemental's per-page-type `disallowed_elements` must be
     * enforced on this path just as much as on composition, or it's a
     * side door around the CMS's own "add element" picker.
     */
    public function testBatchCreateOfADisallowedElementIsRejected(): void
    {
        $area = $this->createBlockPageWithArea('Batch Disallowed Target');

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestElement',
                    'fields' => ['Title' => 'Should be rejected', 'ParentID' => (int) $area->ID],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('error', $body['data']['results'][0]['status']);
        $this->assertSame('ELEMENT_NOT_ALLOWED_ON_PAGE', $body['data']['results'][0]['error']['code']);
        $this->assertSame(
            0,
            ApiTestElement::get()->filter('ParentID', $area->ID)->count(),
            'a rejected element must not be persisted into the area'
        );
    }

    /**
     * Companion to the rejection test: disallowing some other element type
     * on this page must not also block ApiTestElement, which was never
     * disallowed, from being attached to the same area via the same batch
     * path.
     */
    public function testBatchCreateOfAnAllowedElementStillSucceedsWhenAnotherTypeIsDisallowed(): void
    {
        $area = $this->createBlockPageWithArea('Batch Allowed Target');

        Config::modify()->set(
            ApiTestBlockPage::class,
            'disallowed_elements',
            ['DNADesign\\Elemental\\Models\\ElementContent']
        );
        static::resetElementalTypesCache();

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestElement',
                    'fields' => ['Title' => 'Still allowed', 'ParentID' => (int) $area->ID],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('created', $body['data']['results'][0]['status']);
    }

    /**
     * #64 review follow-up: `assertElementPlacementAllowed()` only fires
     * when `ParentID` actually changes — a plain field-only update to an
     * element already sitting on a page must keep succeeding even after
     * that page's config narrows to newly disallow the element's type.
     * Elemental's own `getElementalTypes()` gate has no equivalent for
     * "keep editing existing content," only for a new placement.
     */
    public function testBatchUpdateOfAnAlreadyPlacedElementIsUnaffectedByALaterDisallow(): void
    {
        $area = $this->createBlockPageWithArea('Batch Edit Target');

        $created = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestElement',
                    'fields' => ['Title' => 'Created while allowed', 'ParentID' => (int) $area->ID],
                ],
            ],
        ], $this->adminToken));

        $elementId = (int) $created['data']['results'][0]['id'];

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $updated = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTestElement',
                    'id' => $elementId,
                    'fields' => ['Title' => 'Just fixing a typo'],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('updated', $updated['data']['results'][0]['status']);
        $this->assertSame('Just fixing a typo', ApiTestElement::get()->byID($elementId)->Title);
    }

    /**
     * The other side of the same fix: a batch update that DOES change
     * `ParentID` — re-parenting an already-placed element onto a
     * different page's area — is a genuine new placement on the target
     * page and must still be checked against it, exactly like a create.
     */
    public function testBatchUpdateReparentingAnElementToADisallowedAreaIsRejected(): void
    {
        $sourceArea = $this->createBlockPageWithArea('Batch Reparent Source');
        $targetArea = $this->createBlockPageWithArea('Batch Reparent Target');

        $created = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTestElement',
                    'fields' => ['Title' => 'Reparent me', 'ParentID' => (int) $sourceArea->ID],
                ],
            ],
        ], $this->adminToken));

        $elementId = (int) $created['data']['results'][0]['id'];

        // Both pages are the same class, so this necessarily disallows the
        // type on the source page too — irrelevant here, since the source
        // placement is pre-existing and unchanged, and the fix under test
        // is precisely that an unchanged placement is never re-checked.
        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $reparented = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTestElement',
                    'id' => $elementId,
                    'fields' => ['ParentID' => (int) $targetArea->ID],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('error', $reparented['data']['results'][0]['status']);
        $this->assertSame('ELEMENT_NOT_ALLOWED_ON_PAGE', $reparented['data']['results'][0]['error']['code']);
        $this->assertSame(
            (int) $sourceArea->ID,
            (int) ApiTestElement::get()->byID($elementId)->ParentID,
            'a rejected re-parent must leave the element in its original area'
        );
    }

    /**
     * #130: a dry-run create must leave no record behind and the response
     * must use the `would*` vocabulary, not the real-run one — a caller
     * inspecting `status` must never be able to mistake this for a
     * confirmed write.
     */
    public function testDryRunCreateLeavesNoRecordAndReportsWouldCreate(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'dryRun' => true,
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTest',
                    'externalId' => 'dry-run-create',
                    'fields' => ['Title' => 'Would be created'],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame(['operation' => 'batchDryRun', 'atomic' => false], $body['meta']);
        $this->assertSame(['wouldCreate'], array_column($body['data']['results'], 'status'));
        $this->assertSame(1, $body['data']['summary']['wouldCreate']);
        $this->assertNull(
            ApiTestObject::get()->filter('FixtureIdentifier', 'dry-run-create')->first(),
            'a dry run must never leave a real record behind'
        );
    }

    /**
     * #130's whole reason for existing: the exact "create + archive against
     * production" probe pattern the prod-replay retrospective had to fall
     * back to, now provable in one preflight call. update and delete both
     * covered in the same batch.
     */
    public function testDryRunUpdateAndDeleteLeaveNoTraceAndReportWouldVerbs(): void
    {
        $record = ApiTestObject::create(['Title' => 'Original title']);
        $record->write();
        $toDelete = ApiTestObject::create(['Title' => 'Would be archived']);
        $toDelete->write();

        $body = $this->decode($this->apiPost('batch', [
            'dryRun' => true,
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'fields' => ['Title' => 'Would be updated'],
                ],
                [
                    'op' => 'delete',
                    'class' => 'ApiTest',
                    'id' => (int) $toDelete->ID,
                    'mode' => 'archive',
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame(['wouldUpdate', 'wouldDelete'], array_column($body['data']['results'], 'status'));
        $this->assertSame(
            'Original title',
            ApiTestObject::get()->byID($record->ID)->Title,
            'a dry-run update must not touch the row'
        );
        $this->assertNotNull(
            ApiTestObject::get()->byID($toDelete->ID),
            'a dry-run delete must not touch the row'
        );
        $this->assertFalse(
            $body['data']['results'][1]['deleted'],
            'a wouldDelete result must not claim "deleted": true — the record still exists'
        );
    }

    /**
     * Code-review regression test: the module's normal element-attach
     * shape — an `update` op whose payload declares only `relations`, no
     * `fields` — has nothing for the rollback pre-image mechanism to
     * snapshot. On a REAL atomic failure that's deliberately
     * ROLLBACK_UNVERIFIED (see the #127 test of the same shape). On a dry
     * run nothing failed and the whole batch is guaranteed rolled back
     * regardless, so this must still succeed — treating "nothing to
     * check" as "verification failed" would make dry-run unusable for
     * the single most common batch shape.
     */
    public function testDryRunWithARelationsOnlyUpdateStillSucceeds(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', ['Children']);

        $record = ApiTestObject::create(['Title' => 'Unrelated to the relation change']);
        $record->write();

        $body = $this->decode($this->apiPost('batch', [
            'dryRun' => true,
            'operations' => [
                [
                    'op' => 'update',
                    'class' => 'ApiTest',
                    'id' => (int) $record->ID,
                    'relations' => ['Children' => ['mode' => 'set', 'items' => []]],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame(['wouldUpdate'], array_column($body['data']['results'], 'status'));
    }

    /**
     * A dry run authorizes exactly like a real run — the whole point is to
     * predict what a real run would do, including its failures.
     */
    public function testDryRunStillReportsPerOpErrors(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'dryRun' => true,
            'operations' => [
                ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Bogus' => 1]],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame('error', $body['data']['results'][0]['status']);
        $this->assertSame('UNKNOWN_FIELD', $body['data']['results'][0]['error']['code']);
    }

    /**
     * Atomic + dryRun: a real op failure must predict the exact same
     * VALIDATION_FAILED envelope a real atomic run would report — a dry run
     * predicts what a real run would do, it doesn't change the shape of
     * the failure response. The one difference: `rolledBack` is
     * unconditionally true here (the whole batch was wrapped in a
     * transaction that never had a chance to commit), not independently
     * re-derived per request the way a real failed atomic run's is.
     *
     * Code-review regression coverage: the first version of this only
     * asserted `rolledBack`/the error code, which passed even when
     * `error.details[0].results[].status` still reported real-run verbs
     * (`created`, not `wouldCreate`) for a batch that never committed —
     * this now pins the mapped vocabulary and the `dryRun` marker too,
     * since `meta` is always `{}` on an error response (module-wide
     * convention — see `ContentApiController::errorResponse()`) and can't
     * carry that signal instead.
     */
    public function testAtomicDryRunPredictsTheSameValidationFailureAndLeavesNothingBehind(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'atomic' => true,
            'dryRun' => true,
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTest',
                    'externalId' => 'atomic-dry-1',
                    'fields' => ['Title' => 'First'],
                ],
                ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Bogus' => 1]],
            ],
        ], $this->adminToken));

        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $detail = $body['error']['details'][0];
        $this->assertTrue($detail['rolledBack']);
        $this->assertTrue($detail['dryRun']);
        $this->assertSame(
            ['wouldCreate', 'error'],
            array_column($detail['results'], 'status'),
            'the error envelope must use the same would* vocabulary as a successful dry run'
        );
        $this->assertSame(1, $detail['summary']['wouldCreate']);
        $this->assertNull(ApiTestObject::get()->filter('FixtureIdentifier', 'atomic-dry-1')->first());
    }

    /**
     * End-to-end coverage of runDryRun()'s own ROLLBACK_UNVERIFIED path —
     * "the loudest possible failure" the whole dry-run safety guarantee
     * rests on, previously exercised only for the real-atomic-failure
     * path (testUnverifiedRollbackReportsDistinctlyFromAVerifiedOne
     * above). Same forcing mechanism: the framework's real rollback
     * works correctly under normal conditions, so nothing in a real
     * request can force a genuine failed-verification outcome;
     * ForceUnverifiedRollbackBatchProcessor swaps in to force it. Also
     * pins that this specific response path deliberately does NOT map
     * through the would* vocabulary (real verbs are the honest signal
     * once the caller genuinely can't tell whether something committed)
     * and carries the `dryRun: true` marker `error.details` needs since
     * `meta` is always `{}` on any error response.
     */
    public function testDryRunUnverifiedRollbackReportsWithRealVerbsAndDryRunMarker(): void
    {
        Injector::inst()->registerService(
            ForceUnverifiedRollbackBatchProcessor::create(),
            BatchProcessor::class
        );

        $body = $this->decode($this->apiPost('batch', [
            'dryRun' => true,
            'operations' => [
                [
                    'op' => 'create',
                    'class' => 'ApiTest',
                    'externalId' => 'dry-unverified-1',
                    'fields' => ['Title' => 'First'],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('ROLLBACK_UNVERIFIED', $body['error']['code']);
        $this->assertSame(500, $body['error']['status']);

        $detail = $body['error']['details'][0];
        $this->assertTrue($detail['dryRun']);
        $this->assertSame(
            ['created'],
            array_column($detail['results'], 'status'),
            'ROLLBACK_UNVERIFIED must use real verbs, not would* — the caller genuinely doesn\'t know '
                . 'whether this committed'
        );
        $this->assertNull(ApiTestObject::get()->filter('FixtureIdentifier', 'dry-unverified-1')->first());
    }

    /**
     * The environment gate and CONTENT_API_POPULATE check must still apply
     * to a dry run — validate-only still authorizes and resolves everything
     * a real run would, which alone leaks schema/permission information a
     * caller shouldn't get for free just by adding "dryRun": true.
     */
    public function testDryRunIsStillEnvironmentGated(): void
    {
        Config::modify()->set(EnvironmentGate::class, 'population_enabled_environments', []);

        $response = $this->apiPost('batch', [
            'dryRun' => true,
            'operations' => [
                ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Title' => 'Should not resolve']],
            ],
        ], $this->adminToken);

        $this->assertErrorCode($response, 'ENV_FORBIDDEN', 403);
    }

    /**
     * Creates an ApiTestBlockPage with a genuinely persisted ElementalArea.
     * Elemental only auto-creates the area during a write that happens in
     * the DRAFT reading stage (`ElementalAreasExtension::
     * allowAlteringElementalArea()`) — the same staging every write in this
     * module already runs under via `Versioned::withVersionedMode()` (see
     * `BatchProcessor::run()`) — so a bare `$page->write()` at the test's
     * own top level, outside that stage, would silently leave the area
     * relation at ID 0.
     */
    private function createBlockPageWithArea(string $title): ElementalArea
    {
        return Versioned::withVersionedMode(function () use ($title) {
            Versioned::set_stage(Versioned::DRAFT);

            $page = ApiTestBlockPage::create(['Title' => $title]);
            $page->write();

            return $page->ElementalArea();
        });
    }
}
