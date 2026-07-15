<?php

namespace Dynamic\ContentApi\Tests\Control;

use Colymba\RESTfulAPI\QueryHandlers\DefaultQueryHandler;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPolyObject;
use Dynamic\ContentApi\Write\WriteGuardExtension;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;

/**
 * #23: colymba's native /api/$Model surface has no concept of this
 * module's `{"class","id"}` has_one payload shape or the companion
 * `{Name}Class` column — WriteGuardExtension::onBeforeDeserialize()
 * translates it into the raw columns colymba already knows how to write.
 */
class WriteGuardPolymorphicTest extends ContentApiTestCase
{
    protected static $required_extensions = [
        ApiTestPolyObject::class => [WriteGuardExtension::class],
    ];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->mintTokenFor('adminUser');

        Config::modify()->set(DefaultQueryHandler::class, 'models', [
            'ApiTest' => ApiTestObject::class,
            'Poly' => ApiTestPolyObject::class,
        ]);
        Config::modify()->set(ApiTestPolyObject::class, 'api_access', 'GET,POST,PUT,DELETE');
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

    public function testPolymorphicHasOneWritesThroughNativeSurface(): void
    {
        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $poly = ApiTestPolyObject::create(['Title' => 'Native poly']);
        $poly->write();

        $response = $this->colymba('PUT', "Poly/{$poly->ID}", [
            'Owner' => ['class' => 'ApiTest', 'id' => (int) $owner->ID],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestPolyObject::get()->byID($poly->ID);
        $this->assertSame((int) $owner->ID, (int) $fresh->OwnerID, 'FK column set via the native surface');
        $this->assertSame(
            ApiTestObject::class,
            $fresh->OwnerClass,
            'companion Class column set via the native surface'
        );
    }

    public function testPolymorphicHasOneWithoutClassHintIsRejectedNotCrashed(): void
    {
        // Matches the module's own write path: a bare {"id": n} is
        // ambiguous for a polymorphic has_one and must be rejected, not
        // reach colymba's deserializer with a nonsensical raw value.
        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $poly = ApiTestPolyObject::create(['Title' => 'Native poly']);
        $poly->write();

        $response = $this->colymba('PUT', "Poly/{$poly->ID}", [
            'Owner' => ['id' => (int) $owner->ID],
        ]);

        $this->assertSame(400, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testAsymmetricClassColumnProtectionRevertsBothColumnsNotJustOne(): void
    {
        // #25 applies here too, but the FK and companion Class column must
        // revert TOGETHER — protecting OwnerClass specifically while Owner
        // itself is allowed must not leave a torn state where OwnerID
        // points at a real record but OwnerClass doesn't name it (a
        // dangling polymorphic FK), which is worse than just rejecting the
        // whole relation write.
        Config::modify()->set(ApiTestPolyObject::class, 'api_writable_fields', ['Title', 'Owner']);
        Config::modify()->set(ApiTestPolyObject::class, 'api_protected_fields', ['OwnerClass']);

        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $poly = ApiTestPolyObject::create(['Title' => 'Protected class column']);
        $poly->write();
        $originalOwnerId = (int) $poly->OwnerID;

        $response = $this->colymba('PUT', "Poly/{$poly->ID}", [
            'Owner' => ['class' => 'ApiTest', 'id' => (int) $owner->ID],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestPolyObject::get()->byID($poly->ID);
        $this->assertSame(
            $originalOwnerId,
            (int) $fresh->OwnerID,
            'FK reverted too: OwnerClass is protected, so the pair must not split'
        );
        $this->assertNotSame(ApiTestObject::class, $fresh->OwnerClass);
    }

    public function testFkOnlyRepointIsNotDroppedWhenClassColumnIsProtectedButUntouched(): void
    {
        // A request that only sends OwnerID (repointing an already-typed
        // relation to a different record, without resending the class)
        // must not be blocked just because the untouched OwnerClass column
        // happens to be protected — the FK/Class pairing only applies when
        // a request actually writes both columns together.
        Config::modify()->set(ApiTestPolyObject::class, 'api_writable_fields', ['Title', 'Owner']);
        Config::modify()->set(ApiTestPolyObject::class, 'api_protected_fields', ['OwnerClass']);

        $firstOwner = $this->objFromFixture(ApiTestObject::class, 'one');
        $secondOwner = $this->objFromFixture(ApiTestObject::class, 'two');

        $poly = ApiTestPolyObject::create(['Title' => 'Already typed']);
        $poly->setField('OwnerID', $firstOwner->ID);
        $poly->setField('OwnerClass', ApiTestObject::class);
        $poly->write();

        $response = $this->colymba('PUT', "Poly/{$poly->ID}", [
            'OwnerID' => (int) $secondOwner->ID,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestPolyObject::get()->byID($poly->ID);
        $this->assertSame(
            (int) $secondOwner->ID,
            (int) $fresh->OwnerID,
            'FK-only repoint must not be dropped just because the untouched Class column is protected'
        );
    }

    public function testPolymorphicHasOneFullyDeniedRevertsBothColumns(): void
    {
        Config::modify()->set(ApiTestPolyObject::class, 'api_writable_fields', ['Title']);

        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $poly = ApiTestPolyObject::create(['Title' => 'Denied poly']);
        $poly->write();
        $originalOwnerId = (int) $poly->OwnerID;

        $response = $this->colymba('PUT', "Poly/{$poly->ID}", [
            'Owner' => ['class' => 'ApiTest', 'id' => (int) $owner->ID],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestPolyObject::get()->byID($poly->ID);
        $this->assertSame($originalOwnerId, (int) $fresh->OwnerID, 'FK reverted: Owner not in the allowlist');
        $this->assertNotSame(ApiTestObject::class, $fresh->OwnerClass, 'Class column reverted too');
    }
}
