<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use SilverStripe\Core\Config\Config;

class RecordsReadTest extends ContentApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->mintTokenFor('apiUser');
    }

    public function testListReturnsRecords(): void
    {
        $response = $this->apiGet('records/ApiTest', $this->token);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // canView filtering runs before total/pagination are computed (#20),
        // so total reflects only the 5 visible records, not the raw 6.
        $this->assertSame(5, $body['meta']['total']);
        $this->assertCount(5, $body['data']);

        $first = $body['data'][0];
        $this->assertSame('ApiTest', $first['classRef']);
        $this->assertArrayHasKey('Title', $first['fields']);
        $this->assertArrayNotHasKey('FixtureIdentifier', $first['fields']);
    }

    public function testListFilters(): void
    {
        $body = $this->decode($this->apiGet('records/ApiTest?Title=Beta', $this->token));
        $this->assertCount(1, $body['data']);
        $this->assertSame('Beta', $body['data'][0]['fields']['Title']);

        $body = $this->decode($this->apiGet('records/ApiTest?Title__PartialMatch=Beta', $this->token));
        $this->assertCount(2, $body['data']);

        $body = $this->decode($this->apiGet('records/ApiTest?Rank__GreaterThan=1&Rank__LessThan=99', $this->token));
        $this->assertCount(2, $body['data']);
    }

    public function testListRejectsUnknownFilterField(): void
    {
        $response = $this->apiGet('records/ApiTest?Bogus=1', $this->token);

        $this->assertErrorCode($response, 'UNKNOWN_FIELD', 422);
    }

    public function testListRejectsUnknownModifier(): void
    {
        $response = $this->apiGet('records/ApiTest?Title__DropTable=1', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testListSortAndPagination(): void
    {
        $body = $this->decode($this->apiGet('records/ApiTest?sort=-Rank&limit=2', $this->token));

        // canView filtering runs before pagination (#20): Secret (Rank 99)
        // is excluded from the visible set entirely, so a full 2-record
        // page comes back — [Beta Prime, Beta] — instead of a short page
        // with Secret silently occupying a slot and then vanishing.
        $this->assertCount(2, $body['data']);
        $this->assertSame(2, $body['meta']['limit']);
        $this->assertSame(5, $body['meta']['total']);
        $this->assertSame('Beta Prime', $body['data'][0]['fields']['Title']);
        $this->assertSame('Beta', $body['data'][1]['fields']['Title']);

        $body = $this->decode($this->apiGet('records/ApiTest?sort=Rank&limit=2&offset=4', $this->token));
        $this->assertSame(4, $body['meta']['offset']);
    }

    public function testReadOneById(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiGet("records/ApiTest/{$record->ID}", $this->token));

        $this->assertSame('Alpha', $body['data']['fields']['Title']);
        $this->assertSame('alpha', $body['data']['externalId']);
        $this->assertArrayHasKey('Buddy', $body['data']['relations']);
    }

    public function testReadOneByExternalId(): void
    {
        $body = $this->decode($this->apiGet('records/ApiTest/ext:alpha', $this->token));

        $this->assertSame('Alpha', $body['data']['fields']['Title']);
    }

    public function testAmbiguousExternalIdConflicts(): void
    {
        $response = $this->apiGet('records/ApiTest/ext:dup', $this->token);

        $this->assertErrorCode($response, 'MULTIPLE_MATCHES', 409);
    }

    public function testMissingExternalIdIs404(): void
    {
        $response = $this->apiGet('records/ApiTest/ext:nope', $this->token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    public function testMissingIdIs404(): void
    {
        $response = $this->apiGet('records/ApiTest/999999', $this->token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    public function testMalformedIdIsRejected(): void
    {
        $response = $this->apiGet('records/ApiTest/abc', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testUnknownClassRefIs404(): void
    {
        $response = $this->apiGet('records/Nope', $this->token);

        $this->assertErrorCode($response, 'UNKNOWN_CLASS', 404);
    }

    public function testMemberWithoutApiPermissionIsForbidden(): void
    {
        $token = $this->mintTokenFor('noAccessUser');

        $response = $this->apiGet('records/ApiTest', $token);

        $this->assertErrorCode($response, 'FORBIDDEN', 403);
    }

    public function testClassWithoutApiAccessIsForbidden(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', false);

        $response = $this->apiGet('records/ApiTest', $this->token);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    public function testContentApiAccessOverridesApiAccess(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', false);
        Config::modify()->set(ApiTestObject::class, 'content_api_access', 'read');

        $response = $this->apiGet('records/ApiTest', $this->token);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRecordCanViewIsEnforcedOnReadOne(): void
    {
        $secret = $this->objFromFixture(ApiTestObject::class, 'secret');

        $response = $this->apiGet("records/ApiTest/{$secret->ID}", $this->token);

        $this->assertErrorCode($response, 'FORBIDDEN_RECORD', 403);
    }

    public function testVersionedStageReads(): void
    {
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        // Draft (default) sees the record and reports stage state.
        $body = $this->decode($this->apiGet("records/ApiTestVersioned/{$record->ID}", $this->token));
        $this->assertTrue($body['data']['stage']['draft']);
        $this->assertFalse($body['data']['stage']['live']);
        $this->assertSame('draft', $body['meta']['stage']);

        // Live misses the draft-only record.
        $response = $this->apiGet("records/ApiTestVersioned/{$record->ID}?_stage=live", $this->token);
        $this->assertErrorCode($response, 'NOT_FOUND', 404);

        // After publish, live sees it.
        $record->publishSingle();
        $body = $this->decode($this->apiGet("records/ApiTestVersioned/{$record->ID}?_stage=live", $this->token));
        $this->assertTrue($body['data']['stage']['live']);
    }

    public function testInvalidStageIsRejected(): void
    {
        $response = $this->apiGet('records/ApiTestVersioned?_stage=nope', $this->token);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }
}
