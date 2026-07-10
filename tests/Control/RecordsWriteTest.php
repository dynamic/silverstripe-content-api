<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestTag;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use SilverStripe\Core\Config\Config;

class RecordsWriteTest extends ContentApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->mintTokenFor('apiUser');

        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', ['Children', 'Tags']);
    }

    public function testCreate(): void
    {
        $response = $this->apiPost('records/ApiTest', [
            'fields' => ['Title' => 'Created', 'Rank' => 10],
            'externalId' => 'created-one',
        ], $this->token);

        $body = $this->decode($response);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('created', $body['meta']['operation']);
        $this->assertSame('Created', $body['data']['fields']['Title']);
        $this->assertSame('created-one', $body['data']['externalId']);

        $record = ApiTestObject::get()->filter('FixtureIdentifier', 'created-one')->first();
        $this->assertNotNull($record);
        $this->assertSame(10, (int) $record->Rank);
    }

    public function testCreateWithDuplicateExternalIdConflicts(): void
    {
        $response = $this->apiPost('records/ApiTest', [
            'fields' => ['Title' => 'Dupe'],
            'externalId' => 'alpha',
        ], $this->token);

        $this->assertErrorCode($response, 'ALREADY_EXISTS', 409);
    }

    public function testUpsertUpdatesSparsely(): void
    {
        $response = $this->apiPost('records/ApiTest', [
            'fields' => ['Rank' => 42],
            'externalId' => 'alpha',
            'mode' => 'upsert',
        ], $this->token);

        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('updated', $body['meta']['operation']);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        // Rank updated, Title untouched — the anti-clobber guarantee.
        $this->assertSame(42, (int) $record->Rank);
        $this->assertSame('Alpha', $record->Title);
    }

    public function testPatchIsSparse(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->apiPatch("records/ApiTest/{$record->ID}", [
            'fields' => ['Rank' => 7],
        ], $this->token);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestObject::get()->byID($record->ID);
        $this->assertSame(7, (int) $fresh->Rank);
        $this->assertSame('Alpha', $fresh->Title);
    }

    public function testPatchByExternalId(): void
    {
        $response = $this->apiPatch('records/ApiTest/ext:beta', [
            'fields' => ['Rank' => 21],
        ], $this->token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(21, (int) $this->objFromFixture(ApiTestObject::class, 'two')->Rank);
    }

    public function testUnknownFieldRejectsWholePayload(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->apiPatch("records/ApiTest/{$record->ID}", [
            'fields' => ['Rank' => 55, 'Bogus' => 1],
        ], $this->token);

        $body = $this->assertErrorCode($response, 'UNKNOWN_FIELD', 422);
        $this->assertSame('Bogus', $body['error']['details'][0]['field']);

        // Nothing was applied — validate-before-apply.
        $this->assertSame(1, (int) ApiTestObject::get()->byID($record->ID)->Rank);
    }

    public function testProtectedFieldRejected(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->apiPatch("records/ApiTest/{$record->ID}", [
            'fields' => ['ID' => 999],
        ], $this->token);

        $this->assertErrorCode($response, 'READONLY_FIELD', 422);
    }

    public function testAllowlistPolicy(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_write_policy', 'allowlist');
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title']);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $denied = $this->apiPatch("records/ApiTest/{$record->ID}", [
            'fields' => ['Rank' => 5],
        ], $this->token);
        $this->assertErrorCode($denied, 'READONLY_FIELD', 422);

        $allowed = $this->apiPatch("records/ApiTest/{$record->ID}", [
            'fields' => ['Title' => 'Renamed'],
        ], $this->token);
        $this->assertSame(200, $allowed->getStatusCode());
        $this->assertSame('Renamed', ApiTestObject::get()->byID($record->ID)->Title);
    }

    public function testValidationFailureMapsPerField(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->apiPatch("records/ApiTest/{$record->ID}", [
            'fields' => ['Title' => 'Invalid'],
        ], $this->token);

        $body = $this->assertErrorCode($response, 'VALIDATION_FAILED', 422);
        $this->assertSame('Title', $body['error']['details'][0]['field']);
    }

    public function testHasOneWriteVariants(): void
    {
        $one = $this->objFromFixture(ApiTestObject::class, 'one');
        $two = $this->objFromFixture(ApiTestObject::class, 'two');

        // Integer ID form.
        $this->apiPatch("records/ApiTest/{$one->ID}", [
            'fields' => ['Buddy' => (int) $two->ID],
        ], $this->token);
        $this->assertSame((int) $two->ID, (int) ApiTestObject::get()->byID($one->ID)->BuddyID);

        // externalId object form.
        $this->apiPatch("records/ApiTest/{$one->ID}", [
            'fields' => ['Buddy' => ['externalId' => 'beta']],
        ], $this->token);
        $this->assertSame((int) $two->ID, (int) ApiTestObject::get()->byID($one->ID)->BuddyID);

        // null clears.
        $this->apiPatch("records/ApiTest/{$one->ID}", [
            'fields' => ['Buddy' => null],
        ], $this->token);
        $this->assertSame(0, (int) ApiTestObject::get()->byID($one->ID)->BuddyID);
    }

    public function testHasManyRelationModes(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $childOne = $this->objFromFixture(ApiTestChildObject::class, 'childOne');
        $childTwo = $this->objFromFixture(ApiTestChildObject::class, 'childTwo');

        $response = $this->apiPatch("records/ApiTest/{$record->ID}", [
            'relations' => [
                'Children' => ['mode' => 'set', 'items' => [(int) $childOne->ID, (int) $childTwo->ID]],
            ],
        ], $this->token);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(2, $record->Children()->count());

        $this->apiPatch("records/ApiTest/{$record->ID}", [
            'relations' => [
                'Children' => ['mode' => 'remove', 'items' => [(int) $childOne->ID]],
            ],
        ], $this->token);

        $this->assertSame(1, $record->Children()->count());
        $this->assertSame((int) $childTwo->ID, (int) $record->Children()->first()->ID);
    }

    public function testManyManyWithExtraFields(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $tag = $this->objFromFixture(ApiTestTag::class, 'tagOne');

        $response = $this->apiPatch("records/ApiTest/{$record->ID}", [
            'relations' => [
                'Tags' => [
                    'mode' => 'add',
                    'items' => [['id' => (int) $tag->ID, 'extraFields' => ['SortOrder' => 3]]],
                ],
            ],
        ], $this->token);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(3, (int) $record->Tags()->first()->SortOrder);
    }

    public function testUnlistedRelationIsReadonly(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', []);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->apiPatch("records/ApiTest/{$record->ID}", [
            'relations' => ['Children' => ['mode' => 'set', 'items' => []]],
        ], $this->token);

        $this->assertErrorCode($response, 'READONLY_FIELD', 422);
    }

    public function testUnknownRelation(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->apiPatch("records/ApiTest/{$record->ID}", [
            'relations' => ['Nope' => ['mode' => 'set', 'items' => []]],
        ], $this->token);

        $this->assertErrorCode($response, 'UNKNOWN_RELATION', 422);
    }

    public function testCreateRequiresVerb(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', 'read');

        $response = $this->apiPost('records/ApiTest', [
            'fields' => ['Title' => 'Nope'],
        ], $this->token);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    public function testCanCreateDenied(): void
    {
        // ApiTestVersionedObject has no canCreate override — DataObject's
        // default requires admin, which apiUser is not.
        $response = $this->apiPost('records/ApiTestVersioned', [
            'fields' => ['Title' => 'Nope'],
        ], $this->token);

        $this->assertErrorCode($response, 'FORBIDDEN_RECORD', 403);
    }

    public function testPublishModesOnWrite(): void
    {
        $token = $this->mintTokenFor('adminUser');

        // Default: draft only.
        $draft = $this->decode($this->apiPost('records/ApiTestVersioned', [
            'fields' => ['Title' => 'Draft Thing'],
            'externalId' => 'draft-thing',
        ], $token));
        $this->assertFalse($draft['data']['stage']['live']);

        // publish: single → live.
        $live = $this->decode($this->apiPost('records/ApiTestVersioned', [
            'fields' => ['Title' => 'Live Thing'],
            'externalId' => 'live-thing',
            'publish' => 'single',
        ], $token));
        $this->assertTrue($live['data']['stage']['live']);
        $this->assertFalse($live['data']['stage']['modifiedOnDraft']);
    }

    public function testRecordStageActions(): void
    {
        $token = $this->mintTokenFor('adminUser');
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        // publish
        $published = $this->decode(
            $this->apiPost("records/ApiTestVersioned/{$record->ID}/publish", [], $token)
        );
        $this->assertTrue($published['data']['stage']['live']);

        // unpublish
        $unpublished = $this->decode(
            $this->apiPost("records/ApiTestVersioned/{$record->ID}/unpublish", [], $token)
        );
        $this->assertFalse($unpublished['data']['stage']['live']);

        // archive
        $archived = $this->decode(
            $this->apiPost("records/ApiTestVersioned/{$record->ID}/archive", [], $token)
        );
        $this->assertTrue($archived['data']['archived']);

        $miss = $this->apiGet("records/ApiTestVersioned/{$record->ID}", $token);
        $this->assertErrorCode($miss, 'NOT_FOUND', 404);
    }

    public function testUnknownRecordActionIs404(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->apiPost("records/ApiTest/{$record->ID}/explode", [], $this->token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    public function testDeleteUnversioned(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'three');
        $id = (int) $record->ID;

        $response = $this->apiDelete("records/ApiTest/{$id}", $this->token);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['data']['deleted']);
        $this->assertNull(ApiTestObject::get()->byID($id));
    }

    public function testDeleteVersionedModes(): void
    {
        $token = $this->mintTokenFor('adminUser');
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');
        $record->publishSingle();

        // hard is refused on versioned classes.
        $refused = $this->apiDelete("records/ApiTestVersioned/{$record->ID}?mode=hard", $token);
        $this->assertErrorCode($refused, 'PAYLOAD_INVALID', 400);

        // unpublish keeps draft.
        $this->apiDelete("records/ApiTestVersioned/{$record->ID}?mode=unpublish", $token);
        $this->assertNotNull(ApiTestVersionedObject::get()->byID($record->ID));
        $this->assertFalse($record->isPublished());

        // archive (default) removes from both stages.
        $this->apiDelete("records/ApiTestVersioned/{$record->ID}", $token);
        $this->assertNull(ApiTestVersionedObject::get()->byID($record->ID));
    }
}
