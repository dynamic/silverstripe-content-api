<?php

namespace Dynamic\ContentApi\Tests\Write;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPolyObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use Dynamic\ContentApi\Write\RecordWriter;
use SilverStripe\Core\Config\Config;
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
     * (e.g. Money) returns a `DBComposite`, and its own value is NOT a
     * safe general proxy for its content — `DBComposite` never overrides
     * `DBField::getValue()`, and `DBComposite::setValue()` binds to the
     * parent record instead of populating the inherited `$value`
     * property, so the base `getValue()` (and any string cast of it)
     * reads as empty/null on every composite UNLESS that specific
     * subclass happens to override `getValue()` itself. A live-bound
     * object also stays tied to the exact `$record` instance it was read
     * from, so reading it again after `applyFields()` mutates that record
     * would silently return the POST-write state. The pre-image must
     * resolve to the field's real, always-scalar sub-columns instead
     * (`PriceAmount`/`PriceCurrency`), the same way a has_one resolves to
     * its raw FK column.
     */
    public function testPreImageOfACompositeFieldCapturesItsRawSubColumns(): void
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
        // reflect THAT state; an eager, per-sub-column snapshot reflects
        // the state before it, captured the instant before the mutation
        // happened.
        // assertEquals, not assertEqualsCanonicalizing: the latter's array
        // comparison sorts VALUES and discards keys entirely, so it would
        // pass even if the pre-image were wrongly keyed (e.g.
        // "AmountPrice"/"CurrencyPrice") as long as the same two values
        // showed up somewhere. assertEquals is key-aware but order-
        // insensitive (assertSame would fail on key order alone —
        // DBMoney::$composite_db declares Currency before Amount).
        $this->assertEquals(
            ['PriceAmount' => 10.0, 'PriceCurrency' => 'USD'],
            $result['preImage'],
            'a composite field must expand to its real, correctly-named sub-columns, never the DBComposite object'
        );
    }

    /**
     * The polymorphic-relation companion to the has_one test above. A
     * polymorphic has_one ("Owner" => DataObject::class) writes BOTH
     * "OwnerID" and "OwnerClass" for one payload key
     * (`WriteApplicator::applyFields()`) — a pre-image that only captures
     * the FK id would report "verified" even if a rollback left the
     * relation pointing at the right numeric id in the WRONG class's
     * table (e.g. reverted "5" but not "ApiTestChild" -> "ApiTest").
     */
    public function testPreImageOfAPolymorphicHasOneCapturesBothTheIdAndClassColumns(): void
    {
        $originalOwner = ApiTestObject::create(['Title' => 'Original owner']);
        $originalOwner->write();
        $newOwner = ApiTestChildObject::create(['Title' => 'New owner, different class']);
        $newOwner->write();

        $record = ApiTestPolyObject::create(['Title' => 'Has a polymorphic owner']);
        $record->OwnerID = $originalOwner->ID;
        $record->OwnerClass = ApiTestObject::class;
        $record->write();

        $result = $this->writer()->update(
            $record,
            ['fields' => ['Owner' => ['class' => 'ApiTestChild', 'id' => (int) $newOwner->ID]]],
            $this->member()
        );

        $this->assertSame(
            [
                'OwnerID' => (int) $originalOwner->ID,
                'OwnerClass' => ApiTestObject::class,
            ],
            $result['preImage'],
            'both the FK id and the companion class column must be captured for a polymorphic has_one'
        );
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

    /**
     * #114: `update`/`create` are independently configurable verbs from
     * `action` (`ClassRegistry::VERBS`) — a class granting `update` but
     * withholding `action` must not be able to publish its root record
     * via the payload's "publish" key, since `checkClassAccess()` was
     * previously only ever called with `update`/`create` here.
     */
    public function testWriteWithAPublishKeyRequiresTheActionVerb(): void
    {
        Config::modify()->set(ApiTestVersionedObject::class, 'api_access', 'read,update');

        $record = ApiTestVersionedObject::create(['Title' => 'Needs action to publish']);
        $record->write();

        try {
            $this->writer()->update(
                $record,
                ['fields' => ['Title' => 'Updated'], 'publish' => 'single'],
                $this->member()
            );
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertSame('FORBIDDEN_CLASS', $error->toArray()['code']);
        }
    }

    /**
     * Same narrowed class, no "publish" key at all (the default, "none")
     * — must stay reachable on "update" alone, matching
     * PublishOrchestrator::publish()'s own no-op for that mode.
     */
    public function testWriteWithoutAPublishKeyDoesNotRequireTheActionVerb(): void
    {
        Config::modify()->set(ApiTestVersionedObject::class, 'api_access', 'read,update');

        $record = ApiTestVersionedObject::create(['Title' => 'No publish key']);
        $record->write();

        $result = $this->writer()->update(
            $record,
            ['fields' => ['Title' => 'Updated without publishing']],
            $this->member()
        );

        $this->assertSame('Updated without publishing', $result['record']->Title);
    }

    /**
     * The positive half: granting `action` alongside `update` (the
     * default full access every other test in this file relies on) lets
     * a payload "publish" key succeed.
     */
    public function testWriteWithAPublishKeySucceedsWhenActionVerbIsGranted(): void
    {
        $record = ApiTestVersionedObject::create(['Title' => 'Should publish']);
        $record->write();

        $this->writer()->update(
            $record,
            ['fields' => ['Title' => 'Published'], 'publish' => 'single'],
            $this->member()
        );

        $this->assertTrue($record->isPublished());
    }

    /**
     * A "publish" key on a non-versioned class must NOT require `action`
     * — PublishOrchestrator::publish() itself is already a no-op for a
     * non-versioned record, so a class that can never actually be
     * published has nothing here for `action` to gate. Narrows
     * ApiTestObject's own api_access (normally `true`, i.e. every verb)
     * to prove this — if `action` were required, this write would 403
     * even though nothing was ever going to publish.
     */
    public function testWriteWithAPublishKeyOnANonVersionedClassDoesNotRequireTheActionVerb(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', 'read,update');

        $record = ApiTestObject::create(['Title' => 'Not versioned']);
        $record->write();

        $result = $this->writer()->update(
            $record,
            ['fields' => ['Title' => 'Still not versioned'], 'publish' => 'single'],
            $this->member()
        );

        $this->assertSame('Still not versioned', $result['record']->Title);
    }

    /**
     * #198: `api_access: 'GET,POST,PUT'` (no bare `action` token) reads as
     * full CRUD but grants no way to ever publish — confirmed on two real
     * projects where content stayed permanently draft-only for this exact
     * reason, with no error or warning anywhere saying so. A write to such
     * a class must now surface an ACTION_VERB_MISSING warning. Asserts the
     * full warning shape (code/field), not just that the code appears
     * somewhere — matches this codebase's own convention for the same
     * `warnings[]` structure elsewhere (CompositionTest's areaRelation
     * warning assertion) — and that it fires exactly once, not once per
     * some internal loop.
     */
    public function testWriteWarnsWhenWriteAccessIsGrantedWithoutTheActionVerb(): void
    {
        Config::modify()->set(ApiTestVersionedObject::class, 'api_access', 'read,create,update');

        $record = ApiTestVersionedObject::create(['Title' => 'Draft forever']);
        $record->write();

        $result = $this->writer()->update(
            $record,
            ['fields' => ['Title' => 'Still draft forever']],
            $this->member()
        );

        $matches = array_values(array_filter(
            $result['warnings'],
            fn (array $warning): bool => $warning['code'] === 'ACTION_VERB_MISSING'
        ));

        $this->assertCount(
            1,
            $matches,
            'a write to a class granting create/update but not action must warn exactly once ' .
                'that it can never publish'
        );
        $this->assertSame('api_access', $matches[0]['field']);
        $this->assertStringContainsString('"action"', $matches[0]['message']);
    }

    /**
     * Same shape as above, but granting only `create` (no `update`) — the
     * warning condition is `create OR update`, and this proves the
     * `create`-alone branch independently rather than only ever exercising
     * both together. `update()`'s own checkClassAccess() requires `update`
     * to even be entered, so this branch can only be reached via
     * `upsert()`'s create mode — which in turn needs an admin member,
     * since ApiTestVersionedObject declares no `canCreate()` override (it
     * only loosens canView/canEdit) and so falls back to the framework's
     * default ADMIN-only `canCreate()`.
     */
    public function testWriteWarnsWhenOnlyCreateIsGrantedWithoutTheActionVerb(): void
    {
        Config::modify()->set(ApiTestVersionedObject::class, 'api_access', 'read,create');

        $result = $this->writer()->upsert(
            ApiTestVersionedObject::class,
            ['fields' => ['Title' => 'Created without action']],
            $this->objFromFixture(Member::class, 'adminUser'),
            'create'
        );

        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('ACTION_VERB_MISSING', $codes);
    }

    /**
     * Same shape again, but granting only `update` (no `create`) — the
     * `update`-alone branch of the same `OR`.
     */
    public function testWriteWarnsWhenOnlyUpdateIsGrantedWithoutTheActionVerb(): void
    {
        Config::modify()->set(ApiTestVersionedObject::class, 'api_access', 'read,update');

        $record = ApiTestVersionedObject::create(['Title' => 'Updatable without action']);
        $record->write();

        $result = $this->writer()->update(
            $record,
            ['fields' => ['Title' => 'Updated again without action']],
            $this->member()
        );

        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('ACTION_VERB_MISSING', $codes);
    }

    /**
     * Negative control: the same class with `action` also granted (every
     * other test in this file's default) must never carry this warning —
     * otherwise it would fire unconditionally and the positive test above
     * would prove nothing.
     */
    public function testWriteDoesNotWarnWhenTheActionVerbIsGranted(): void
    {
        $record = ApiTestVersionedObject::create(['Title' => 'Can publish fine']);
        $record->write();

        $result = $this->writer()->update(
            $record,
            ['fields' => ['Title' => 'Still can publish fine']],
            $this->member()
        );

        $codes = array_column($result['warnings'], 'code');
        $this->assertNotContains('ACTION_VERB_MISSING', $codes);
    }

    /**
     * A non-versioned class can never be published regardless of its
     * declared verbs — PublishOrchestrator::publish() is already a no-op
     * for it — so warning here would be noise about a class that was
     * never going to publish through the payload's "publish" key anyway.
     */
    public function testWriteDoesNotWarnOnANonVersionedClassEvenWithoutTheActionVerb(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', 'read,create,update');

        $record = ApiTestObject::create(['Title' => 'Not versioned, no action needed']);
        $record->write();

        $result = $this->writer()->update(
            $record,
            ['fields' => ['Title' => 'Still not versioned']],
            $this->member()
        );

        $codes = array_column($result['warnings'], 'code');
        $this->assertNotContains('ACTION_VERB_MISSING', $codes);
    }
}
