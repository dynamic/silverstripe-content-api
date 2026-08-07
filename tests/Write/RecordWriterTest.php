<?php

namespace Dynamic\ContentApi\Tests\Write;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Write\RecordWriter;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;

/**
 * Direct coverage for #127's rollback pre-image capture in
 * `RecordWriter::write()` — a code-review pass on the initial #127 patch
 * caught two capture-side bugs neither the reflection-level
 * `BatchProcessorRollbackVerificationTest` nor the end-to-end `BatchTest`
 * cases could reach, because both feed `verifyRollback()` an
 * already-correct `$preImages` array rather than exercising the code that
 * actually produces one. This file targets that production step instead.
 */
class RecordWriterTest extends ContentApiTestCase
{
    private function writer(): RecordWriter
    {
        return Injector::inst()->get(RecordWriter::class);
    }

    private function member(): Member
    {
        return $this->objFromFixture(Member::class, 'apiUser');
    }

    public function testPreImageCapturesAPlainFieldByItsDeclaredKey(): void
    {
        $record = ApiTestObject::create(['Title' => 'Original title']);
        $record->write();

        $result = $this->writer()->update(
            $record,
            ['fields' => ['Title' => 'Would-be new title']],
            $this->member()
        );

        $this->assertSame(['Title' => 'Original title'], $result['preImage']);
    }

    /**
     * The bug this guards: `getField()` on a has_one relation *name*
     * resolves it to the related DataObject via `getComponent()`, and
     * `DataObject::__toString()` returns its class name, not any
     * per-record identity. Snapshotting that object (or its string cast)
     * would compare "same class?" instead of "same record?" — reporting
     * a rollback verified for ANY two records of the same class,
     * regardless of which one is actually linked. The pre-image must
     * resolve to the raw `BuddyID` column instead.
     */
    public function testPreImageOfAHasOneFieldCapturesTheRawForeignKeyNotTheRelatedObject(): void
    {
        $buddyA = ApiTestObject::create(['Title' => 'Buddy A']);
        $buddyA->write();
        $buddyB = ApiTestObject::create(['Title' => 'Buddy B']);
        $buddyB->write();

        $record = ApiTestObject::create(['Title' => 'Has a buddy']);
        $record->BuddyID = $buddyA->ID;
        $record->write();

        $result = $this->writer()->update(
            $record,
            ['fields' => ['Buddy' => $buddyB->ID]],
            $this->member()
        );

        $this->assertSame(
            ['BuddyID' => (int) $buddyA->ID],
            $result['preImage'],
            'the pre-image must be the raw FK id, keyed by the actual DB column — never the related object'
        );
    }

    /**
     * Same underlying column-resolution rule from the other side: a
     * payload that addresses the relation by its raw `{Name}ID` key
     * directly (rather than the relation name) must resolve to the exact
     * same pre-image, since both keys write the same column.
     */
    public function testPreImageOfAHasOneFkColumnKeyMatchesTheRelationNameKey(): void
    {
        $buddyA = ApiTestObject::create(['Title' => 'Buddy A']);
        $buddyA->write();
        $buddyB = ApiTestObject::create(['Title' => 'Buddy B']);
        $buddyB->write();

        $record = ApiTestObject::create(['Title' => 'Has a buddy']);
        $record->BuddyID = $buddyA->ID;
        $record->write();

        $result = $this->writer()->update(
            $record,
            ['fields' => ['BuddyID' => $buddyB->ID]],
            $this->member()
        );

        $this->assertSame(['BuddyID' => (int) $buddyA->ID], $result['preImage']);
    }

    /**
     * The bug this guards: `DataObject::getField()` on a composite field
     * (e.g. Money) returns a `DBComposite` that `DBComposite::bindTo()`
     * keeps live-bound to the exact `$record` instance it was read from.
     * Storing that object as the pre-image (rather than an eager scalar
     * snapshot taken immediately) would silently re-read `$record`'s
     * POST-write state the next time it's stringified — `applyFields()`
     * mutates the very same object the "pre-image" was captured from.
     */
    public function testPreImageOfACompositeFieldIsAnImmutableSnapshotNotALiveReference(): void
    {
        $record = ApiTestObject::create(['Title' => 'Has a price']);
        $record->setField('PriceAmount', 10.0);
        $record->setField('PriceCurrency', 'USD');
        $record->write();

        $result = $this->writer()->update(
            $record,
            ['fields' => ['Price' => ['Amount' => 20.0, 'Currency' => 'EUR']]],
            $this->member()
        );

        // $record has now been mutated in place by applyFields() as part
        // of the update() call above. A live-bound (buggy) pre-image would
        // reflect THAT state; an eager snapshot reflects the state before
        // it, captured the instant before the mutation happened.
        $this->assertIsString($result['preImage']['Price']);
        $this->assertStringContainsString('10', $result['preImage']['Price']);
        $this->assertStringNotContainsString('20', $result['preImage']['Price']);
    }

    public function testNoPreImageIsCapturedForACreate(): void
    {
        $result = $this->writer()->upsert(
            ApiTestObject::class,
            ['fields' => ['Title' => 'Brand new']],
            $this->member(),
            'create'
        );

        $this->assertArrayNotHasKey(
            'preImage',
            $result,
            'a created record has no prior state to compare against'
        );
    }
}
