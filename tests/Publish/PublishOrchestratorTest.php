<?php

namespace Dynamic\ContentApi\Tests\Publish;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantSubPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestHierarchyObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedGrandchildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedParentObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnsCycleObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * Coverage for #71's publish-order guard rails. Confirmed live during a
 * real IA restructure (see the module's own #71 issue and
 * dynamic/agency-skills#55): unpublishing (or archiving) a `Hierarchy`
 * record with any live/draft tree children silently cascade-deleted every
 * one of them too, recursively — `SiteTree::onBeforeDelete()` cascades to
 * `AllChildren()` whenever `SiteTree.enforce_strict_hierarchy` is enabled
 * (the framework default), and both `doUnpublish()` and `doArchive()`
 * delete the record via a real `delete()` call, unconditionally triggering
 * that cascade against whatever children currently exist in the stage
 * being deleted from — independent of whether those children have also
 * moved. (An earlier version of this test suite/guard only covered a
 * child that had been reparented in draft, matching how the bug was first
 * diagnosed; `testUnpublishRefusesWhenTargetHasAnyLiveChildAtAll` below is
 * what caught that the narrower guard undercounted the real risk — a
 * completely untouched live child is cascade-deleted exactly the same
 * way.) Separately, `publishRecursive()` doesn't cascade to `Hierarchy`
 * tree children either (only owned Elemental relations), so a multi-page
 * subtree needed one explicit publish call per page before `subtree` mode.
 */
class PublishOrchestratorTest extends ContentApiTestCase
{
    private PublishOrchestrator $orchestrator;

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orchestrator = PublishOrchestrator::create();

        $this->logHandler = new TestHandler();
        Injector::inst()->registerService(new Logger('test', [$this->logHandler]), LoggerInterface::class);
    }

    public function testUnpublishRefusesWhenTargetHasAnyLiveChildAtAll(): void
    {
        $wrapper = $this->publishedPage('Wrapper');
        $child = $this->publishedPage('Child', $wrapper->ID);

        try {
            $this->orchestrator->unpublish($wrapper);
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertSame('UNPUBLISH_STRANDS_DESCENDANTS', $error->toArray()['code']);
            $this->assertStringContainsString((string) $child->ID, $error->getMessage());
        }

        $this->assertTrue(
            $this->isLive($wrapper->ID),
            'the guard must refuse before doUnpublish() runs — the wrapper must still be live'
        );
        $this->assertTrue($this->isLive($child->ID), 'an untouched live child must not be cascade-deleted');
    }

    public function testUnpublishRefusesWhenALiveChildHasAlsoMovedToADifferentParentInDraft(): void
    {
        $wrapper = $this->publishedPage('Wrapper Moved');
        $child = $this->publishedPage('Child Moved', $wrapper->ID);
        $newParent = $this->publishedPage('New Parent Moved');

        // Reparent in draft only — never republished to live. Not the
        // condition the guard actually checks (see class docblock), but
        // this is the exact shape #71 was originally reported with, and
        // must still refuse.
        $child->ParentID = $newParent->ID;
        $child->write();

        $this->expectException(ApiError::class);
        $this->orchestrator->unpublish($wrapper);
    }

    /**
     * getDescendantIDList()'s recursion is the entire premise of the fix —
     * the original bug was specifically about a 3-category, ~28-leaf-page
     * subtree, not a single direct child. A guard that only walked one
     * level deep would miss exactly the shape that motivated #71.
     */
    public function testUnpublishRefusesWhenOnlyALiveGrandchildExists(): void
    {
        $wrapper = $this->publishedPage('Grandchild Wrapper');
        $middle = $this->publishedPage('Grandchild Middle', $wrapper->ID);
        $grandchild = $this->publishedPage('Grandchild Leaf', $middle->ID);

        try {
            $this->orchestrator->unpublish($wrapper);
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertStringContainsString((string) $grandchild->ID, $error->getMessage());
        }

        $this->assertTrue($this->isLive($wrapper->ID));
        $this->assertTrue($this->isLive($middle->ID));
        $this->assertTrue($this->isLive($grandchild->ID), 'a live grandchild, not just a live child, must refuse');
    }

    public function testUnpublishSucceedsWhenTargetHasNoLiveChildren(): void
    {
        $leaf = $this->publishedPage('Leaf Page');

        $this->orchestrator->unpublish($leaf);

        $this->assertFalse($this->isLive($leaf->ID));
    }

    public function testUnpublishSucceedsWhenTheOnlyChildIsDraftOnly(): void
    {
        $wrapper = $this->publishedPage('Wrapper Draft Child');
        $draftOnlyChild = ApiTestPage::create(['Title' => 'Draft-Only Child', 'ParentID' => $wrapper->ID]);
        $draftOnlyChild->write();

        // A draft-only child was never live, so it isn't in the live
        // descendant set the guard checks — must not trip it.
        $this->orchestrator->unpublish($wrapper);

        $this->assertFalse($this->isLive($wrapper->ID));
    }

    public function testForceBypassesTheGuardAndCascadesAsSilverStripeNormallyWould(): void
    {
        $wrapper = $this->publishedPage('Wrapper Force');
        $child = $this->publishedPage('Child Force', $wrapper->ID);

        $this->orchestrator->unpublish($wrapper, force: true);

        $this->assertFalse($this->isLive($wrapper->ID));
        $this->assertFalse(
            $this->isLive($child->ID),
            'force must genuinely bypass the guard and let the real framework cascade happen'
        );
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains((string) $child->ID),
            'a forced bypass of a real cascade risk must leave an audit trail, not disappear silently'
        );
    }

    public function testForceOnARecordWithNoDescendantsLogsNothing(): void
    {
        $leaf = $this->publishedPage('Leaf Force No-op');

        $this->orchestrator->unpublish($leaf, force: true);

        $this->assertFalse(
            $this->logHandler->hasWarningRecords(),
            'force is a no-op decision when there was nothing to bypass — nothing to log'
        );
    }

    public function testDeleteWithUnpublishModeRoutesThroughTheSameGuard(): void
    {
        $wrapper = $this->publishedPage('Wrapper Delete');
        $this->publishedPage('Child Delete', $wrapper->ID);

        $this->expectException(ApiError::class);
        $this->orchestrator->delete($wrapper, 'unpublish');
    }

    public function testDeleteWithUnpublishModeAndForceBypassesTheGuard(): void
    {
        $wrapper = $this->publishedPage('Wrapper Delete Force');
        $this->publishedPage('Child Delete Force', $wrapper->ID);

        $this->orchestrator->delete($wrapper, 'unpublish', true);

        $this->assertFalse($this->isLive($wrapper->ID));
    }

    public function testArchiveRefusesWhenTargetHasAnyDescendantInEitherStage(): void
    {
        $wrapper = $this->publishedPage('Archive Wrapper');
        $liveChild = $this->publishedPage('Archive Live Child', $wrapper->ID);

        try {
            $this->orchestrator->archive($wrapper);
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertSame('UNPUBLISH_STRANDS_DESCENDANTS', $error->toArray()['code']);
        }

        $this->assertTrue($this->isLive($wrapper->ID), 'the guard must refuse before doArchive() runs');
        $this->assertTrue($this->isLive($liveChild->ID));
    }

    /**
     * doArchive() deletes the draft-stage row directly too (not just via
     * doUnpublish()'s live-stage delete) — a draft-only child, invisible
     * to the unpublish guard, is exactly the case archive's own guard
     * needs to catch that unpublish's doesn't.
     */
    public function testArchiveRefusesWhenTheOnlyDescendantIsDraftOnly(): void
    {
        $wrapper = $this->publishedPage('Archive Wrapper Draft Child');
        $draftOnlyChild = ApiTestPage::create(['Title' => 'Archive Draft-Only Child', 'ParentID' => $wrapper->ID]);
        $draftOnlyChild->write();

        try {
            $this->orchestrator->archive($wrapper);
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertSame('UNPUBLISH_STRANDS_DESCENDANTS', $error->toArray()['code']);
        }

        $this->assertTrue($this->isLive($wrapper->ID), 'the guard must refuse before doArchive() runs');
        $this->assertNotNull(ApiTestPage::get()->byID($draftOnlyChild->ID), 'the draft-only child must survive too');
    }

    /**
     * Same recursion concern as the unpublish grandchild test — a
     * draft-only grandchild (not just a draft-only direct child) must
     * still be caught by archive's draft-stage check.
     */
    public function testArchiveRefusesWhenOnlyADraftOnlyGrandchildExists(): void
    {
        $wrapper = $this->publishedPage('Archive Grandchild Wrapper');
        $middle = $this->publishedPage('Archive Grandchild Middle', $wrapper->ID);
        $grandchild = ApiTestPage::create(['Title' => 'Archive Grandchild Leaf', 'ParentID' => $middle->ID]);
        $grandchild->write();

        try {
            $this->orchestrator->archive($wrapper);
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertStringContainsString((string) $grandchild->ID, $error->getMessage());
        }

        $this->assertTrue($this->isLive($wrapper->ID));
        $this->assertNotNull(ApiTestPage::get()->byID($grandchild->ID));
    }

    public function testArchiveSucceedsWhenNoDescendantsInEitherStage(): void
    {
        $leaf = $this->publishedPage('Archive Leaf');

        $this->orchestrator->archive($leaf);

        $this->assertFalse($this->isLive($leaf->ID));
    }

    public function testArchiveForceBypassesTheGuard(): void
    {
        $wrapper = $this->publishedPage('Archive Wrapper Force');
        $child = $this->publishedPage('Archive Child Force', $wrapper->ID);

        $this->orchestrator->archive($wrapper, force: true);

        $this->assertFalse($this->isLive($wrapper->ID));
        $this->assertTrue($this->logHandler->hasWarningThatContains((string) $child->ID));
    }

    public function testDeleteWithArchiveModeRoutesThroughTheSameGuard(): void
    {
        $wrapper = $this->publishedPage('Archive Delete Wrapper');
        $this->publishedPage('Archive Delete Child', $wrapper->ID);

        $this->expectException(ApiError::class);
        $this->orchestrator->delete($wrapper, 'archive');
    }

    public function testDeleteWithArchiveModeAndForceBypassesTheGuard(): void
    {
        $wrapper = $this->publishedPage('Archive Delete Force Wrapper');
        $this->publishedPage('Archive Delete Force Child', $wrapper->ID);

        $this->orchestrator->delete($wrapper, 'archive', true);

        $this->assertFalse($this->isLive($wrapper->ID));
    }

    /**
     * #89: the guard used to key on `hasExtension(Hierarchy::class)`, but
     * `SiteTree::onBeforeDelete()`'s cascade — the actual risk being
     * guarded against — only fires when `SiteTree.enforce_strict_hierarchy`
     * is enabled. With it turned off, unpublishing a page with live
     * children must succeed without `force`, and the framework itself must
     * not have cascaded anything away.
     */
    public function testUnpublishSucceedsWithoutForceWhenEnforceStrictHierarchyIsDisabled(): void
    {
        Config::modify()->set(SiteTree::class, 'enforce_strict_hierarchy', false);

        $wrapper = $this->publishedPage('No Cascade Wrapper');
        $child = $this->publishedPage('No Cascade Child', $wrapper->ID);

        $this->orchestrator->unpublish($wrapper);

        $this->assertFalse($this->isLive($wrapper->ID));
        $this->assertTrue(
            $this->isLive($child->ID),
            'enforce_strict_hierarchy=false means the framework never cascades — the child must still be live'
        );
    }

    public function testArchiveSucceedsWithoutForceWhenEnforceStrictHierarchyIsDisabled(): void
    {
        Config::modify()->set(SiteTree::class, 'enforce_strict_hierarchy', false);

        $wrapper = $this->publishedPage('No Cascade Archive Wrapper');
        $child = $this->publishedPage('No Cascade Archive Child', $wrapper->ID);

        $this->orchestrator->archive($wrapper);

        $this->assertFalse($this->isLive($wrapper->ID));
        $this->assertTrue($this->isLive($child->ID));
    }

    /**
     * #89's second consequence: a `Hierarchy`-extended, `Versioned` class
     * that isn't `SiteTree` was refused the same as a page, even though
     * `SiteTree::onBeforeDelete()` — the only place the cascade lives — can
     * never fire on it at all.
     */
    public function testUnpublishSucceedsWithoutForceOnAHierarchyClassThatIsNotSiteTree(): void
    {
        $wrapper = $this->publishedHierarchyObject('Non-SiteTree Wrapper');
        $child = $this->publishedHierarchyObject('Non-SiteTree Child', $wrapper->ID);

        // Prove the parent/child relationship is real before relying on the
        // guard scoping past it — otherwise this test would pass just as
        // happily if ApiTestHierarchyObject's Hierarchy wiring were broken
        // and $child were never actually a descendant to begin with.
        $this->assertSame([(int) $child->ID], $wrapper->getDescendantIDList());

        $this->orchestrator->unpublish($wrapper);

        $this->assertFalse($this->isLiveHierarchyObject($wrapper->ID));
        $this->assertTrue($this->isLiveHierarchyObject($child->ID));
    }

    public function testSubtreeModePublishesEveryDraftDescendant(): void
    {
        $root = ApiTestPage::create(['Title' => 'Subtree Root']);
        $root->write();

        $middle = ApiTestPage::create(['Title' => 'Subtree Middle', 'ParentID' => $root->ID]);
        $middle->write();

        $leaf = ApiTestPage::create(['Title' => 'Subtree Leaf', 'ParentID' => $middle->ID]);
        $leaf->write();

        $this->orchestrator->publish($root, 'subtree', $this->apiMember());

        $this->assertTrue($this->isLive($root->ID));
        $this->assertTrue($this->isLive($middle->ID));
        $this->assertTrue($this->isLive($leaf->ID));
    }

    public function testSubtreeModeDoesNotPublishAChildThatMovedAwayInDraftBeforehand(): void
    {
        $root = ApiTestPage::create(['Title' => 'Subtree Root Moved']);
        $root->write();

        $elsewhere = ApiTestPage::create(['Title' => 'Elsewhere']);
        $elsewhere->write();

        $child = ApiTestPage::create(['Title' => 'Subtree Child Moved', 'ParentID' => $elsewhere->ID]);
        $child->write();

        $this->orchestrator->publish($root, 'subtree', $this->apiMember());

        $this->assertTrue($this->isLive($root->ID));
        $this->assertFalse(
            $this->isLive($child->ID),
            'subtree only walks the target\'s own draft children — a page parented elsewhere is not one of them'
        );
    }

    public function testSubtreeModeOnANonHierarchicalClassJustPublishesTheRecordItself(): void
    {
        $record = ApiTestVersionedObject::create(['Title' => 'Not a tree']);
        $record->write();

        $this->orchestrator->publish($record, 'subtree', $this->apiMember());

        $this->assertTrue((bool) Versioned::get_by_stage(ApiTestVersionedObject::class, Versioned::LIVE)
            ->filter('ID', $record->ID)->exists());
    }

    /**
     * #90: a descendant whose class doesn't grant the `action` verb must
     * refuse the whole walk — not just skip that one descendant.
     */
    public function testSubtreePublishRefusesADescendantWhoseClassDoesNotGrantTheActionVerb(): void
    {
        $root = ApiTestPage::create(['Title' => 'Subtree Class-Gated Root']);
        $root->write();

        $child = ApiTestPage::create(['Title' => 'Subtree Class-Gated Child', 'ParentID' => $root->ID]);
        $child->write();

        // ApiTestPage's own api_access starts as `true` (full access, set
        // in ContentApiTestCase::setUp()) — narrow it to `read` only, so
        // checkClassAccess('action', ...) refuses regardless of $member.
        Config::modify()->set(ApiTestPage::class, 'api_access', 'read');

        try {
            $this->orchestrator->publish($root, 'subtree', $this->apiMember());
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertSame('FORBIDDEN_CLASS', $error->toArray()['code']);
        }

        $this->assertFalse(
            $this->isLive($root->ID),
            'the whole walk is authorization-checked before any write — root must not be published either'
        );
        $this->assertFalse($this->isLive($child->ID));
    }

    /**
     * #90: a descendant the member can't edit (class allows `action`, but
     * this specific record's own canEdit() refuses) must also refuse the
     * whole walk. `ApiTestGrantSubPage` — not `ApiTestPage` — is the
     * descendant here deliberately: this testbed's own
     * `ContentApiGrantExtension` is applied broadly to `SiteTree`, and it
     * grants canEdit() unconditionally to any `CONTENT_API_ACCESS` member
     * on a class that declares its own `api_access`. `ApiTestGrantSubPage`
     * inherits `ApiTestPage`'s class-level verbs (so `checkClassAccess`
     * still passes) but declares none of its own, so the grant extension
     * never answers for it (see the stub's own docblock) and real
     * `CanEditType`/`EditorMembers` restrictions actually apply.
     */
    public function testSubtreePublishRefusesADescendantTheMemberCannotEdit(): void
    {
        $root = ApiTestPage::create(['Title' => 'Subtree Record-Gated Root']);
        $root->write();

        $child = ApiTestGrantSubPage::create([
            'Title' => 'Subtree Record-Gated Child',
            'ParentID' => $root->ID,
            'CanEditType' => InheritedPermissions::ONLY_THESE_MEMBERS,
        ]);
        $child->write();

        // A non-empty EditorMembers list that deliberately excludes
        // apiUser — an empty list reads as "not yet restricted", not
        // "restricted to nobody".
        $child->EditorMembers()->add($this->objFromFixture(Member::class, 'adminUser'));

        try {
            $this->orchestrator->publish($root, 'subtree', $this->apiMember());
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertSame('FORBIDDEN_RECORD', $error->toArray()['code']);
            $this->assertStringContainsString((string) $child->ID, $error->getMessage());
        }

        $this->assertFalse($this->isLive($root->ID));
        $this->assertFalse($this->isLive($child->ID));
    }

    /**
     * #102: a deliberately-unpublished descendant must stay unpublished —
     * not resurrected just because a live ancestor is going through
     * `subtree` — and its own children (which liveOnly never even visits)
     * must be left alone too.
     */
    public function testLiveOnlySkipsAnUnpublishedDescendantBranchEntirely(): void
    {
        $root = $this->publishedPage('LiveOnly Root');

        $offlinePage = ApiTestPage::create(['Title' => 'LiveOnly Deliberately Offline', 'ParentID' => $root->ID]);
        $offlinePage->write();
        $offlinePage->publishRecursive();
        $offlinePage->doUnpublish();

        $grandchild = ApiTestPage::create([
            'Title' => 'LiveOnly Grandchild Under Offline',
            'ParentID' => $offlinePage->ID,
        ]);
        $grandchild->write();

        $stillLiveChild = $this->publishedPage('LiveOnly Still-Live Child', $root->ID);

        $entries = $this->orchestrator->publish($root, 'subtree', $this->apiMember(), liveOnly: true);

        $this->assertTrue($this->isLive($root->ID));
        $this->assertTrue($this->isLive($stillLiveChild->ID));
        $this->assertFalse(
            $this->isLive($offlinePage->ID),
            'a deliberately-unpublished descendant must not be resurrected by an ancestor\'s subtree publish'
        );
        $this->assertFalse(
            $this->isLive($grandchild->ID),
            'liveOnly must not recurse into a skipped branch\'s own children either'
        );

        $touchedIDs = array_column($entries, 'id');
        $this->assertContains((int) $root->ID, $touchedIDs);
        $this->assertContains((int) $stillLiveChild->ID, $touchedIDs);
        $this->assertNotContains((int) $offlinePage->ID, $touchedIDs);
        $this->assertNotContains((int) $grandchild->ID, $touchedIDs);
    }

    /**
     * #102: dryRun must authorization-check exactly as a real run would
     * (so the same error surfaces up front) but never call publishSingle().
     */
    public function testDryRunReturnsTheWouldPublishSetWithoutWriting(): void
    {
        $root = ApiTestPage::create(['Title' => 'DryRun Root']);
        $root->write();

        $child = ApiTestPage::create(['Title' => 'DryRun Child', 'ParentID' => $root->ID]);
        $child->write();

        $entries = $this->orchestrator->publish($root, 'subtree', $this->apiMember(), dryRun: true);

        $this->assertFalse($this->isLive($root->ID), 'dryRun must never write');
        $this->assertFalse($this->isLive($child->ID));

        $touchedIDs = array_column($entries, 'id');
        $this->assertContains((int) $root->ID, $touchedIDs);
        $this->assertContains((int) $child->ID, $touchedIDs);
    }

    public function testDryRunStillSurfacesTheSameAuthorizationErrorARealRunWould(): void
    {
        $root = ApiTestPage::create(['Title' => 'DryRun Refused Root']);
        $root->write();

        $child = ApiTestGrantSubPage::create([
            'Title' => 'DryRun Refused Child',
            'ParentID' => $root->ID,
            'CanEditType' => InheritedPermissions::ONLY_THESE_MEMBERS,
        ]);
        $child->write();
        $child->EditorMembers()->add($this->objFromFixture(Member::class, 'adminUser'));

        try {
            $this->orchestrator->publish($root, 'subtree', $this->apiMember(), dryRun: true);
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertSame('FORBIDDEN_RECORD', $error->toArray()['code']);
        }

        $this->assertFalse($this->isLive($root->ID));
    }

    public function testSubtreeIsAcceptedByAssertValidMode(): void
    {
        $this->orchestrator->assertValidMode('subtree');
        $this->addToAssertionCount(1);
    }

    public function testOwnsIsAcceptedByAssertValidMode(): void
    {
        $this->orchestrator->assertValidMode('owns');
        $this->addToAssertionCount(1);
    }

    /**
     * #119/#168: `owns` publishes the record itself plus every
     * `$owns`-reachable descendant — the parent/child/grandchild fixture
     * built for #120's `OwnedTreeWalkerTest` exercises has_many, has_one,
     * and depth-2 recursion all at once. `adminUser`, not `apiMember()`:
     * these are plain `DataObject` stubs with no `ContentApiGrantExtension`
     * applied, so their `canEdit()` falls back to the framework default
     * (`ADMIN` required) — matching `CompositionTest`'s own convention for
     * the same reason.
     */
    public function testOwnsModePublishesTheFullOwnedTree(): void
    {
        $parent = ApiTestOwnedParentObject::create(['Title' => 'Owns Parent']);
        $parent->write();

        $child = ApiTestOwnedChildObject::create(['Title' => 'Owns Child', 'ParentID' => $parent->ID]);
        $child->write();

        $grandchild = ApiTestOwnedGrandchildObject::create(['Title' => 'Owns Grandchild', 'ParentID' => $child->ID]);
        $grandchild->write();

        $entries = $this->orchestrator->publish($parent, 'owns', $this->adminMember());

        $this->assertTrue($this->isLiveRecord(ApiTestOwnedParentObject::class, $parent->ID));
        $this->assertTrue($this->isLiveRecord(ApiTestOwnedChildObject::class, $child->ID));
        $this->assertTrue($this->isLiveRecord(ApiTestOwnedGrandchildObject::class, $grandchild->ID));

        $touchedIDs = array_column($entries, 'id');
        $this->assertContains((int) $parent->ID, $touchedIDs);
        $this->assertContains((int) $child->ID, $touchedIDs);
        $this->assertContains((int) $grandchild->ID, $touchedIDs);
    }

    /**
     * A cyclic `$owns` graph must terminate — {@see OwnedTreeWalker} already
     * guards this at the collection stage, but this confirms the write
     * stage doesn't loop either (there is no separate cycle guard around
     * `publishSingle()` itself, so this only holds if the walker's own
     * dedup is respected).
     */
    public function testOwnsModeTerminatesOnACyclicOwnsGraph(): void
    {
        $a = ApiTestOwnsCycleObject::create(['Title' => 'Owns Cycle A']);
        $a->write();
        $b = ApiTestOwnsCycleObject::create(['Title' => 'Owns Cycle B', 'NextID' => $a->ID]);
        $b->write();
        $a->NextID = $b->ID;
        $a->write();

        $entries = $this->orchestrator->publish($a, 'owns', $this->adminMember());

        $this->assertTrue($this->isLiveRecord(ApiTestOwnsCycleObject::class, $a->ID));
        $this->assertTrue($this->isLiveRecord(ApiTestOwnsCycleObject::class, $b->ID));
        // Root (A, published separately, last) + B (walked once) — not more.
        $this->assertCount(2, $entries);
    }

    /**
     * #168: a class in the owned tree that withholds `action` refuses the
     * whole call, before anything is written — matching `subtree`'s own
     * check-everything-first contract (`testSubtreePublishRefusesADescendantWhoseClassDoesNotGrantTheActionVerb`).
     */
    public function testOwnsModeRefusesWhenADescendantClassDoesNotGrantTheActionVerb(): void
    {
        $parent = ApiTestOwnedParentObject::create(['Title' => 'Owns Gated Parent']);
        $parent->write();

        $child = ApiTestOwnedChildObject::create(['Title' => 'Owns Gated Child', 'ParentID' => $parent->ID]);
        $child->write();

        Config::modify()->set(ApiTestOwnedChildObject::class, 'api_access', 'read');

        try {
            $this->orchestrator->publish($parent, 'owns', $this->adminMember());
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertSame('FORBIDDEN_CLASS', $error->toArray()['code']);
        }

        $this->assertFalse(
            $this->isLiveRecord(ApiTestOwnedParentObject::class, $parent->ID),
            'the whole walk is authorization-checked before any write — root must not be published either'
        );
        $this->assertFalse($this->isLiveRecord(ApiTestOwnedChildObject::class, $child->ID));
    }

    /**
     * A member without `ADMIN` can't `canEdit()` these plain-`DataObject`
     * stubs by default (no `ContentApiGrantExtension`, unlike `SiteTree` —
     * see the class docblock) — `apiMember()` alone is enough to trigger
     * `FORBIDDEN_RECORD` here, no `ApiTestGrantSubPage`-equivalent needed.
     */
    public function testOwnsModeRefusesWhenTheMemberCannotEditADescendant(): void
    {
        $parent = ApiTestOwnedParentObject::create(['Title' => 'Owns Record-Gated Parent']);
        $parent->write();

        $child = ApiTestOwnedChildObject::create(['Title' => 'Owns Record-Gated Child', 'ParentID' => $parent->ID]);
        $child->write();

        try {
            $this->orchestrator->publish($parent, 'owns', $this->apiMember());
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertSame('FORBIDDEN_RECORD', $error->toArray()['code']);
        }

        $this->assertFalse($this->isLiveRecord(ApiTestOwnedParentObject::class, $parent->ID));
        $this->assertFalse($this->isLiveRecord(ApiTestOwnedChildObject::class, $child->ID));
    }

    public function testOwnsModeDryRunReturnsTheWouldPublishSetWithoutWriting(): void
    {
        $parent = ApiTestOwnedParentObject::create(['Title' => 'Owns DryRun Parent']);
        $parent->write();

        $child = ApiTestOwnedChildObject::create(['Title' => 'Owns DryRun Child', 'ParentID' => $parent->ID]);
        $child->write();

        $entries = $this->orchestrator->publish($parent, 'owns', $this->adminMember(), dryRun: true);

        $this->assertFalse(
            $this->isLiveRecord(ApiTestOwnedParentObject::class, $parent->ID),
            'dryRun must never write'
        );
        $this->assertFalse($this->isLiveRecord(ApiTestOwnedChildObject::class, $child->ID));

        $touchedIDs = array_column($entries, 'id');
        $this->assertContains((int) $parent->ID, $touchedIDs);
        $this->assertContains((int) $child->ID, $touchedIDs);
    }

    public function testOwnsModeDryRunStillSurfacesTheSameAuthorizationErrorARealRunWould(): void
    {
        $parent = ApiTestOwnedParentObject::create(['Title' => 'Owns DryRun Refused Parent']);
        $parent->write();

        $child = ApiTestOwnedChildObject::create(['Title' => 'Owns DryRun Refused Child', 'ParentID' => $parent->ID]);
        $child->write();

        try {
            $this->orchestrator->publish($parent, 'owns', $this->apiMember(), dryRun: true);
            $this->fail('expected an ApiError');
        } catch (ApiError $error) {
            $this->assertSame('FORBIDDEN_RECORD', $error->toArray()['code']);
        }

        $this->assertFalse($this->isLiveRecord(ApiTestOwnedParentObject::class, $parent->ID));
    }

    /**
     * `$additional` is load-bearing (see `publishOwnedTree()`'s own
     * docblock): a caller-known record outside the `$owns` graph entirely
     * must still be published and authorized, the shape
     * `CompositionService::publishAll()` relies on for element children.
     */
    public function testOwnsModeAcceptsAdditionalTargetsOutsideTheOwnsGraph(): void
    {
        $parent = ApiTestOwnedParentObject::create(['Title' => 'Owns Additional Parent']);
        $parent->write();

        // Not linked into $parent's own $owns graph at all — reachable only
        // because the caller passes it explicitly, the same shape
        // CompositionService::publishAll() and PageHandler::applyTemplate()
        // rely on for element children BaseElement's own $owns doesn't
        // reach.
        $sideloaded = ApiTestOwnedGrandchildObject::create(['Title' => 'Owns Sideloaded']);
        $sideloaded->write();

        $entries = $this->orchestrator->publishOwnedTree($parent, $this->adminMember(), [$sideloaded]);

        $this->assertTrue($this->isLiveRecord(ApiTestOwnedParentObject::class, $parent->ID));
        $this->assertTrue($this->isLiveRecord(ApiTestOwnedGrandchildObject::class, $sideloaded->ID));

        $touchedIDs = array_column($entries, 'id');
        $this->assertContains((int) $sideloaded->ID, $touchedIDs);
    }

    /**
     * A record reachable both via `$owns` and via `$additional` (e.g. the
     * composition's own element, which is both walk-reachable through the
     * area AND passed explicitly) must not be published/authorized twice —
     * the entries list has exactly one row for it.
     */
    public function testOwnsModeDeduplicatesAnAdditionalTargetAlreadyInTheOwnsGraph(): void
    {
        $parent = ApiTestOwnedParentObject::create(['Title' => 'Owns Dedup Parent']);
        $parent->write();

        $child = ApiTestOwnedChildObject::create(['Title' => 'Owns Dedup Child', 'ParentID' => $parent->ID]);
        $child->write();

        $entries = $this->orchestrator->publishOwnedTree($parent, $this->adminMember(), [$child]);

        $touchedIDs = array_column($entries, 'id');
        $this->assertSame(
            1,
            count(array_filter($touchedIDs, fn (int $id) => $id === (int) $child->ID)),
            'a target reachable both via $owns and via $additional must appear exactly once'
        );
    }

    /**
     * A non-owning, non-hierarchical class is equivalent to `single` under
     * `owns` — no descendants, just the record itself.
     */
    public function testOwnsModeOnAClassWithNoOwnedRelationsJustPublishesTheRecordItself(): void
    {
        $record = ApiTestVersionedObject::create(['Title' => 'Owns No Relations']);
        $record->write();

        $entries = $this->orchestrator->publish($record, 'owns', $this->adminMember());

        $this->assertTrue((bool) Versioned::get_by_stage(ApiTestVersionedObject::class, Versioned::LIVE)
            ->filter('ID', $record->ID)->exists());
        $this->assertCount(1, $entries);
        $this->assertSame((int) $record->ID, $entries[0]['id']);
    }

    private function apiMember(): Member
    {
        return $this->objFromFixture(Member::class, 'apiUser');
    }

    /**
     * Has `ADMIN`, which `Permission::check()`/`checkMember()` implicitly
     * satisfies for any permission code — the only way to pass `canEdit()`
     * on a plain `DataObject` stub with no `ContentApiGrantExtension`
     * applied, since the framework default requires `ADMIN` explicitly.
     */
    private function adminMember(): Member
    {
        return $this->objFromFixture(Member::class, 'adminUser');
    }

    private function isLiveRecord(string $className, int $id): bool
    {
        return (bool) Versioned::get_by_stage($className, Versioned::LIVE)->filter('ID', $id)->exists();
    }

    private function publishedPage(string $title, int $parentID = 0): ApiTestPage
    {
        $page = ApiTestPage::create(['Title' => $title, 'ParentID' => $parentID]);
        $page->write();
        $page->publishRecursive();

        return $page;
    }

    private function isLive(int $id): bool
    {
        return (bool) Versioned::get_by_stage(ApiTestPage::class, Versioned::LIVE)->filter('ID', $id)->exists();
    }

    private function publishedHierarchyObject(string $title, int $parentID = 0): ApiTestHierarchyObject
    {
        $object = ApiTestHierarchyObject::create(['Title' => $title, 'ParentID' => $parentID]);
        $object->write();
        $object->publishRecursive();

        return $object;
    }

    private function isLiveHierarchyObject(int $id): bool
    {
        return (bool) Versioned::get_by_stage(ApiTestHierarchyObject::class, Versioned::LIVE)
            ->filter('ID', $id)
            ->exists();
    }
}
