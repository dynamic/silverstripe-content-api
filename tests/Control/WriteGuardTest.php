<?php

namespace Dynamic\ContentApi\Tests\Control;

use Colymba\RESTfulAPI\QueryHandlers\DefaultQueryHandler;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestCascadeObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestTag;
use Dynamic\ContentApi\Write\WriteGuardExtension;
use DNADesign\Elemental\Models\ElementalArea;
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
        ApiTestElement::class => [WriteGuardExtension::class],
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

    /**
     * colymba's deserializer writes every payload key straight to the
     * model — `WriteGuardExtension::onBeforeWrite()` only ever answered
     * "may this field change at all," never "is the new value valid," so
     * an out-of-list Enum value reached this surface untouched even after
     * `WriteApplicator::applyFields()` learned to reject one on the
     * batch/composition path (that method is never called for a colymba
     * write at all). `WriteGuardExtension` now carries an
     * `isEnumValueAcceptable()` revert for exactly this gap (merged up
     * from branch `1`, #191/#205) — but on THIS branch it's structurally
     * dead code for every Enum value, not merely unreachable in this one
     * test's scenario: SilverStripe 6's `DBEnum` declares a
     * `field_validators` entry (`OptionFieldValidator`, new in this major
     * version), and `DataObject::validate()`/`preWrite()` runs that
     * validation and throws before `onBeforeWrite()` is ever called at
     * all — not a colymba version difference (colymba's own deserializer
     * does no value validation itself on either branch; verified by
     * tracing `DefaultQueryHandler`'s write path, which catches the
     * `ValidationException` `$model->write()` throws and maps it to this
     * 400). Confirmed live on this branch's stack. Asserting the
     * revert-to-200 behavior branch `1`'s otherwise-identical test
     * expects would be asserting something this branch's own SS6
     * framework makes unreachable. Either shape satisfies the actual
     * goal here (reject or revert, never silently coerce); this branch's
     * is arguably stronger, since nothing is written at all.
     */
    public function testPutRejectsAnOutOfListEnumValue(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $priorStatus = $record->Status;

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Status' => 'Published',
        ]);

        $this->assertSame(400, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestObject::get()->byID($record->ID);
        $this->assertSame(
            $priorStatus,
            $fresh->Status,
            'out-of-list enum value must never be written, not silently coerced'
        );
    }

    public function testPutAcceptsAValidEnumValueOnColymba(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Status' => 'published',
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('published', ApiTestObject::get()->byID($record->ID)->Status);
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

        // Payload names Marker (denied) — the lead's own Marker reverts, but
        // the follower's cascade bump (15 -> 20) during the request must
        // survive: the follower is a different owner object, so it is not in
        // the payload WeakMap and the guard skips it.
        $response = $this->colymba('PUT', "Cascade/{$lead->ID}", [
            'Title' => 'Lead updated',
            'Marker' => 999,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(
            20,
            (int) ApiTestCascadeObject::get()->byID($follower->ID)->Marker,
            'follower cascade bump persisted — not reverted by the lead payload policy'
        );
        $this->assertSame(
            0,
            (int) ApiTestCascadeObject::get()->byID($lead->ID)->Marker,
            'the lead\'s own denied Marker was reverted'
        );
    }

    public function testGlobalAllowlistPolicyIsEnforced(): void
    {
        // A project-wide WriteApplicator.policy: allowlist (no per-class
        // config) must also gate colymba writes — parity with WriteApplicator.
        Config::modify()->set(
            \Dynamic\ContentApi\Write\WriteApplicator::class,
            'policy',
            'allowlist'
        );
        Config::modify()->set(ApiTestObject::class, 'api_writable_fields', ['Title']);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Title' => 'Allowed',
            'Rank' => 77,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, (int) ApiTestObject::get()->byID($record->ID)->Rank, 'global allowlist gated Rank');
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

    public function testParentIdStaysReadonlyOnColymbaSurface(): void
    {
        // Module #27, security half: ParentID is server-derived and written
        // internally by CompositionService via WriteApplicator's trusted
        // $internalFields channel (see CompositionTest for the positive
        // case) — it must never need adding to api_writable_fields, and
        // must stay non-writable on the untrusted colymba PUT surface even
        // though the composition path can set it.
        Config::modify()->set(
            DefaultQueryHandler::class,
            'models',
            ['ApiTest' => ApiTestObject::class, 'ApiTestElement' => ApiTestElement::class]
        );
        Config::modify()->set(ApiTestElement::class, 'api_access', 'GET,POST,PUT,DELETE');
        Config::modify()->set(ApiTestElement::class, 'api_writable_fields', ['Title']);

        $area = ElementalArea::create();
        $area->write();

        $otherArea = ElementalArea::create();
        $otherArea->write();

        $element = ApiTestElement::create();
        $element->Title = 'Existing';
        $element->ParentID = $area->ID;
        $element->write();
        $element->publishSingle();

        // Generic /api is stage-unaware and (outside an authenticated CMS
        // session) resolves to LIVE, same as any other front-end request —
        // publish so colymba's PUT can find the record at all.
        $response = $this->colymba('PUT', "ApiTestElement/{$element->ID}", [
            'Title' => 'Renamed via colymba',
            'ParentID' => $otherArea->ID,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestElement::get()->byID($element->ID);
        $this->assertSame('Renamed via colymba', $fresh->Title, 'allowlisted field applied');
        $this->assertSame(
            (int) $area->ID,
            (int) $fresh->ParentID,
            'ParentID reverted — the trusted internal channel does not leak onto the public PUT surface'
        );
    }
}
