<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Control\ContentApiController;
use Dynamic\ContentApi\Serialize\RecordSerializer;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestFingerprintRelatedDeniedSubclassObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestFingerprintRelatedObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;

/**
 * #236: data a project configured as hidden must not reach a token holder
 * through the read side of /content-api/v1.
 */
class ReadExposureTest extends ContentApiTestCase
{
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8'
        . 'z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->mintTokenFor('adminUser');
    }

    private function deniedRow(): ApiTestFingerprintRelatedDeniedSubclassObject
    {
        $row = ApiTestFingerprintRelatedDeniedSubclassObject::create([
            'Title' => 'Denied subclass row',
            'FixtureIdentifier' => 'denied-sub',
        ]);
        $row->write();

        return $row;
    }

    /**
     * Finding 1: an explicitly denied subclass stays denied when its row is
     * addressed through an exposed ancestor's ref.
     */
    public function testADeniedSubclassRowIsRefusedThroughItsAncestorRef(): void
    {
        $row = $this->deniedRow();

        $this->assertErrorCode(
            $this->apiGet("records/ApiTestFingerprintRelated/{$row->ID}", $this->adminToken),
            'FORBIDDEN_CLASS',
            403
        );
        $this->assertErrorCode(
            $this->apiGet("records/ApiTestFingerprintRelated/{$row->ID}/parity", $this->adminToken),
            'FORBIDDEN_CLASS',
            403
        );
        $this->assertErrorCode(
            $this->apiPost("records/ApiTestFingerprintRelated/{$row->ID}/publish", [], $this->adminToken),
            'FORBIDDEN_CLASS',
            403
        );
        $this->assertFalse($row->isPublished());
    }

    public function testADeniedSubclassRowIsLeftOutOfTheAncestorList(): void
    {
        $row = $this->deniedRow();
        $visible = ApiTestFingerprintRelatedObject::create(['Title' => 'Visible row']);
        $visible->write();

        $body = $this->decode($this->apiGet('records/ApiTestFingerprintRelated', $this->adminToken));
        $ids = array_column($body['data'], 'id');

        $this->assertNotContains((int) $row->ID, $ids);
        $this->assertContains((int) $visible->ID, $ids);
        $this->assertSame(count($ids), $body['meta']['total']);
    }

    public function testAnUpsertResolvingToADeniedSubclassRowIsRefused(): void
    {
        $this->deniedRow();

        $body = $this->decode($this->apiPost('batch', ['operations' => [[
            'op' => 'upsert',
            'class' => 'ApiTestFingerprintRelated',
            'externalId' => 'denied-sub',
            'fields' => ['Title' => 'Rewritten'],
        ]]], $this->adminToken));

        $this->assertSame('FORBIDDEN_CLASS', $body['data']['results'][0]['error']['code']);
    }

    /**
     * An Image that explicitly denies is not governed by File's grant.
     */
    public function testAnExplicitlyDeniedImageIsNotReadableThroughFilesGrant(): void
    {
        TestAssetStore::activate('ContentApiReadExposureTest');

        try {
            Config::modify()->set(File::class, 'api_access', 'read');
            Config::modify()->set(Image::class, 'api_access', false);

            $image = Image::create();
            $image->setFromString(base64_decode(ReadExposureTest::PNG_BASE64), 'exposure/pixel.png');
            $image->write();

            $this->assertErrorCode($this->apiGet("assets/{$image->ID}", $this->adminToken), 'FORBIDDEN_CLASS', 403);
        } finally {
            TestAssetStore::reset();
        }
    }

    /**
     * Finding 2: filters and sorts are limited to columns a response shows.
     */
    public function testFilteringOrSortingOnAWithheldColumnIsRefused(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_fields', ['Title']);

        $paths = ['records/ApiTest?Rank=3', 'records/ApiTest?Rank__GreaterThan=2', 'records/ApiTest?sort=-Rank'];

        foreach ($paths as $path) {
            $this->assertErrorCode($this->apiGet($path, $this->adminToken), 'UNKNOWN_FIELD', 422);
        }

        $ok = $this->apiGet('records/ApiTest?Title__PartialMatch=Beta', $this->adminToken);
        $this->assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
    }

    public function testFilteringOnAHiddenFieldIsRefused(): void
    {
        Config::modify()->merge(RecordSerializer::class, 'hidden_fields', ['Title']);

        $this->assertErrorCode(
            $this->apiGet('records/ApiTest?Title__StartsWith=Bet', $this->adminToken),
            'UNKNOWN_FIELD',
            422
        );
    }

    /**
     * Finding 3: the schema route refuses a mapped class with no verbs.
     */
    public function testSchemaForAnUnexposedClassIsRefused(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', false);

        $this->assertErrorCode($this->apiGet('schema/ApiTest', $this->adminToken), 'FORBIDDEN_CLASS', 403);
    }

    /**
     * Finding 4: authenticated responses must not be stored by a cache.
     */
    public function testResponsesAreMarkedNoStore(): void
    {
        $ok = $this->apiGet('records/ApiTest', $this->adminToken);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $ok->getHeader('Cache-Control'));

        $denied = $this->apiGet('records/ApiTest');
        $this->assertStringContainsString('no-store', (string) $denied->getHeader('Cache-Control'));
        $this->assertSame(ContentApiController::CACHE_CONTROL, $ok->getHeader('Cache-Control'));
    }

    /**
     * Each path that loads a row by the requested class checks the row's own
     * class, independently of the read path above.
     */
    public function testParityOfADeniedSubclassRowIsRefused(): void
    {
        $row = $this->deniedRow();

        $this->assertErrorCode(
            $this->apiGet("records/ApiTestFingerprintRelated/{$row->ID}/parity", $this->adminToken),
            'FORBIDDEN_CLASS',
            403
        );
    }

    public function testStageActionsOnADeniedSubclassRowAreRefused(): void
    {
        $row = $this->deniedRow();
        $row->publishSingle();

        foreach (['publish', 'unpublish', 'archive'] as $action) {
            $this->assertErrorCode(
                $this->apiPost("records/ApiTestFingerprintRelated/{$row->ID}/{$action}", [], $this->adminToken),
                'FORBIDDEN_CLASS',
                403
            );
        }

        $this->assertTrue($row->isPublished());
    }

    public function testClassNameIsFilterableEvenWithApiFields(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_fields', ['Title']);

        $ok = $this->apiGet('records/ApiTest?ClassName=' . urlencode(ApiTestObject::class), $this->adminToken);

        $this->assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
    }
}
