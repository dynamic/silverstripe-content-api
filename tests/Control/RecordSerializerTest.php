<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestCascadeObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPolyObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestTag;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;

class RecordSerializerTest extends ContentApiTestCase
{
    private string $token;

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->mintTokenFor('adminUser');

        $this->logHandler = new TestHandler();
        Injector::inst()->registerService(
            new Logger('test', [$this->logHandler]),
            LoggerInterface::class
        );
    }

    public function testUnreadableRelationIsLoggedNotSilentlySwallowed(): void
    {
        // A has_many entry whose target class doesn't exist can't be read —
        // RecordSerializer must log it, not just return null (#22).
        Config::modify()->merge(ApiTestObject::class, 'has_many', [
            'Broken' => 'Dynamic\\ContentApi\\Tests\\Stub\\NonExistentTarget',
        ]);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiGet("records/ApiTest/{$record->ID}", $this->token));

        $this->assertArrayHasKey('Broken', $body['data']['relations']);
        $this->assertNull($body['data']['relations']['Broken']);
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains('Broken'),
            'expected a warning logged for the unreadable relation'
        );
    }

    public function testPolymorphicRelationWithUnregisteredClassOmitsClassKey(): void
    {
        $owner = ApiTestCascadeObject::create(['Title' => 'Owner']);
        $owner->write();

        $poly = ApiTestPolyObject::create(['Title' => 'Orphaned ref']);
        $poly->setField('OwnerID', $owner->ID);
        // ApiTestCascadeObject is deliberately not in the test ClassRegistry
        // models map (see ContentApiTestCase::setUp()) — refFor() returns
        // null for it.
        $poly->setField('OwnerClass', ApiTestCascadeObject::class);
        $poly->write();

        $body = $this->decode($this->apiGet("records/ApiTestPoly/{$poly->ID}", $this->token));

        $ownerRelation = $body['data']['relations']['Owner'];
        $this->assertSame($owner->ID, $ownerRelation['id']);
        $this->assertArrayNotHasKey(
            'class',
            $ownerRelation,
            'must not leak the internal FQCN of a class the registry never exposed'
        );
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains('unregistered'),
            'expected a warning logged for the unregistered polymorphic target class'
        );
    }

    public function testPolymorphicRelationWithNullIdDoesNotWarnOnStaleClassColumn(): void
    {
        // A write path that clears only the FK (e.g. colymba's native PUT,
        // which zeroes OwnerID but never touches OwnerClass) leaves a stale
        // Class value behind. The relation is genuinely unset from the
        // caller's perspective, so this must not fire the "unregistered
        // class" warning at all.
        $poly = ApiTestPolyObject::create(['Title' => 'Cleared ref']);
        $poly->setField('OwnerID', 0);
        $poly->setField('OwnerClass', ApiTestCascadeObject::class);
        $poly->write();

        $body = $this->decode($this->apiGet("records/ApiTestPoly/{$poly->ID}", $this->token));

        $this->assertNull($body['data']['relations']['Owner']);
        $this->assertFalse(
            $this->logHandler->hasWarningRecords(),
            'a cleared FK must not trigger a warning based on the stale Class column'
        );
    }

    public function testPolymorphicRelationWithIdButEmptyClassColumnWarns(): void
    {
        // A set FK with an empty companion Class column (a partial write, a
        // legacy import, an upstream bug) is exactly the kind of broken
        // relation this fix set out to make discoverable — it must not be
        // silently indistinguishable from a genuinely unregistered class.
        // OwnerClass is a DBClassName (DBEnum) column — SilverStripe assigns
        // it *some* concrete class from the enum's value set on a plain
        // write() rather than leaving it empty, and rejects an explicit ''
        // as an invalid enum value through the ORM. A raw SQL update is the
        // only way to reproduce a truly blank companion column, matching
        // how this could actually arise (a legacy import, a pre-migration
        // row written before the column existed).
        $poly = ApiTestPolyObject::create(['Title' => 'Half-written ref']);
        $poly->setField('OwnerID', 999);
        $poly->write();

        DB::prepared_query('UPDATE "ContentApi_ApiTestPolyObject" SET "OwnerClass" = \'\' WHERE "ID" = ?', [$poly->ID]);

        $body = $this->decode($this->apiGet("records/ApiTestPoly/{$poly->ID}", $this->token));

        $ownerRelation = $body['data']['relations']['Owner'];
        $this->assertSame(999, $ownerRelation['id']);
        $this->assertArrayNotHasKey('class', $ownerRelation);
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains('no companion'),
            'expected a warning logged when the id is set but the Class column is empty'
        );
    }

    /**
     * Regression: a many_many relation declaring many_many_extraFields
     * (e.g. SortOrder) used to serialize as a bare id array, silently
     * dropping the extraFields data — WriteApplicator can write it
     * ({"id", "extraFields"} per item, see resolveRelationItem()), but a
     * GET->PUT round-trip could never see it come back.
     */
    public function testManyManyExtraFieldsRoundTripOnRead(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $tagOne = $this->objFromFixture(ApiTestTag::class, 'tagOne');
        $tagTwo = $this->objFromFixture(ApiTestTag::class, 'tagTwo');

        $record->Tags()->add($tagOne, ['SortOrder' => 3]);
        $record->Tags()->add($tagTwo, ['SortOrder' => 1]);

        $body = $this->decode($this->apiGet("records/ApiTest/{$record->ID}", $this->token));

        $tags = $body['data']['relations']['Tags'];
        $this->assertCount(2, $tags);

        $byId = [];
        foreach ($tags as $tag) {
            $this->assertArrayHasKey('id', $tag);
            $this->assertArrayHasKey('extraFields', $tag);
            $byId[$tag['id']] = $tag['extraFields'];
        }

        $this->assertSame(['SortOrder' => 3], $byId[$tagOne->ID]);
        $this->assertSame(['SortOrder' => 1], $byId[$tagTwo->ID]);
    }

    /**
     * A has_many (or a many_many with no declared extraFields) must keep
     * the existing bare-id-array shape — the {"id","extraFields"} shape is
     * only for relations that actually have extra join-table data.
     */
    public function testRelationWithoutExtraFieldsStaysABareIdArray(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $child = ApiTestChildObject::create(['Title' => 'A child']);
        $child->write();
        $child->setField('ParentID', $record->ID);
        $child->write();

        $body = $this->decode($this->apiGet("records/ApiTest/{$record->ID}", $this->token));

        $this->assertSame([(int) $child->ID], $body['data']['relations']['Children']);
    }

    public function testUnreadableRelationWarningIsDedupedAcrossRecordsInAListRead(): void
    {
        // Every ApiTest fixture record shares the same broken has_many
        // config — pre-#22-follow-up this would log once per record on
        // every list page instead of once per broken relation shape.
        Config::modify()->merge(ApiTestObject::class, 'has_many', [
            'Broken' => 'Dynamic\\ContentApi\\Tests\\Stub\\NonExistentTarget',
        ]);

        $body = $this->decode($this->apiGet('records/ApiTest', $this->token));
        $this->assertGreaterThan(1, count($body['data']), 'precondition: list read returns multiple records');

        $brokenWarnings = array_filter(
            $this->logHandler->getRecords(),
            fn ($record) => str_contains($record['message'], 'Broken')
        );

        $this->assertCount(
            1,
            $brokenWarnings,
            'the same broken-relation shape must warn once per request, not once per record'
        );
    }
}
