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

    public function testBareClassColumnWithNoPairedFkIsAlwaysRejectedRegardlessOfAllowlist(): void
    {
        // A client sending only "OwnerClass" directly — with no "Owner" or
        // "OwnerID" key at all, so translatePolymorphicHasOnes() never
        // touches this relation — must never be able to set an arbitrary,
        // unvalidated class string, even if "Owner" is fully allowlisted.
        // Otherwise it resolves to the relation's own allowlist entry and
        // is judged writable with none of resolveRelation()'s
        // ClassRegistry validation ever having run.
        Config::modify()->set(ApiTestPolyObject::class, 'api_writable_fields', ['Title', 'Owner']);

        $poly = ApiTestPolyObject::create(['Title' => 'Bare class column']);
        $poly->write();
        // Reload rather than trust the in-memory object: OwnerClass is a
        // DBClassName (DBEnum), so SilverStripe assigns it some concrete
        // class from the enum's value set on write() rather than leaving
        // it as the pre-write in-memory value.
        $originalOwnerClass = ApiTestPolyObject::get()->byID($poly->ID)->OwnerClass;

        $response = $this->colymba('PUT', "Poly/{$poly->ID}", [
            'OwnerClass' => ApiTestObject::class,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestPolyObject::get()->byID($poly->ID);
        $this->assertSame(
            $originalOwnerClass,
            $fresh->OwnerClass,
            'a bare Class-column key must always be reverted, never independently writable'
        );
    }

    public function testRawIdAndClassColumnsSentTogetherRevertBothNotJustClass(): void
    {
        // A client sending both raw "OwnerID" and "OwnerClass" keys
        // directly — bypassing the wrapped "Owner": {"class","id"} shape
        // entirely — must not end up with the FK repointed while the
        // Class column silently stays stale: OwnerClass can never be
        // legitimately set this way, so applying only the FK half would
        // produce exactly the torn FK+Class state this mechanism exists
        // to prevent. Both must revert together.
        $owner = $this->objFromFixture(ApiTestObject::class, 'one');

        $poly = ApiTestPolyObject::create(['Title' => 'Raw pair together']);
        $poly->write();
        $original = ApiTestPolyObject::get()->byID($poly->ID);
        $originalOwnerId = (int) $original->OwnerID;
        $originalOwnerClass = $original->OwnerClass;

        $response = $this->colymba('PUT', "Poly/{$poly->ID}", [
            'OwnerID' => (int) $owner->ID,
            'OwnerClass' => ApiTestObject::class,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestPolyObject::get()->byID($poly->ID);
        $this->assertSame($originalOwnerId, (int) $fresh->OwnerID, 'FK must revert too, not just the Class column');
        $this->assertSame($originalOwnerClass, $fresh->OwnerClass);
    }

    public function testGetThenPutVerbatimEchoingUnchangedClassDoesNotDropTheFkRepoint(): void
    {
        // The routine GET-then-PUT-verbatim pattern: a naive client GETs a
        // record (which serializes OwnerID/OwnerClass as plain columns on
        // this native surface), changes only OwnerID, and PUTs the whole
        // body back — OwnerClass comes along unchanged. This must not be
        // treated as a same-request "raw pair" bypass attempt: OwnerClass
        // didn't actually change, so the legitimate FK repoint must apply.
        $firstOwner = $this->objFromFixture(ApiTestObject::class, 'one');
        $secondOwner = $this->objFromFixture(ApiTestObject::class, 'two');

        $poly = ApiTestPolyObject::create(['Title' => 'Verbatim echo']);
        $poly->setField('OwnerID', $firstOwner->ID);
        $poly->setField('OwnerClass', ApiTestObject::class);
        $poly->write();

        $response = $this->colymba('PUT', "Poly/{$poly->ID}", [
            'OwnerID' => (int) $secondOwner->ID,
            'OwnerClass' => ApiTestObject::class,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = ApiTestPolyObject::get()->byID($poly->ID);
        $this->assertSame(
            (int) $secondOwner->ID,
            (int) $fresh->OwnerID,
            'echoing back the unchanged Class column must not drop a legitimate FK repoint'
        );
        $this->assertSame(ApiTestObject::class, $fresh->OwnerClass);
    }

    public function testFullyDeniedRelationWithUnresolvableHintSilentlyRevertsInsteadOfErroring(): void
    {
        // #23 ordering fix: resolving a relation's class hint must not run
        // before its writability is checked — a denied relation with a
        // malformed/unresolvable hint must still silently no-op (matching
        // testPolymorphicHasOneFullyDeniedRevertsBothColumns), not surface
        // a hard validation error for a field the client can't write anyway.
        Config::modify()->set(ApiTestPolyObject::class, 'api_writable_fields', ['Title']);

        $poly = ApiTestPolyObject::create(['Title' => 'Denied with bad hint']);
        $poly->write();

        $response = $this->colymba('PUT', "Poly/{$poly->ID}", [
            'Owner' => ['class' => 'ApiTest', 'externalId' => 'does-not-exist'],
        ]);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'a denied relation must silently no-op even with an unresolvable hint, not 404/400: '
                . $response->getBody()
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
