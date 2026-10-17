<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestTag;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use Dynamic\ContentApi\Write\WriteApplicator;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Versioned\Versioned;

/**
 * #239: the API never answers 2xx for a state change that didn't happen, or
 * misreport stage state.
 */
class SilentSuccessTest extends ContentApiTestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->mintTokenFor('adminUser');
        Config::modify()->set(SiteTree::class, 'api_access', 'read,update,delete,action,convert');
    }

    /**
     * A page published as SiteTree, then converted to ApiTestPage on draft
     * only.
     */
    private function convertedOnDraftOnly(): SiteTree
    {
        $page = SiteTree::create(['Title' => 'Converted on draft']);
        $page->write();
        $page->publishSingle();

        $response = $this->apiPost("pages/{$page->ID}/convert", ['className' => 'ApiTestPage'], $this->adminToken);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $page;
    }

    private function liveRow(int $id): ?SiteTree
    {
        return Versioned::get_by_stage(SiteTree::class, Versioned::LIVE)->byID($id);
    }

    /**
     * Finding 1: unpublish/archive after a draft-only conversion refuse
     * instead of answering 200 with the old-class page still live.
     */
    public function testTakingDownADraftOnlyConversionIsRefused(): void
    {
        $page = $this->convertedOnDraftOnly();

        foreach (['unpublish', 'archive'] as $action) {
            $this->assertErrorCode(
                $this->apiPost("records/ApiTestPage/{$page->ID}/{$action}", [], $this->adminToken),
                'STAGE_CLASS_MISMATCH',
                409
            );
        }

        $this->assertNotNull($this->liveRow((int) $page->ID));
        $this->assertNotNull(Versioned::get_by_stage(SiteTree::class, Versioned::DRAFT)->byID($page->ID));
    }

    public function testABatchDeleteOfADraftOnlyConversionIsRefused(): void
    {
        $page = $this->convertedOnDraftOnly();

        foreach (['archive', 'unpublish'] as $mode) {
            $body = $this->decode($this->apiPost('batch', ['operations' => [
                ['op' => 'delete', 'class' => 'ApiTestPage', 'id' => (int) $page->ID, 'mode' => $mode],
            ]], $this->adminToken));

            $this->assertSame('STAGE_CLASS_MISMATCH', $body['data']['results'][0]['error']['code'], $mode);
        }

        $this->assertNotNull($this->liveRow((int) $page->ID));
    }

    /**
     * Finding 3: publish with mode "none" is refused, not a no-op 200.
     */
    public function testPublishModeNoneIsRefused(): void
    {
        $record = ApiTestVersionedObject::create(['Title' => 'Not published']);
        $record->write();

        $body = $this->assertErrorCode(
            $this->apiPost("records/ApiTestVersioned/{$record->ID}/publish", ['mode' => 'none'], $this->adminToken),
            'PAYLOAD_INVALID',
            400
        );

        $this->assertStringNotContainsString('none,', $body['error']['message']);
        $this->assertFalse($record->isPublished());
    }

    /**
     * Finding 4: a live-only record (deleted from draft) reports no draft.
     */
    public function testALiveOnlyRecordReportsNoDraft(): void
    {
        $record = ApiTestVersionedObject::create(['Title' => 'Live only']);
        $record->write();
        $record->publishSingle();
        $record->deleteFromStage(Versioned::DRAFT);

        $body = $this->decode($this->apiGet("records/ApiTestVersioned/{$record->ID}?_stage=live", $this->adminToken));

        $this->assertSame(['draft' => false, 'live' => true, 'modifiedOnDraft' => false], $body['data']['stage']);
    }

    /**
     * Finding 5: an unknown many_many extraFields key is rejected (strict)
     * or warned about, not silently dropped.
     */
    public function testAnUnknownExtraFieldsKeyIsRejected(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', ['Tags', 'ThroughTags']);
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $tag = $this->objFromFixture(ApiTestTag::class, 'tagOne');

        foreach (['Tags', 'ThroughTags'] as $relation) {
            $body = $this->decode($this->apiPost('batch', ['operations' => [[
                'op' => 'update',
                'class' => 'ApiTest',
                'id' => (int) $record->ID,
                'relations' => [$relation => ['mode' => 'add', 'items' => [
                    ['id' => (int) $tag->ID, 'extraFields' => ['SortOrdr' => 7]],
                ]]],
            ]]], $this->adminToken));

            $error = $body['data']['results'][0]['error'];
            $this->assertSame('UNKNOWN_FIELD', $error['code'], $relation);
            $this->assertSame($relation . '.extraFields.SortOrdr', $error['details'][0]['field']);
        }
    }

    public function testAnUnknownExtraFieldsKeyIsAWarningWhenLenient(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', ['Tags']);
        Config::modify()->set(WriteApplicator::class, 'unknown_fields', 'lenient');
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $tag = $this->objFromFixture(ApiTestTag::class, 'tagOne');

        $body = $this->decode($this->apiPost('batch', ['operations' => [[
            'op' => 'update',
            'class' => 'ApiTest',
            'id' => (int) $record->ID,
            'relations' => ['Tags' => ['mode' => 'add', 'items' => [
                ['id' => (int) $tag->ID, 'extraFields' => ['SortOrdr' => 7]],
            ]]],
        ]]], $this->adminToken));

        $result = $body['data']['results'][0];
        $this->assertSame('updated', $result['status']);
        $this->assertSame('Tags.extraFields.SortOrdr', $result['warnings'][0]['field']);
    }
}
