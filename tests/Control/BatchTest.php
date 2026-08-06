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
use DNADesign\Elemental\Extensions\ElementalAreasExtension;
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
    }

    protected function tearDown(): void
    {
        // See CompositionTest's identical reset — ElementalAreasExtension's
        // getElementalTypes() caches per page class name in a static that
        // Config::modify()'s automatic rollback doesn't touch.
        ElementalAreasExtension::reset();

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
        ElementalAreasExtension::reset();

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
        ElementalAreasExtension::reset();

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
