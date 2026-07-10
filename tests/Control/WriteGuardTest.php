<?php

namespace Dynamic\ContentApi\Tests\Control;

use Colymba\RESTfulAPI\QueryHandlers\DefaultQueryHandler;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestTag;
use Dynamic\ContentApi\Write\WriteGuardExtension;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;

/**
 * Exercises colymba's generic /api write path with WriteGuardExtension
 * active — the productized ApiFieldGuardExtension that makes colymba's
 * apply-every-key writes field-safe.
 */
class WriteGuardTest extends ContentApiTestCase
{
    protected static $required_extensions = [
        ApiTestObject::class => [WriteGuardExtension::class],
    ];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->mintTokenFor('adminUser');

        // Expose the stub on colymba's surface.
        Config::modify()->set(DefaultQueryHandler::class, 'models', [
            'ApiTest' => ApiTestObject::class,
        ]);
        Config::modify()->set(ApiTestObject::class, 'api_access', 'GET,POST,PUT,DELETE');
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

    public function testUnauthenticatedColymbaRequestIsRejected(): void
    {
        $response = $this->mainSession->sendRequest('GET', 'api/ApiTest', []);

        $this->assertSame(403, $response->getStatusCode(), 'authentication_policy: true gates /api');
    }

    public function testColymbaReadWorks(): void
    {
        $response = $this->colymba('GET', 'ApiTest');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $records = json_decode((string) $response->getBody(), true);
        $this->assertNotEmpty($records);
        $this->assertArrayHasKey('Title', $records[0], 'colymba serializes verbatim PascalCase');
    }

    public function testPutRevertsDisallowedScalarInAllowlistMode(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title']);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Title' => 'Renamed via colymba',
            'Rank' => 99,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestObject::get()->byID($record->ID);
        $this->assertSame('Renamed via colymba', $fresh->Title, 'allowlisted field applied');
        $this->assertSame(1, (int) $fresh->Rank, 'disallowed field reverted');
    }

    public function testPostCreateRevertsDisallowedFields(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title']);

        $response = $this->colymba('POST', 'ApiTest', [
            'Title' => 'Created via colymba',
            'Rank' => 55,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $created = ApiTestObject::get()->filter('Title', 'Created via colymba')->first();
        $this->assertNotNull($created);
        $this->assertSame(0, (int) $created->Rank, 'disallowed field never persisted on create');
    }

    public function testGuardedModeProtectedFields(): void
    {
        // No allowlist — guarded mode: only protected fields revert.
        Config::modify()->set(ApiTestObject::class, 'api_protected_fields', ['Rank']);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Title' => 'Guarded rename',
            'Rank' => 77,
            'ApiToken' => 'stolen-token',
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestObject::get()->byID($record->ID);
        $this->assertSame('Guarded rename', $fresh->Title);
        $this->assertSame(1, (int) $fresh->Rank, 'per-class protected field reverted');
    }

    public function testEchoedUnlistedRelationsAreStripped(): void
    {
        // The GET-then-PUT-verbatim scenario: colymba applies present _many
        // keys via removeAll()+add(), so echoing an empty/partial relation
        // array would detach relations. The guard strips unlisted keys.
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title']);
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', []);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $record->Children()->add($this->objFromFixture(ApiTestChildObject::class, 'childOne'));
        $record->Tags()->add($this->objFromFixture(ApiTestTag::class, 'tagOne'));

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Title' => 'Echo update',
            'Children' => [],
            'Tags' => [],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestObject::get()->byID($record->ID);
        $this->assertSame(1, $fresh->Children()->count(), 'unlisted has_many survived the echo');
        $this->assertSame(1, $fresh->Tags()->count(), 'unlisted many_many survived the echo');
    }

    public function testWritableRelationStillApplies(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title', 'Tags']);
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', ['Tags']);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $tag = $this->objFromFixture(ApiTestTag::class, 'tagOne');

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Tags' => [(int) $tag->ID],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, $record->Tags()->count(), 'listed relation applied by colymba');
    }

    public function testCmsContextWriteIsUntouched(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title']);

        // Direct ORM write (no colymba controller): guard must not interfere.
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $record->Rank = 42;
        $record->write();

        $this->assertSame(42, (int) ApiTestObject::get()->byID($record->ID)->Rank);
    }
}
