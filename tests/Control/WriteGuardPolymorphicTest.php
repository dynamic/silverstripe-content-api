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

    public function testPolymorphicClassColumnIsIndependentlyGatedOnNativeSurface(): void
    {
        // #25 applies here too: protecting OwnerClass specifically must
        // revert it even though Owner (and so OwnerID) is allowed and
        // colymba's deserializer already wrote both raw columns directly.
        Config::modify()->set(ApiTestPolyObject::class, 'api_writable_fields', ['Title', 'Owner']);
        Config::modify()->set(ApiTestPolyObject::class, 'api_protected_fields', ['OwnerClass']);

        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $poly = ApiTestPolyObject::create(['Title' => 'Protected class column']);
        $poly->write();

        $response = $this->colymba('PUT', "Poly/{$poly->ID}", [
            'Owner' => ['class' => 'ApiTest', 'id' => (int) $owner->ID],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestPolyObject::get()->byID($poly->ID);
        $this->assertSame((int) $owner->ID, (int) $fresh->OwnerID, 'FK column: Owner is allowed');
        $this->assertNotSame(
            ApiTestObject::class,
            $fresh->OwnerClass,
            'companion Class column: protected, must be reverted even though the FK write was allowed'
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
