<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Assets\AssetService;
use Dynamic\ContentApi\Control\Handlers\AssetHandler;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use ReflectionMethod;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Core\Config\Config;

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

        $cases = [UPLOAD_ERR_INI_SIZE => 'PAYLOAD_TOO_LARGE', UPLOAD_ERR_PARTIAL => 'PAYLOAD_INVALID'];

        foreach ($cases as $code => $expected) {
            try {
                $method->invoke($handler, ['error' => $code, 'size' => 0, 'tmp_name' => '']);
                $this->fail('expected an ApiError for upload error ' . $code);
            } catch (ApiError $error) {
                $this->assertSame($expected, $error->toArray()['code']);
            }
        }

        $method->invoke($handler, ['error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => '/tmp/x']);
        $this->addToAssertionCount(1);
    }
}
