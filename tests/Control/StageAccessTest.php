<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestStageGatedObject;
use SilverStripe\Versioned\Versioned;

/**
 * #235: a model that allows edit but denies canPublish()/canUnpublish() is
 * refused publish and unpublish through the API, as the CMS refuses them,
 * while ordinary field writes still succeed.
 */
class StageAccessTest extends ContentApiTestCase
{
    private function record(): ApiTestStageGatedObject
    {
        $record = ApiTestStageGatedObject::create(['Title' => 'Stage gated']);
        $record->write();

        return $record;
    }

    private function isLive(int $id): bool
    {
        return Versioned::get_by_stage(ApiTestStageGatedObject::class, Versioned::LIVE)->byID($id) !== null;
    }

    public function testRecordPublishHonoursCanPublish(): void
    {
        $record = $this->record();

        $response = $this->apiPost(
            "records/ApiTestStageGated/{$record->ID}/publish",
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
            "records/ApiTestStageGated/{$record->ID}/unpublish",
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
            'class' => 'ApiTestStageGated',
            'id' => (int) $record->ID,
            'fields' => ['Title' => 'Published?'],
            'publish' => 'single',
        ]]], $token));

        $this->assertSame('error', $body['data']['results'][0]['status']);
        $this->assertSame('FORBIDDEN_RECORD', $body['data']['results'][0]['error']['code']);
        $this->assertFalse($this->isLive((int) $record->ID));

        $body = $this->decode($this->apiPost('batch', ['operations' => [[
            'op' => 'update',
            'class' => 'ApiTestStageGated',
            'id' => (int) $record->ID,
            'fields' => ['Title' => 'Draft edit'],
        ]]], $token));

        $this->assertSame('updated', $body['data']['results'][0]['status']);
        $this->assertSame('Draft edit', ApiTestStageGatedObject::get()->byID($record->ID)->Title);
    }

    /**
     * Any delete that takes a record off Live asks canUnpublish() too, as the
     * CMS's canArchive() does.
     */
    public function testBatchDeleteInUnpublishModeHonoursCanUnpublish(): void
    {
        $record = $this->record();
        $record->publishSingle();

        $body = $this->decode($this->apiPost('batch', ['operations' => [[
            'op' => 'delete',
            'class' => 'ApiTestStageGated',
            'id' => (int) $record->ID,
            'mode' => 'unpublish',
        ]]], $this->mintTokenFor('populateUser')));

        $this->assertSame('FORBIDDEN_RECORD', $body['data']['results'][0]['error']['code']);
        $this->assertStringContainsString('unpublish', $body['data']['results'][0]['error']['message']);
        $this->assertTrue($this->isLive((int) $record->ID));
    }

    public function testArchiveOfAPublishedRecordHonoursCanUnpublish(): void
    {
        $record = $this->record();
        $record->publishSingle();

        $response = $this->apiPost(
            "records/ApiTestStageGated/{$record->ID}/archive",
            [],
            $this->mintTokenFor('apiUser')
        );

        $body = $this->assertErrorCode($response, 'FORBIDDEN_RECORD', 403);
        $this->assertStringContainsString('unpublish', $body['error']['message']);
        $this->assertTrue($this->isLive((int) $record->ID));
    }
}
