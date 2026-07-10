<?php

namespace Dynamic\ContentApi\Tests\Control;

use Colymba\RESTfulAPI\QueryHandlers\DefaultQueryHandler;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestCascadeObject;
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

    public function testProtectedRelationByRelationName(): void
    {
        // Regression: a has_one listed in api_protected_fields by its RELATION
        // name ('Buddy') must be reverted even though the DB column is BuddyID.
        Config::modify()->set(ApiTestObject::class, 'api_protected_fields', ['Buddy']);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $other = $this->objFromFixture(ApiTestObject::class, 'two');

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Title' => 'Reparent attempt',
            'BuddyID' => (int) $other->ID,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(
            0,
            (int) ApiTestObject::get()->byID($record->ID)->BuddyID,
            'protected relation reverted despite being named by relation, not FK'
        );
    }

    public function testProtectedWinsInsideAllowlistMode(): void
    {
        // Regression: protected must win even when the field is in the
        // allowlist (parity with WriteApplicator, which checks protected
        // first). ApiToken in the allowlist must NOT be writable.
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title', 'Rank']);
        Config::modify()->set(ApiTestObject::class, 'api_protected_fields', ['Rank']);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Title' => 'Allowed',
            'Rank' => 88,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestObject::get()->byID($record->ID);
        $this->assertSame('Allowed', $fresh->Title);
        $this->assertSame(1, (int) $fresh->Rank, 'allowlisted-but-protected field still reverted');
    }

    public function testCascadeWritesAreNotGuardedByTargetPolicy(): void
    {
        // Regression for #8: a guarded record written during a colymba request
        // that is NOT the deserialized target (apiRequestBody === null) must be
        // left alone — the old request-body fallback applied the target's field
        // policy to it and reverted legitimate changes. Marker is denied in the
        // lead's payload; the follower (guarded, never deserialized) must keep
        // its value through the request.
        Config::modify()->set(ApiTestCascadeObject::class, 'api_writable_fields', ['Title']);
        Config::modify()->set(
            \Colymba\RESTfulAPI\QueryHandlers\DefaultQueryHandler::class,
            'models',
            ['ApiTest' => ApiTestObject::class, 'Cascade' => ApiTestCascadeObject::class]
        );
        Config::modify()->set(ApiTestCascadeObject::class, 'api_access', 'GET,POST,PUT,DELETE');

        $lead = ApiTestCascadeObject::create();
        $lead->Title = 'Lead';
        $lead->IsLead = true;
        $lead->write();

        $follower = ApiTestCascadeObject::create();
        $follower->Title = 'Follower';
        $follower->Marker = 10;
        $follower->write();

        // Control: the cascade mechanism fires on a plain write (no colymba).
        $lead->Title = 'Lead direct';
        $lead->write();
        $this->assertSame(
            15,
            (int) ApiTestCascadeObject::get()->byID($follower->ID)->Marker,
            'precondition: onAfterWrite cascade bumps the follower on a direct write'
        );

        // Payload names Marker (denied) — the lead's own Marker would revert,
        // but the follower's cascade bump must survive.
        $response = $this->colymba('PUT', "Cascade/{$lead->ID}", [
            'Title' => 'Lead updated',
            'Marker' => 999,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(
            15,
            (int) ApiTestCascadeObject::get()->byID($follower->ID)->Marker,
            'follower cascade bump (10 + 5) persisted — not reverted by the lead payload policy'
        );
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
