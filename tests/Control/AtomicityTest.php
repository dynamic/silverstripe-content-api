<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestExplodingHooksExtension;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Core\Config\Config;
use SilverStripe\Versioned\Versioned;

/**
 * #238: a request that fails part-way leaves nothing it already wrote, and a
 * batch op's own failure stays that op's error.
 */
class AtomicityTest extends ContentApiTestCase
{
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8'
        . 'z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected static $required_extensions = [
        ApiTestObject::class => [ApiTestExplodingHooksExtension::class],
        ApiTestVersionedObject::class => [ApiTestExplodingHooksExtension::class],
        ApiTestPage::class => [ApiTestExplodingHooksExtension::class],
    ];

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        ApiTestExplodingHooksExtension::disarm();
        $this->adminToken = $this->mintTokenFor('adminUser');
    }

    protected function tearDown(): void
    {
        ApiTestExplodingHooksExtension::disarm();

        parent::tearDown();
    }

    private function isLive(string $class, int $id): bool
    {
        return Versioned::get_by_stage($class, Versioned::LIVE)->byID($id) !== null;
    }

    private function isOnDraft(string $class, int $id): bool
    {
        return Versioned::get_by_stage($class, Versioned::DRAFT)->byID($id) !== null;
    }

    /**
     * Finding 2: a value the field type can't take is that op's
     * INVALID_VALUE, and the ops around it still run.
     */
    public function testAnUnsettableValueIsThatOpsInvalidValue(): void
    {
        $body = $this->decode($this->apiPost('batch', ['operations' => [
            ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Title' => 'First']],
            ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Title' => ['x']]],
            ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Title' => 'Third']],
        ]], $this->adminToken));

        $this->assertSame(['created', 'error', 'created'], array_column($body['data']['results'], 'status'));
        $this->assertSame('INVALID_VALUE', $body['data']['results'][1]['error']['code']);
    }

    public function testAnUnexpectedExceptionIsThatOpsServerError(): void
    {
        ApiTestExplodingHooksExtension::$explodeOn['write'] = 'Boom';

        $body = $this->decode($this->apiPost('batch', ['operations' => [
            ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Title' => 'Before']],
            ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Title' => 'Boom']],
            ['op' => 'create', 'class' => 'ApiTest', 'fields' => ['Title' => 'After']],
        ]], $this->adminToken));

        $this->assertSame(['created', 'error', 'created'], array_column($body['data']['results'], 'status'));
        $this->assertSame('SERVER_ERROR', $body['data']['results'][1]['error']['code']);
        $this->assertNull(ApiTestObject::get()->filter('Title', 'Boom')->first());
    }

    /**
     * Finding 3: an archive whose unpublish hook throws leaves both stages.
     */
    public function testAFailedBatchArchiveLeavesBothStages(): void
    {
        $record = ApiTestVersionedObject::create(['Title' => 'Doomed']);
        $record->write();
        $record->publishSingle();
        ApiTestExplodingHooksExtension::$explodeOn['unpublish'] = 'Doomed';

        $body = $this->decode($this->apiPost('batch', ['operations' => [
            ['op' => 'delete', 'class' => 'ApiTestVersioned', 'id' => (int) $record->ID, 'mode' => 'archive'],
        ]], $this->adminToken));

        $this->assertSame('error', $body['data']['results'][0]['status']);
        $this->assertTrue($this->isLive(ApiTestVersionedObject::class, (int) $record->ID));
        $this->assertTrue($this->isOnDraft(ApiTestVersionedObject::class, (int) $record->ID));
    }

    public function testAFailedArchiveActionLeavesBothStages(): void
    {
        $record = ApiTestVersionedObject::create(['Title' => 'Doomed']);
        $record->write();
        $record->publishSingle();
        ApiTestExplodingHooksExtension::$explodeOn['unpublish'] = 'Doomed';

        $response = $this->apiPost("records/ApiTestVersioned/{$record->ID}/archive", [], $this->adminToken);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertTrue($this->isLive(ApiTestVersionedObject::class, (int) $record->ID));
        $this->assertTrue($this->isOnDraft(ApiTestVersionedObject::class, (int) $record->ID));
    }

    /**
     * Finding 4: a subtree publish whose hook throws on a descendant leaves
     * nothing live.
     */
    public function testAFailedSubtreePublishLeavesNothingLive(): void
    {
        $root = ApiTestPage::create(['Title' => 'Root']);
        $root->write();
        $first = ApiTestPage::create(['Title' => 'First child', 'ParentID' => $root->ID]);
        $first->write();
        $second = ApiTestPage::create(['Title' => 'Second child', 'ParentID' => $root->ID]);
        $second->write();
        ApiTestExplodingHooksExtension::$explodeOn['publish'] = 'Second child';

        $response = $this->apiPost("records/ApiTestPage/{$root->ID}/publish", ['mode' => 'subtree'], $this->adminToken);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertFalse($this->isLive(ApiTestPage::class, (int) $root->ID));
        $this->assertFalse($this->isLive(ApiTestPage::class, (int) $first->ID));
    }

    /**
     * Finding 1: a composition that overwrites a published file and then
     * fails keeps the file's published bytes.
     */
    public function testAFailedCompositionKeepsAnOverwrittenFilesPublishedBytes(): void
    {
        TestAssetStore::activate('ContentApiAtomicityTest');

        try {
            Config::modify()->set(File::class, 'api_access', 'read,create,update,action');
            $original = 'original bytes';
            $file = File::create();
            $file->setFromString($original, 'comp/hero.pdf');
            $file->write();
            $file->publishSingle();

            $page = $this->objFromFixture(\Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage::class, 'blockPage');

            $response = $this->apiPost('compositions/page', [
                'page' => ['match' => ['id' => (int) $page->ID]],
                'publish' => 'recursive',
                'assets' => [['filename' => 'hero.pdf', 'folder' => 'comp', 'base64' => base64_encode('replacement')]],
                'elements' => [[
                    'class' => 'ApiTestElement',
                    'externalId' => 'atomic-e1',
                    'fields' => ['Photo' => ['$ref' => 'ghost']],
                ]],
            ], $this->adminToken);

            $this->assertErrorCode($response, 'UNRESOLVED_REF', 422);

            $live = Versioned::get_by_stage(File::class, Versioned::LIVE)->byID($file->ID);
            $this->assertSame(sha1($original), $live->getHash());
            $this->assertTrue($live->exists(), 'the published bytes are still in the store');
            $this->assertSame($original, $live->getString());
        } finally {
            TestAssetStore::reset();
        }
    }
}
