<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;

class BatchTest extends ContentApiTestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->mintTokenFor('adminUser');
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
}
