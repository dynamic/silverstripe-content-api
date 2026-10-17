<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestPublishDenyingExtension;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use SilverStripe\Versioned\Versioned;

/**
 * #235: a model that allows edit but denies canPublish()/canUnpublish() is
 * refused publish and unpublish through the API, as the CMS refuses them,
 * while ordinary field writes still succeed.
 */
class StageAccessTest extends ContentApiTestCase
{
    protected static $required_extensions = [
        ApiTestVersionedObject::class => [ApiTestPublishDenyingExtension::class],
    ];

    private function record(): ApiTestVersionedObject
    {
        $record = ApiTestVersionedObject::create(['Title' => 'Stage gated']);
        $record->write();

        return $record;
    }

    private function isLive(int $id): bool
    {
        return Versioned::get_by_stage(ApiTestVersionedObject::class, Versioned::LIVE)->byID($id) !== null;
    }

    public function testRecordPublishHonoursCanPublish(): void
    {
        $record = $this->record();

        $response = $this->apiPost(
            "records/ApiTestVersioned/{$record->ID}/publish",
            [],
            $this->mintTokenFor('apiUser')
        );

        $this->assertErrorCode($response, 'FORBIDDEN_RECORD', 403);
        $this->assertFalse($this->isLive((int) $record->ID));
    }

    public function testRecordUnpublishHonoursCanUnpublish(): void
    {
        $record = $this->record();
        $record->publishSingle();

        $response = $this->apiPost(
            "records/ApiTestVersioned/{$record->ID}/unpublish",
            [],
            $this->mintTokenFor('apiUser')
        );

        $this->assertErrorCode($response, 'FORBIDDEN_RECORD', 403);
        $this->assertTrue($this->isLive((int) $record->ID));
    }

    public function testBatchPublishHonoursCanPublishWhileFieldWritesStillSucceed(): void
    {
        $record = $this->record();
        $token = $this->mintTokenFor('populateUser');

        $body = $this->decode($this->apiPost('batch', ['operations' => [[
            'op' => 'update',
            'class' => 'ApiTestVersioned',
            'id' => (int) $record->ID,
            'fields' => ['Title' => 'Published?'],
            'publish' => 'single',
        ]]], $token));

        $this->assertSame('error', $body['data']['results'][0]['status']);
        $this->assertSame('FORBIDDEN_RECORD', $body['data']['results'][0]['error']['code']);
        $this->assertFalse($this->isLive((int) $record->ID));

        $body = $this->decode($this->apiPost('batch', ['operations' => [[
            'op' => 'update',
            'class' => 'ApiTestVersioned',
            'id' => (int) $record->ID,
            'fields' => ['Title' => 'Draft edit'],
        ]]], $token));

        $this->assertSame('updated', $body['data']['results'][0]['status']);
        $this->assertSame('Draft edit', ApiTestVersionedObject::get()->byID($record->ID)->Title);
    }
}
