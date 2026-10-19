<?php

namespace Dynamic\ContentApi\Tests\Control;

use Colymba\RESTfulAPI\QueryHandlers\DefaultQueryHandler;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestBlockPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestCascadeObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestTag;
use Dynamic\ContentApi\Write\WriteGuardExtension;
use DNADesign\Elemental\Models\ElementalArea;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Versioned\Versioned;

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

    protected function tearDown(): void
    {
        // getElementalTypes() caches per page class name in a static that
        // Config::modify()'s automatic rollback doesn't touch.
        static::resetElementalTypesCache();

        parent::tearDown();
    }

    /**
     * Exposes the element stub on colymba's generic /api surface with NO
     * `api_writable_fields`, so the default `guarded` policy leaves its
     * has_one FKs — `ParentID` included — writable. That is exactly the
     * configuration #100 reports as the side door.
     */
    private function exposeElement(): void
    {
        Config::modify()->set(
            DefaultQueryHandler::class,
            'models',
            ['ApiTest' => ApiTestObject::class, 'ApiTestElement' => ApiTestElement::class]
        );
        Config::modify()->set(ApiTestElement::class, 'api_access', 'GET,POST,PUT,DELETE');
    }

    /**
     * An `ApiTestBlockPage` whose page AND area are both LIVE.
     *
     * `publishRecursive()`, not `publishSingle()`: `ElementalArea` is in
     * `ElementalPageExtension::$owns` and is itself `Versioned`, so
     * `publishSingle()` leaves the area DRAFT-only — which would make every
     * 422 case here pass for the wrong reason (a LIVE area lookup finds
     * nothing, and an unresolvable area is the guard's skip branch, not its
     * enforcement branch). With both records LIVE, a rejection can only come
     * from the placement policy, and
     * {@see self::testColymbaPutRejectsAnElementOntoAnUnpublishedPageArea()}
     * is the only case left that still needs the guard's DRAFT-stage read.
     *
     * The area is only auto-created by
     * `ElementalAreasExtension::allowAlteringElementalArea()` during a write
     * in the DRAFT stage, hence the surrounding `withVersionedMode()`.
     */
    private function blockPageArea(string $title): ElementalArea
    {
        return Versioned::withVersionedMode(function () use ($title) {
            Versioned::set_stage(Versioned::DRAFT);

            $page = ApiTestBlockPage::create(['Title' => $title]);
            $page->write();
            $area = $page->ElementalArea();
            $page->publishRecursive();

            return $area;
        });
    }

    /**
     * Same fixture, deliberately left DRAFT-only: neither the page nor its
     * area exists in any `_live` table — the shape that only a DRAFT-stage
     * read of the target area can see.
     */
    private function draftBlockPageArea(string $title): ElementalArea
    {
        return Versioned::withVersionedMode(function () use ($title) {
            Versioned::set_stage(Versioned::DRAFT);

            $page = ApiTestBlockPage::create(['Title' => $title]);
            $page->write();

            return $page->ElementalArea();
        });
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

    /**
     * #225: colymba's own write path sanitises an HTML field too.
     */
    public function testColymbaHtmlWritesAreSanitised(): void
    {
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Body' => '<script>alert(1)</script><p>ok</p>',
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $stored = (string) ApiTestObject::get()->byID($record->ID)->getField('Body');
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringContainsString('<p>ok</p>', $stored);
    }

    /**
     * #237: /api accepts the token header only; ?token= no longer logs in.
     */
    public function testTokenQueryVarIsNotAccepted(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_access', 'GET');

        $response = $this->mainSession->sendRequest('GET', 'api/ApiTest?token=' . urlencode($this->token), []);

        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(200, $this->colymba('GET', 'ApiTest')->getStatusCode(), 'the header still works');
    }

    /**
     * #230: a payload key that isn't a schema name never reaches a magic
     * set{Key}() setter.
     */
    public function testNonSchemaKeysNeverReachASetter(): void
    {
        ApiTestObject::$setterProbeCalled = false;
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", [
            'Title' => 'Renamed',
            'SetterProbe' => 1,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFalse(ApiTestObject::$setterProbeCalled);
        $this->assertSame('Renamed', ApiTestObject::get()->byID($record->ID)->Title);
    }

    /**
     * #235 residual: colymba's own relation write authorizes the far side.
     */
    public function testColymbaHasManyWriteNeedsUpdateOnTheChildClass(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', ['Children']);
        Config::modify()->set(ApiTestChildObject::class, 'api_access', 'read');
        $record = $this->objFromFixture(ApiTestObject::class, 'one');
        $child = $this->objFromFixture(ApiTestChildObject::class, 'childOne');

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", ['Children' => [(int) $child->ID]]);

        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, (int) ApiTestChildObject::get()->byID($child->ID)->ParentID);
    }

    /**
     * A many_many id that doesn't resolve is refused: ManyManyList::add()
     * would otherwise insert a join row for it without any check.
     */
    public function testColymbaManyManyWriteRefusesAnUnknownTarget(): void
    {
        Config::modify()->set(ApiTestObject::class, 'api_writable_relations', ['Tags']);
        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $response = $this->colymba('PUT', "ApiTest/{$record->ID}", ['Tags' => [999999]]);

        $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, $record->Tags()->count());
    }

    /**
     * #100: `ElementPlacementPolicy` (#64) only ran inside `RecordWriter`, so
     * an element class exposed with write verbs and no `api_writable_fields`
     * could still be attached to — or moved between — `ElementalArea`s past
     * the page's own `disallowed_elements`, a side door around the CMS's "add
     * element" picker. colymba's generic PUT now rejects the placement before
     * anything is written.
     */
    public function testColymbaPutRejectsMovingAnElementOntoAPageThatDisallowsItsType(): void
    {
        $this->exposeElement();

        $sourceArea = $this->blockPageArea('Placement Source');
        $targetArea = $this->blockPageArea('Placement Target');

        $element = ApiTestElement::create();
        $element->Title = 'Movable element';
        $element->ParentID = $sourceArea->ID;
        $element->write();
        $element->publishSingle();

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $response = $this->colymba('PUT', "ApiTestElement/{$element->ID}", [
            'ParentID' => (int) $targetArea->ID,
        ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString(
            'is not an allowed element type',
            (string) $response->getBody(),
            'the rejection is the placement policy, not an unrelated failure'
        );
        $this->assertSame(
            (int) $sourceArea->ID,
            (int) ApiTestElement::get()->byID($element->ID)->ParentID,
            'a rejected move must leave the element where it was'
        );
    }

    /**
     * The create half of the same gap: colymba POST writes `ParentID` straight
     * onto the new record, so a rejected create must not leave an element
     * behind. This is also why the check cannot live in `onBeforeWrite()` —
     * reverting `ParentID` there would persist an orphaned element instead.
     */
    public function testColymbaPostRejectsCreatingAnElementOnAPageThatDisallowsItsType(): void
    {
        $this->exposeElement();

        $area = $this->blockPageArea('Placement Create Target');

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $response = $this->colymba('POST', 'ApiTestElement', [
            'Title' => 'Never created',
            'ParentID' => (int) $area->ID,
        ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(
            0,
            ApiTestElement::get()->filter('Title', 'Never created')->count(),
            'a rejected create must not persist the element at all'
        );
    }

    /**
     * The other half of both cases: a placement the page DOES allow must still
     * go through, which is also the control proving `ParentID` is genuinely
     * writable on this surface under `guarded` (so the two rejections above
     * are the placement policy firing, not a field-writability revert).
     *
     * It opens with a rejection against the SAME target page on purpose: a
     * 200 here would also be what a silently skipped check looks like (an
     * unresolvable owner page, or no member to pass `canCreate()`), and only
     * the 422-then-200 pair against one page separates "allowed" from
     * "never enforced".
     */
    public function testColymbaStillPlacesAnElementTheTargetPageAllows(): void
    {
        $this->exposeElement();

        $sourceArea = $this->blockPageArea('Allowed Source');
        $targetArea = $this->blockPageArea('Allowed Target');

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $blocked = $this->colymba('POST', 'ApiTestElement', [
            'Title' => 'Blocked on this page',
            'ParentID' => (int) $targetArea->ID,
        ]);

        $this->assertSame(422, $blocked->getStatusCode(), (string) $blocked->getBody());

        // Same page, `disallowed_elements` swapped to a list that no longer
        // names ApiTestElement — so the type is allowed here and was not
        // above.
        Config::modify()->set(
            ApiTestBlockPage::class,
            'disallowed_elements',
            ['DNADesign\\Elemental\\Models\\ElementContent']
        );
        static::resetElementalTypesCache();

        $create = $this->colymba('POST', 'ApiTestElement', [
            'Title' => 'Legitimately created',
            'ParentID' => (int) $targetArea->ID,
        ]);

        $this->assertSame(200, $create->getStatusCode(), (string) $create->getBody());

        $created = ApiTestElement::get()->filter('Title', 'Legitimately created')->first();
        $this->assertNotNull($created, 'an allowed create is applied');
        $this->assertSame((int) $targetArea->ID, (int) $created->ParentID);

        $element = ApiTestElement::create();
        $element->Title = 'Legitimately movable';
        $element->ParentID = $sourceArea->ID;
        $element->write();
        $element->publishSingle();

        $move = $this->colymba('PUT', "ApiTestElement/{$element->ID}", ['ParentID' => (int) $targetArea->ID]);

        $this->assertSame(200, $move->getStatusCode(), (string) $move->getBody());
        $this->assertSame(
            (int) $targetArea->ID,
            (int) ApiTestElement::get()->byID($element->ID)->ParentID,
            'an allowed move is applied'
        );
    }

    /**
     * A GET-then-PUT round trip echoes the element's own `ParentID` back.
     * Rejecting that would break every client that reads a record before
     * writing it, and `RecordWriter` has the same no-op-when-unchanged rule.
     */
    public function testColymbaPutEchoingTheElementsOwnParentIdIsNotRejected(): void
    {
        $this->exposeElement();

        $area = $this->blockPageArea('Echo Target');

        $element = ApiTestElement::create();
        $element->Title = 'Already placed';
        $element->ParentID = $area->ID;
        $element->write();
        $element->publishSingle();

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $response = $this->colymba('PUT', "ApiTestElement/{$element->ID}", [
            'Title' => 'Just fixing a typo',
            'ParentID' => (int) $area->ID,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(
            'Just fixing a typo',
            ApiTestElement::get()->byID($element->ID)->Title,
            'the write went through, it was not silently reverted'
        );
    }

    /**
     * Nothing to enforce for an area no page owns — same documented skip
     * branch as `RecordWriter::assertElementPlacementAllowed()` (a brand new
     * area, or a detach), so an ordinary move into one is untouched.
     */
    public function testColymbaPutIntoAnAreaWithNoOwnerPageIsUnaffected(): void
    {
        $this->exposeElement();

        $orphanArea = ElementalArea::create();
        $orphanArea->write();

        $element = ApiTestElement::create();
        $element->Title = 'Heading nowhere in particular';
        $element->write();
        $element->publishSingle();

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $response = $this->colymba('PUT', "ApiTestElement/{$element->ID}", ['ParentID' => (int) $orphanArea->ID]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(
            (int) $orphanArea->ID,
            (int) ApiTestElement::get()->byID($element->ID)->ParentID
        );
    }

    /**
     * The same placement sent as the bare has_one NAME (`Parent`) rather than
     * the FK column (`ParentID`). `schemaKeys()` admits either, and
     * `DefaultQueryHandler::updateModel()` maps a has_one name to its FK
     * (`$model->{$attribute . 'ID'} = $value`), so a check that only read
     * `ParentID` would be one key-rename away from being bypassed. The mixed
     * payload matters just as much: colymba applies keys in payload order, so
     * which of the two wins is the client's choice, not something this guard
     * can assume.
     */
    public function testColymbaPutMovingAnElementViaTheBareParentRelationNameIsRejected(): void
    {
        $this->exposeElement();

        $sourceArea = $this->blockPageArea('Relation Name Source');
        $targetArea = $this->blockPageArea('Relation Name Target');

        $element = ApiTestElement::create();
        $element->Title = 'Renamed-key move';
        $element->ParentID = $sourceArea->ID;
        $element->write();
        $element->publishSingle();

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $byRelationName = $this->colymba('PUT', "ApiTestElement/{$element->ID}", [
            'Parent' => (int) $targetArea->ID,
        ]);

        $this->assertSame(422, $byRelationName->getStatusCode(), (string) $byRelationName->getBody());

        $mixed = $this->colymba('PUT', "ApiTestElement/{$element->ID}", [
            'ParentID' => (int) $sourceArea->ID,
            'Parent' => (int) $targetArea->ID,
        ]);

        $this->assertSame(422, $mixed->getStatusCode(), (string) $mixed->getBody());
        $this->assertSame(
            (int) $sourceArea->ID,
            (int) ApiTestElement::get()->byID($element->ID)->ParentID,
            'neither key shape may move the element'
        );
    }

    /**
     * The target area — and the page owning it — exist on DRAFT only. This is
     * what the guard's explicit `set_stage(DRAFT)` exists for: `/api` resolves
     * to LIVE, so an area read on LIVE is simply not there, and reading it
     * that way would skip enforcement for the unpublished pages a content API
     * is most often drafting content onto. Deleting that stage handling makes
     * this test fail while every LIVE-fixture case above still passes — which
     * is only true because `blockPageArea()` publishes the area too.
     */
    public function testColymbaPutRejectsAnElementOntoAnUnpublishedPageArea(): void
    {
        $this->exposeElement();

        $sourceArea = $this->blockPageArea('Draft Target Source');
        $draftArea = $this->draftBlockPageArea('Draft Only Target');

        $element = ApiTestElement::create();
        $element->Title = 'Heading for a draft page';
        $element->ParentID = $sourceArea->ID;
        $element->write();
        // The ELEMENT must be LIVE for colymba's PUT to find the record at all
        // (see testParentIdStaysReadonlyOnColymbaSurface); the TARGET page does
        // not, which is the point of the case.
        $element->publishSingle();

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $response = $this->colymba('PUT', "ApiTestElement/{$element->ID}", [
            'ParentID' => (int) $draftArea->ID,
        ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(
            (int) $sourceArea->ID,
            (int) ApiTestElement::get()->byID($element->ID)->ParentID
        );
    }

    /**
     * The reverse direction of the same disagreement: a class that
     * `api_protected_fields` the relation (`Parent`) has `ParentID` reverted
     * silently by `onBeforeWrite()`, and must not be met with a 422 from the
     * placement check for a field the client was never allowed to write.
     */
    public function testColymbaPlacementCheckDoesNotHardenADocumentedProtectedFieldRevert(): void
    {
        $this->exposeElement();

        Config::modify()->set(ApiTestElement::class, 'api_protected_fields', ['Parent']);

        $sourceArea = $this->blockPageArea('Protected Source');
        $targetArea = $this->blockPageArea('Protected Target');

        $element = ApiTestElement::create();
        $element->Title = 'Protected parent';
        $element->ParentID = $sourceArea->ID;
        $element->write();
        $element->publishRecursive();

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $response = $this->colymba('PUT', "ApiTestElement/{$element->ID}", [
            'Title' => 'Renamed only',
            'ParentID' => (int) $targetArea->ID,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(
            'Renamed only',
            ApiTestElement::get()->byID($element->ID)->Title,
            'the writable field still applies'
        );
        $this->assertSame(
            (int) $sourceArea->ID,
            (int) ApiTestElement::get()->byID($element->ID)->ParentID,
            'the protected field is silently reverted, not refused'
        );
    }

    /**
     * A class that allowlists the relation by NAME (`api_writable_fields:
     * [Title, Parent]`) — which `WriteApplicator::isFieldWritable()` honours,
     * and `onBeforeWrite()` therefore applies. `assertElementPlacement()` has
     * to pass the same relation name to that shared check, or it decides
     * `ParentID` is unwritable, takes its skip branch, and lets the move
     * through as a plain 200.
     */
    public function testColymbaPlacementCheckAgreesWithAnAllowlistNamedByRelation(): void
    {
        $this->exposeElement();

        Config::modify()->set(ApiTestElement::class, 'api_writable_fields', ['Title', 'Parent']);

        $sourceArea = $this->blockPageArea('Named Allowlist Source');
        $targetArea = $this->blockPageArea('Named Allowlist Target');

        $element = ApiTestElement::create();
        $element->Title = 'Allowlisted by relation name';
        $element->ParentID = $sourceArea->ID;
        $element->write();
        $element->publishSingle();

        Config::modify()->set(ApiTestBlockPage::class, 'disallowed_elements', [ApiTestElement::class]);
        static::resetElementalTypesCache();

        $response = $this->colymba('PUT', "ApiTestElement/{$element->ID}", [
            'ParentID' => (int) $targetArea->ID,
        ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(
            (int) $sourceArea->ID,
            (int) ApiTestElement::get()->byID($element->ID)->ParentID
        );
    }
}
