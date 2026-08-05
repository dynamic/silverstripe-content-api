<?php

namespace Dynamic\ContentApi\Tests\Publish;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestPage;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use SilverStripe\Versioned\Versioned;

/**
 * Coverage for #71's publish-order guard rails. Both bugs were confirmed
 * live during a real IA restructure (see the module's own #71 issue and
 * dynamic/agency-skills#55): unpublishing a wrapper page whose live
 * children had already been reparented elsewhere in draft silently
 * dropped the whole live subtree (not just the wrapper), and
 * `publishRecursive()` doesn't cascade to `Hierarchy` tree children (only
 * owned relations), so a multi-page subtree needed one explicit publish
 * call per page.
 */
class PublishOrchestratorTest extends ContentApiTestCase
{
    private PublishOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orchestrator = PublishOrchestrator::create();
    }

    public function testUnpublishRefusesWhenALiveChildHasMovedToADifferentParentInDraft(): void
    {
        $wrapper = $this->publishedPage('Wrapper');
        $child = $this->publishedPage('Child', $wrapper->ID);
        $newParent = $this->publishedPage('New Parent');

        // Reparent in draft only — never republished to live.
        $child->ParentID = $newParent->ID;
        $child->write();

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
        $this->assertTrue($this->isLive($child->ID), 'the child must still be live under the old parent');
    }

    public function testUnpublishSucceedsWhenNoDescendantsHaveMoved(): void
    {
        $wrapper = $this->publishedPage('Wrapper Untouched');
        $child = $this->publishedPage('Child Untouched', $wrapper->ID);

        $this->orchestrator->unpublish($wrapper);

        $this->assertFalse($this->isLive($wrapper->ID));
        $this->assertTrue(
            $this->isLive($child->ID),
            'unpublish() only removes the target record — it never cascades to children on its own'
        );
    }

    public function testUnpublishSucceedsWhenAChildWasNeverPublishedInTheFirstPlace(): void
    {
        $wrapper = $this->publishedPage('Wrapper Draft Child');
        $draftOnlyChild = ApiTestPage::create(['Title' => 'Draft-Only Child', 'ParentID' => $wrapper->ID]);
        $draftOnlyChild->write();

        // A draft-only child was never live, so it can't be "stranded" —
        // must not trip the guard.
        $this->orchestrator->unpublish($wrapper);

        $this->assertFalse($this->isLive($wrapper->ID));
    }

    public function testForceBypassesTheGuardAndAcceptsTheLoss(): void
    {
        $wrapper = $this->publishedPage('Wrapper Force');
        $child = $this->publishedPage('Child Force', $wrapper->ID);
        $newParent = $this->publishedPage('New Parent Force');

        $child->ParentID = $newParent->ID;
        $child->write();

        $this->orchestrator->unpublish($wrapper, force: true);

        $this->assertFalse($this->isLive($wrapper->ID));
        $this->assertFalse(
            $this->isLive($child->ID),
            'force must genuinely bypass the guard — the old (pre-#71) behavior'
        );
    }

    public function testDeleteWithUnpublishModeRoutesThroughTheSameGuard(): void
    {
        $wrapper = $this->publishedPage('Wrapper Delete');
        $child = $this->publishedPage('Child Delete', $wrapper->ID);
        $newParent = $this->publishedPage('New Parent Delete');

        $child->ParentID = $newParent->ID;
        $child->write();

        $this->expectException(ApiError::class);
        $this->orchestrator->delete($wrapper, 'unpublish');
    }

    public function testDeleteWithUnpublishModeAndForceBypassesTheGuard(): void
    {
        $wrapper = $this->publishedPage('Wrapper Delete Force');
        $child = $this->publishedPage('Child Delete Force', $wrapper->ID);
        $newParent = $this->publishedPage('New Parent Delete Force');

        $child->ParentID = $newParent->ID;
        $child->write();

        $this->orchestrator->delete($wrapper, 'unpublish', true);

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
