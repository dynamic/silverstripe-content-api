<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;

class RecordActionsTest extends ContentApiTestCase
{
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
        $token = $this->mintTokenFor('apiUser');
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->apiPost("records/ApiTest/{$record->ID}/explode", [], $token);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }

    public function testActionRequiresVerb(): void
    {
        \SilverStripe\Core\Config\Config::modify()
            ->set(ApiTestVersionedObject::class, 'api_access', 'read');

        $token = $this->mintTokenFor('adminUser');
        $record = $this->objFromFixture(ApiTestVersionedObject::class, 'draftOnly');

        $response = $this->apiPost("records/ApiTestVersioned/{$record->ID}/publish", [], $token);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    public function testRemovedCrudEndpointsAreGone(): void
    {
        $token = $this->mintTokenFor('adminUser');
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        // POST create — moved to colymba /api or batch.
        $create = $this->apiPost('records/ApiTest', ['fields' => ['Title' => 'X']], $token);
        $this->assertSame(404, $create->getStatusCode());

        // PATCH — moved to colymba PUT or batch update op.
        $patch = $this->apiPatch("records/ApiTest/{$record->ID}", ['fields' => ['Title' => 'X']], $token);
        $this->assertSame(404, $patch->getStatusCode());

        // DELETE — moved to colymba DELETE or batch delete op / archive action.
        $delete = $this->apiDelete("records/ApiTest/{$record->ID}", $token);
        $this->assertSame(404, $delete->getStatusCode());
    }
}
