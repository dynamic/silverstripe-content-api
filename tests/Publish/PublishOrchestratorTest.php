<?php

namespace Dynamic\ContentApi\Tests\Publish;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->orchestrator = PublishOrchestrator::create();
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

        $this->expectException(ApiError::class);
        $this->orchestrator->archive($wrapper);
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
        $this->publishedPage('Archive Child Force', $wrapper->ID);

        $this->orchestrator->archive($wrapper, force: true);

        $this->assertFalse($this->isLive($wrapper->ID));
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

    public function testSubtreeModePublishesEveryDraftDescendant(): void
    {
        $root = ApiTestPage::create(['Title' => 'Subtree Root']);
        $root->write();

        $middle = ApiTestPage::create(['Title' => 'Subtree Middle', 'ParentID' => $root->ID]);
        $middle->write();

        $leaf = ApiTestPage::create(['Title' => 'Subtree Leaf', 'ParentID' => $middle->ID]);
        $leaf->write();

        $this->orchestrator->publish($root, 'subtree');

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

        $this->orchestrator->publish($root, 'subtree');

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

        $this->orchestrator->publish($record, 'subtree');

        $this->assertTrue((bool) Versioned::get_by_stage(ApiTestVersionedObject::class, Versioned::LIVE)
            ->filter('ID', $record->ID)->exists());
    }

    public function testSubtreeIsAcceptedByAssertValidMode(): void
    {
        $this->orchestrator->assertValidMode('subtree');
        $this->addToAssertionCount(1);
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
}
