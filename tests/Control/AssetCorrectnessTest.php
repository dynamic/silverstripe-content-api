<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Assets\AssetService;
use Dynamic\ContentApi\Control\Handlers\AssetHandler;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use ReflectionMethod;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * #240 (assets) and #246: asset uploads match, identify and report files
 * the way SilverStripe actually stores them.
 */
class AssetCorrectnessTest extends ContentApiTestCase
{
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8'
        . 'z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        TestAssetStore::activate('ContentApiAssetCorrectnessTest');
        $this->adminToken = $this->mintTokenFor('adminUser');
        Config::modify()->set(File::class, 'api_access', 'read,create,update,action');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();

        parent::tearDown();
    }

    private function upload(array $overrides = []): array
    {
        $response = $this->apiPost('assets', array_merge([
            'filename' => 'pixel.png',
            'folder' => 'assets-test',
            'base64' => AssetCorrectnessTest::PNG_BASE64,
        ], $overrides), $this->adminToken);

        return [$response, $this->decode($response)];
    }

    /**
     * #240 finding 3: a name SilverStripe rewrites is still found on re-upload.
     */
    public function testARewrittenNameIsFoundAgainOnReupload(): void
    {
        [, $first] = $this->upload(['filename' => 'Team Photo.png', 'folder' => 'About Us']);
        [$response, $second] = $this->upload(['filename' => 'Team Photo.png', 'folder' => 'About Us']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertTrue($second['data']['existed']);
        $this->assertSame($first['data']['id'], $second['data']['id']);
        $this->assertSame(1, File::get()->filter('Name:StartsWith', 'Team-Photo')->count());
    }

    /**
     * #240 finding 4: an external id stays on one file.
     */
    public function testAnExternalIdHeldAtAnotherPathIsAConflict(): void
    {
        $this->upload(['filename' => 'hero.png', 'externalId' => 'about-hero']);

        [$response] = $this->upload(['filename' => 'hero-2024.png', 'externalId' => 'about-hero']);

        $this->assertErrorCode($response, 'ASSET_CONFLICT', 409);
        $this->assertSame(1, File::get()->filter('FixtureIdentifier', 'about-hero')->count());
    }

    public function testAPathMatchCarryingAnotherKeyIsNotReKeyed(): void
    {
        $this->upload(['filename' => 'hero.png', 'externalId' => 'about-hero']);

        [$response] = $this->upload(['filename' => 'hero.png', 'externalId' => 'other-key']);

        $this->assertErrorCode($response, 'ASSET_CONFLICT', 409);
        $this->assertNotNull(File::get()->filter('FixtureIdentifier', 'about-hero')->first());
    }

    /**
     * #246 finding 2: `url` comes with a visibility, and no session grant.
     */
    public function testUploadsReportVisibility(): void
    {
        [, $draft] = $this->upload(['filename' => 'draft.png', 'publish' => false]);
        [, $live] = $this->upload(['filename' => 'live.png']);

        $this->assertSame('protected', $draft['data']['visibility']);
        $this->assertSame('public', $live['data']['visibility']);
    }

    /**
     * #246 finding 3: with verify_content_type on, an image extension must
     * hold image bytes.
     */
    public function testContentTypeVerificationIsOptIn(): void
    {
        $html = base64_encode('<html><body><script>alert(1)</script></body></html>');

        [$off] = $this->upload(['filename' => 'evil.jpg', 'base64' => $html]);
        $this->assertSame(201, $off->getStatusCode(), 'off by default, matching core');

        Config::modify()->set(AssetService::class, 'verify_content_type', true);
        [$on] = $this->upload(['filename' => 'evil2.jpg', 'base64' => $html]);

        $this->assertErrorCode($on, 'VALIDATION_FAILED', 422);
        $this->assertNull(File::get()->filter('Name', 'evil2.jpg')->first());

        [$fine] = $this->upload(['filename' => 'real.png']);
        $this->assertSame(201, $fine->getStatusCode());
    }

    /**
     * #246 finding 4: PHP's upload errors map to accurate errors.
     */
    public function testUploadErrorsMapToAccurateCodes(): void
    {
        $method = new ReflectionMethod(AssetHandler::class, 'assertUploadReceived');
        $method->setAccessible(true);
        $handler = AssetHandler::create();

        $cases = [
            'ini size' => [['error' => UPLOAD_ERR_INI_SIZE], 'PAYLOAD_TOO_LARGE'],
            'form size' => [['error' => UPLOAD_ERR_FORM_SIZE], 'PAYLOAD_TOO_LARGE'],
            'partial' => [['error' => UPLOAD_ERR_PARTIAL], 'PAYLOAD_INVALID'],
            'file[] array' => [['error' => [UPLOAD_ERR_OK]], 'PAYLOAD_INVALID'],
        ];

        foreach ($cases as $label => [$upload, $expected]) {
            try {
                $method->invoke($handler, $upload + ['size' => 0, 'tmp_name' => '']);
                $this->fail('expected an ApiError for ' . $label);
            } catch (ApiError $error) {
                $this->assertSame($expected, $error->toArray()['code'], $label);
            }
        }

        $method->invoke($handler, ['error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => '/tmp/x']);
        $this->addToAssertionCount(1);
    }

    /**
     * PHP reads post_max_size 0 as no limit, so a body without a file part
     * is not "too large" under it.
     */
    public function testAZeroPostMaxSizeIsNoLimit(): void
    {
        $method = new ReflectionMethod(AssetHandler::class, 'exceedsPostLimit');
        $method->setAccessible(true);
        $handler = AssetHandler::create();

        $this->assertFalse($method->invoke($handler, 5000, '0'));
        $this->assertTrue($method->invoke($handler, 5000, '4K'));
        $this->assertFalse($method->invoke($handler, 4096, '4K'));
    }

    /**
     * A `skip` upload with nothing at its path writes a new file, so its key
     * must not already be on another one.
     */
    public function testASkipThatWritesANewFileStillChecksItsKey(): void
    {
        $this->upload(['filename' => 'a.png', 'folder' => 'first', 'externalId' => 'skip-key']);

        [$response] = $this->upload([
            'filename' => 'b.png',
            'folder' => 'second',
            'externalId' => 'skip-key',
            'conflict' => 'skip',
        ]);

        $this->assertErrorCode($response, 'ASSET_CONFLICT', 409);
        $this->assertSame(1, File::get()->filter('FixtureIdentifier', 'skip-key')->count());
    }

    /**
     * A folder whose stored name the current FolderNameFilter would change
     * (a legacy "v1.2") is still the folder an upload lands in and is found
     * in, as Folder::find_or_make() resolves it.
     */
    public function testAnExistingFolderKeepsItsStoredName(): void
    {
        // Folder::onBeforeWrite() filters the name now, so set the legacy
        // one directly.
        $legacy = Folder::find_or_make('v1-2');
        SQLUpdate::create('"File"', ['"Name"' => 'v1.2', '"Title"' => 'v1.2'], ['"ID"' => $legacy->ID])->execute();
        $this->assertSame('v1.2/', Folder::get()->byID($legacy->ID)->getFilename(), 'fixture keeps the raw name');

        [, $first] = $this->upload(['filename' => 'doc.png', 'folder' => 'v1.2']);
        [$response, $second] = $this->upload(['filename' => 'doc.png', 'folder' => 'v1.2']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertTrue($second['data']['existed']);
        $this->assertSame($first['data']['id'], $second['data']['id']);
        $this->assertSame((int) $legacy->ID, (int) File::get()->byID($first['data']['id'])->ParentID);
        $this->assertSame(0, Folder::get()->filter('Name', 'v1-2')->count(), 'no stray filtered folder');
    }
}
