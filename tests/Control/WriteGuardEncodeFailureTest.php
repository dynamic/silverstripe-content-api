<?php

namespace Dynamic\ContentApi\Write;

/**
 * Namespace-scoped override: an unqualified `json_encode(...)` call from
 * within WriteGuardExtension.php (same namespace) resolves to this function
 * before PHP falls back to the global one. Lets
 * WriteGuardEncodeFailureTest force an encode failure without needing
 * genuinely unencodable data — impossible here, since the guard's `$body`
 * always originates from a successful `json_decode()`, so it can never
 * contain anything `json_encode()` itself would reject.
 */
function json_encode($value, int $flags = 0, int $depth = 512): string|false
{
    if (\Dynamic\ContentApi\Tests\Control\WriteGuardEncodeFailureTest::$forceEncodeFailure) {
        return false;
    }

    return \json_encode($value, $flags, $depth);
}

namespace Dynamic\ContentApi\Tests\Control;

use Colymba\RESTfulAPI\QueryHandlers\DefaultQueryHandler;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Write\WriteGuardExtension;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;

class WriteGuardEncodeFailureTest extends ContentApiTestCase
{
    protected static $required_extensions = [
        ApiTestObject::class => [WriteGuardExtension::class],
    ];

    public static bool $forceEncodeFailure = false;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->mintTokenFor('adminUser');

        Config::modify()->set(DefaultQueryHandler::class, 'models', ['ApiTest' => ApiTestObject::class]);
        Config::modify()->set(ApiTestObject::class, 'api_access', 'GET,POST,PUT,DELETE');
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title']);
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', []);
    }

    protected function tearDown(): void
    {
        static::$forceEncodeFailure = false;

        parent::tearDown();
    }

    private function colymba(string $method, string $path, ?array $body = null): HTTPResponse
    {
        return $this->mainSession->sendRequest(
            $method,
            "api/{$path}",
            [],
            ['X-Silverstripe-Apitoken' => $this->token, 'Content-Type' => 'application/json'],
            null,
            $body === null ? null : json_encode($body)
        );
    }

    public function testEncodeFailureFailsLoudlyInsteadOfBypassingTheGuard(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        static::$forceEncodeFailure = true;

        // "Children" is present and unwritable (api_writable_relations is
        // empty), so the guard has something to strip — $filtered = true —
        // which is what reaches the json_encode() call this test forces
        // to fail.
        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Title' => 'Should not apply',
            'Children' => [],
        ]);

        // HTTPResponse_Exception is caught by SilverStripe's own
        // RequestHandler and turned into this response — a clean failure,
        // not an unhandled exception (#22).
        $this->assertSame(500, $response->getStatusCode());

        $fresh = ApiTestObject::get()->byID($record->ID);
        $this->assertNotSame(
            'Should not apply',
            $fresh->Title,
            'the write must not proceed against the unstripped, unguarded payload'
        );
    }
}
