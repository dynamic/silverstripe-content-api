<?php

namespace Dynamic\ContentApi\Tests;

use Dynamic\ContentApi\Auth\TokenAuthenticator;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\Member;

/**
 * Shared plumbing for content API functional tests: fixture, registry
 * config and token helpers.
 */
abstract class ContentApiTestCase extends FunctionalTest
{
    // Resolved relative to the concrete test class (tests/Control/).
    protected static $fixture_file = '../fixtures/api-test.yml';

    protected static $extra_dataobjects = [
        ApiTestObject::class,
        ApiTestVersionedObject::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ClassRegistry::class, 'models', [
            'ApiTest' => ApiTestObject::class,
            'ApiTestVersioned' => ApiTestVersionedObject::class,
        ]);

        // Explicit here rather than as private statics on the stubs: TestOnly
        // classes in vendored module runs aren't reliably in the config manifest.
        Config::modify()->set(ApiTestObject::class, 'api_access', true);
        Config::modify()->set(ApiTestVersionedObject::class, 'api_access', true);
    }

    protected function mintTokenFor(string $fixtureName = 'apiUser'): string
    {
        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, $fixtureName);

        return TokenAuthenticator::singleton()->mintToken($member);
    }

    protected function apiGet(string $path, ?string $token = null): HTTPResponse
    {
        $headers = $token ? ['X-Silverstripe-Apitoken' => $token] : [];

        return $this->get("content-api/v1/{$path}", null, $headers);
    }

    protected function apiPost(string $path, array $body = [], ?string $token = null): HTTPResponse
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($token) {
            $headers['X-Silverstripe-Apitoken'] = $token;
        }

        return $this->post(
            "content-api/v1/{$path}",
            [],
            $headers,
            null,
            json_encode($body)
        );
    }

    protected function decode(HTTPResponse $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        $this->assertIsArray(
            $decoded,
            'Response body is not valid JSON: ' . substr((string) $response->getBody(), 0, 500)
        );

        return $decoded;
    }

    protected function assertErrorCode(HTTPResponse $response, string $code, int $status): array
    {
        $body = $this->decode($response);

        $this->assertSame($status, $response->getStatusCode(), 'Unexpected HTTP status. Body: ' . $response->getBody());
        $this->assertNotNull($body['error'], 'Expected an error envelope.');
        $this->assertSame($code, $body['error']['code']);

        return $body;
    }
}
