<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;

class AssetsTest extends ContentApiTestCase
{
    /**
     * 1x1 transparent PNG.
     */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8'
        . 'z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        TestAssetStore::activate('ContentApiAssetsTest');

        $this->adminToken = $this->mintTokenFor('adminUser');

        Config::modify()->set(File::class, 'api_access', 'read,create');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();

        parent::tearDown();
    }

    private function uploadPixel(array $overrides = [], ?string $token = null): array
    {
        $response = $this->apiPost('assets', array_merge([
            'filename' => 'pixel.png',
            'folder' => 'api-test',
            'base64' => AssetsTest::PNG_BASE64,
            'externalId' => 'img-pixel',
        ], $overrides), $token ?? $this->adminToken);

        return [$response, $this->decode($response)];
    }

    public function testUploadCreatesImage(): void
    {
        [$response, $body] = $this->uploadPixel();

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('created', $body['meta']['operation']);
        $this->assertFalse($body['data']['existed']);
        $this->assertSame(Image::class, $body['data']['className']);
        $this->assertSame('api-test/pixel.png', $body['data']['filename']);
        $this->assertSame('img-pixel', $body['data']['externalId']);
        $this->assertSame(sha1(base64_decode(AssetsTest::PNG_BASE64)), $body['data']['hash']);
        $this->assertNotEmpty($body['data']['url']);
        $this->assertTrue($body['data']['stage']['live'], 'publish defaults to true');
    }

    public function testUploadIsIdempotentOnIdenticalContent(): void
    {
        [, $first] = $this->uploadPixel();
        [$response, $second] = $this->uploadPixel();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($second['data']['existed']);
        // The full record still comes back — the populate populateFile()
        // bare-true bug is exactly what this asserts against.
        $this->assertSame($first['data']['id'], $second['data']['id']);
        $this->assertSame($first['data']['hash'], $second['data']['hash']);
    }

    public function testOverwriteReplacesContent(): void
    {
        [, $first] = $this->uploadPixel();

        $newContent = base64_encode('not really a png');
        [$response, $second] = $this->uploadPixel(['base64' => $newContent]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($second['data']['existed']);
        $this->assertSame($first['data']['id'], $second['data']['id']);
        $this->assertSame(sha1('not really a png'), $second['data']['hash']);
    }

    public function testConflictSkipKeepsExistingContent(): void
    {
        [, $first] = $this->uploadPixel();

        [$response, $second] = $this->uploadPixel([
            'base64' => base64_encode('different'),
            'conflict' => 'skip',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($second['data']['existed']);
        $this->assertSame($first['data']['hash'], $second['data']['hash'], 'skip must not replace content');
    }

    public function testConflictRenameCreatesSibling(): void
    {
        [, $first] = $this->uploadPixel();

        [$response, $second] = $this->uploadPixel([
            'base64' => base64_encode('different'),
            'conflict' => 'rename',
            'externalId' => 'img-pixel-2',
        ]);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFalse($second['data']['existed']);
        $this->assertNotSame($first['data']['id'], $second['data']['id']);
        $this->assertNotSame($first['data']['filename'], $second['data']['filename']);
    }

    public function testUploadWithoutPublishStaysDraft(): void
    {
        [, $body] = $this->uploadPixel(['publish' => false]);

        $this->assertFalse($body['data']['stage']['live']);
    }

    public function testUploadRequiresPopulatePermission(): void
    {
        [$response] = $this->uploadPixel([], $this->mintTokenFor('apiUser'));

        $this->assertErrorCode($response, 'FORBIDDEN', 403);
    }

    public function testUploadIsEnvironmentGated(): void
    {
        Config::modify()->set(EnvironmentGate::class, 'population_enabled_environments', []);

        [$response] = $this->uploadPixel();

        $this->assertErrorCode($response, 'ENV_FORBIDDEN', 403);
    }

    public function testUploadPayloadValidation(): void
    {
        [$response] = $this->uploadPixel(['base64' => '%%%not-base64%%%']);
        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);

        $response = $this->apiPost('assets', ['filename' => 'x.png'], $this->adminToken);
        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);

        [$response] = $this->uploadPixel(['folder' => '../escape']);
        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);

        [$response] = $this->uploadPixel(['filename' => '']);
        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);

        [$response] = $this->uploadPixel(['conflict' => 'explode']);
        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testAssetRead(): void
    {
        [, $uploaded] = $this->uploadPixel();
        $id = $uploaded['data']['id'];

        $byId = $this->decode($this->apiGet("assets/{$id}", $this->adminToken));
        $this->assertSame('api-test/pixel.png', $byId['data']['filename']);
        $this->assertNotEmpty($byId['data']['url']);

        $byExt = $this->decode($this->apiGet('assets/ext:img-pixel', $this->adminToken));
        $this->assertSame($id, $byExt['data']['id']);
    }

    public function testAssetReadRequiresFileReadAccess(): void
    {
        // Upload while access is granted, then revoke before reading.
        [, $uploaded] = $this->uploadPixel();

        Config::modify()->set(File::class, 'api_access', false);

        $response = $this->apiGet("assets/{$uploaded['data']['id']}", $this->adminToken);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    public function testUploadRequiresCreateAccess(): void
    {
        // File granted read only — no create, and Image is unmapped/ungranted,
        // so the ancestry walk lands on File, which lacks the create verb.
        Config::modify()->set(File::class, 'api_access', 'read');

        [$response] = $this->uploadPixel();

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    public function testUploadClassCheckUsesNormalizedFilename(): void
    {
        // File permits create, but Image is explicitly restricted to read. A
        // trailing-space filename must still resolve to Image (the class
        // actually written) so the create check is gated on Image, not the
        // permissive File fallback.
        Config::modify()->set(File::class, 'api_access', 'read,create');
        Config::modify()->set(Image::class, 'api_access', 'read');

        [$response] = $this->uploadPixel(['filename' => 'pixel.png ']);

        $this->assertErrorCode($response, 'FORBIDDEN_CLASS', 403);
    }

    public function testUploadRespectsSubclassGrant(): void
    {
        // File carries no access at all; a create grant on the concrete Image
        // subclass alone must satisfy the check (the README quick-start pattern).
        Config::modify()->set(File::class, 'api_access', false);
        Config::modify()->set(Image::class, 'api_access', 'read,create');

        [$response, $body] = $this->uploadPixel();

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(Image::class, $body['data']['className']);
    }

    public function testAssetReadRespectsSubclassGrant(): void
    {
        Config::modify()->set(Image::class, 'api_access', 'read,create');

        [, $uploaded] = $this->uploadPixel();

        // File ungranted; only Image (the record's actual class) grants read.
        Config::modify()->set(File::class, 'api_access', false);

        $body = $this->decode($this->apiGet("assets/{$uploaded['data']['id']}", $this->adminToken));

        $this->assertSame($uploaded['data']['id'], $body['data']['id']);
    }
}
