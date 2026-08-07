<?php

namespace Dynamic\ContentApi\Tests\Verify;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedGrandchildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedParentObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnsCycleObject;
use Dynamic\ContentApi\Verify\OwnedTreeWalker;
use SilverStripe\Core\Config\Config;
use SilverStripe\Versioned\Versioned;

/**
 * Direct coverage for #120's `OwnedTreeWalker` — the module's first `$owns`
 * walker (no class in `src/` ever declared `$owns` before this feature; see
 * the stub docblocks for why the test fixtures are synthetic). Two
 * corrections versus the `DraftLiveParityTask` prototype this generalizes
 * are the whole point of a dedicated test file: the prototype has neither a
 * cycle guard nor a depth cap.
 */
class OwnedTreeWalkerTest extends ContentApiTestCase
{
    private function walker(): OwnedTreeWalker
    {
        return OwnedTreeWalker::create();
    }

    private function inDraft(callable $callback): mixed
    {
        return Versioned::withVersionedMode(function () use ($callback) {
            Versioned::set_stage(Versioned::DRAFT);

            return $callback();
        });
    }

    public function testWalksAHasManyOwnedRelationRecursively(): void
    {
        [$parent, $child, $grandchild] = $this->inDraft(function () {
            $parent = ApiTestOwnedParentObject::create(['Title' => 'Parent']);
            $parent->write();

            $child = ApiTestOwnedChildObject::create(['Title' => 'Child', 'ParentID' => $parent->ID]);
            $child->write();

            $grandchild = ApiTestOwnedGrandchildObject::create(['Title' => 'Grandchild', 'ParentID' => $child->ID]);
            $grandchild->write();

            return [$parent, $child, $grandchild];
        });

        $result = $this->inDraft(fn () => $this->walker()->walk($parent));

        $this->assertCount(2, $result, 'both the child and the grandchild must be walked');
        $this->assertSame(1, $result[0]['depth']);
        $this->assertSame((int) $child->ID, (int) $result[0]['record']->ID);
        $this->assertSame(2, $result[1]['depth'], 'the grandchild is reached via the child\'s own $owns declaration');
        $this->assertSame((int) $grandchild->ID, (int) $result[1]['record']->ID);
    }

    /**
     * `FeaturedGrandchild` (a has_one) exercises the `$related instanceof
     * DataObject` branch — every other test in this file only exercises
     * the iterable (has_many) branch.
     */
    public function testWalksAHasOneOwnedRelation(): void
    {
        [$child, $featured] = $this->inDraft(function () {
            $featured = ApiTestOwnedGrandchildObject::create(['Title' => 'Featured']);
            $featured->write();

            $child = ApiTestOwnedChildObject::create(['Title' => 'Child', 'FeaturedGrandchildID' => $featured->ID]);
            $child->write();

            return [$child, $featured];
        });

        $result = $this->inDraft(fn () => $this->walker()->walk($child));

        $this->assertCount(1, $result);
        $this->assertSame((int) $featured->ID, (int) $result[0]['record']->ID);
        $this->assertSame(1, $result[0]['depth']);
    }

    /**
     * An unset has_one owned relation (`FeaturedGrandchildID` = 0) must not
     * be walked as if it pointed at record #0 — `$related->exists()` is the
     * guard.
     */
    public function testAnUnsetHasOneOwnedRelationIsNotWalked(): void
    {
        $child = $this->inDraft(function () {
            $child = ApiTestOwnedChildObject::create(['Title' => 'No featured grandchild']);
            $child->write();

            return $child;
        });

        $result = $this->inDraft(fn () => $this->walker()->walk($child));

        $this->assertSame([], $result);
    }

    /**
     * The bug this guards: the `DraftLiveParityTask` prototype this class
     * generalizes has no visited-set at all — a cyclic `$owns` graph (two
     * records each declaring the other as an owned `Next`) would recurse
     * forever. Two records pointing at each other via `Next` (both
     * `$owns`) form a genuine cycle; nothing in SilverStripe itself
     * prevents declaring one.
     */
    public function testACyclicOwnsGraphTerminatesInsteadOfRecursingForever(): void
    {
        [$a, $b] = $this->inDraft(function () {
            $a = ApiTestOwnsCycleObject::create(['Title' => 'A']);
            $a->write();
            $b = ApiTestOwnsCycleObject::create(['Title' => 'B', 'NextID' => $a->ID]);
            $b->write();
            $a->NextID = $b->ID;
            $a->write();

            return [$a, $b];
        });

        $result = $this->inDraft(fn () => $this->walker()->walk($a));

        // The root (A) is never included in its own result; B is reached
        // once via A->Next, and the walk back to A is where the cycle
        // guard must stop it — A must not reappear a second time.
        $this->assertCount(1, $result, 'the cycle must not produce more than one entry, and must terminate at all');
        $this->assertSame((int) $b->ID, (int) $result[0]['record']->ID);
    }

    /**
     * Independent of the cycle guard — a very deep (but acyclic) chain is
     * still bounded.
     */
    public function testADeepAcyclicChainIsBoundedByTheDepthCap(): void
    {
        Config::modify()->set(OwnedTreeWalker::class, 'max_depth', 3);

        $root = $this->inDraft(function () {
            $current = ApiTestOwnsCycleObject::create(['Title' => 'Depth 0']);
            $current->write();
            $root = $current;

            for ($i = 1; $i <= 10; $i++) {
                $next = ApiTestOwnsCycleObject::create(['Title' => "Depth {$i}"]);
                $next->write();
                $current->NextID = $next->ID;
                $current->write();
                $current = $next;
            }

            return $root;
        });

        $result = $this->inDraft(fn () => $this->walker()->walk($root));

        $this->assertCount(3, $result, 'the walk must stop at the configured max_depth');
        $this->assertSame([1, 2, 3], array_column($result, 'depth'));
    }

    /**
     * The explicit per-call `$maxDepth` argument (used by ParityHandler's
     * `?depth=` query param) takes precedence over the configured default.
     */
    public function testAnExplicitMaxDepthArgumentOverridesTheConfiguredDefault(): void
    {
        [$parent] = $this->inDraft(function () {
            $parent = ApiTestOwnedParentObject::create(['Title' => 'Parent']);
            $parent->write();
            $child = ApiTestOwnedChildObject::create(['Title' => 'Child', 'ParentID' => $parent->ID]);
            $child->write();
            $grandchild = ApiTestOwnedGrandchildObject::create(['Title' => 'GC', 'ParentID' => $child->ID]);
            $grandchild->write();

            return [$parent];
        });

        $result = $this->inDraft(fn () => $this->walker()->walk($parent, 1));

        $this->assertCount(1, $result, 'depth=1 must stop after the child, before the grandchild');
        $this->assertSame(1, $result[0]['depth']);
    }

    /**
     * An unversioned record is never EMITTED (it has no draft/live state
     * for anything consuming this walker's output to report on) — but,
     * unlike the walker's first cut, its own owned relations are still
     * walked THROUGH it, matching `RecursivePublishable::
     * rollbackRelations()`'s real recursion behavior. `ApiTestObject`
     * declares no `$owns` at all, so this specific fixture can't
     * distinguish "pruned" from "walked through but nothing to find" —
     * the assertion is on the root itself never appearing in its own
     * result, which holds either way; see `RecordParityTest` for
     * coverage of the ROOT rejection ParityHandler applies before ever
     * calling this walker on a non-Versioned class.
     */
    public function testAnUnversionedRootIsNeverEmittedIntoItsOwnResult(): void
    {
        $unversioned = \Dynamic\ContentApi\Tests\Stub\ApiTestObject::create(['Title' => 'Not Versioned']);
        $unversioned->write();

        $result = $this->walker()->walk($unversioned);

        $this->assertSame([], $result);
    }

    /**
     * The bug this guards, distinct from the test above: an unversioned
     * record's OWN owned relations must still be walked THROUGH it, only
     * never reported for the unversioned record itself —
     * `RecursivePublishable::rollbackRelations()` (the real framework
     * mechanism `publishRecursive()` builds on) recurses into an
     * unversioned owned record's own owned relations; it only skips
     * *reporting* draft/live state for something that has none. This
     * fixture (unlike `ApiTestObject` above, which declares no `$owns` at
     * all) has an `$owns` pointing at a genuinely versioned leaf, so it
     * can actually distinguish "pruned the branch" from "walked through
     * but reported nothing for itself."
     */
    public function testAnUnversionedIntermediateIsWalkedThroughNotPrunedAt(): void
    {
        [$wrapper, $leaf] = $this->inDraft(function () {
            $leaf = ApiTestOwnedGrandchildObject::create(['Title' => 'Versioned leaf']);
            $leaf->write();

            $wrapper = \Dynamic\ContentApi\Tests\Stub\ApiTestUnversionedOwnedWrapperObject::create([
                'Title' => 'Unversioned wrapper',
                'LeafID' => $leaf->ID,
            ]);
            $wrapper->write();

            return [$wrapper, $leaf];
        });

        $result = $this->inDraft(fn () => $this->walker()->walk($wrapper));

        $this->assertCount(1, $result, 'the versioned leaf must still be found through the unversioned wrapper');
        $this->assertSame((int) $leaf->ID, (int) $result[0]['record']->ID);
        $this->assertSame(
            1,
            $result[0]['depth'],
            'the unversioned wrapper itself consumes a depth level even though it is never reported'
        );
    }

    /**
     * The bug this guards: `$owns` can form a diamond — the same record
     * reachable via two different owned paths at different depths (a
     * shared `File` owned both directly by a page and by one of its
     * elements is a realistic real-world shape). A plain seen/unseen
     * visited-set reports whichever path happens to reach the shared
     * node FIRST; if that's the deeper path, the node's own further
     * owned relations could go unexpanded even though a shallower,
     * still-in-budget path to the same node exists. Constructed so the
     * DEEP path (Parent->Children->Child->Grandchildren, depth 2) is
     * declared and therefore walked before the SHALLOW one
     * (Parent->FeaturedGrandchild, depth 1) — the failure mode only
     * shows up in that order.
     */
    public function testADiamondReachedByTwoPathsIsReportedAtItsShallowestDepth(): void
    {
        [$parent, $shared] = $this->inDraft(function () {
            $parent = ApiTestOwnedParentObject::create(['Title' => 'Parent']);
            $parent->write();

            $shared = ApiTestOwnedGrandchildObject::create(['Title' => 'Shared']);
            $shared->write();

            $child = ApiTestOwnedChildObject::create([
                'Title' => 'Child',
                'ParentID' => $parent->ID,
                'FeaturedGrandchildID' => $shared->ID,
            ]);
            $child->write();

            // Also reachable directly from the parent — the shallow path.
            $parent->FeaturedGrandchildID = $shared->ID;
            $parent->write();

            return [$parent, $shared];
        });

        $result = $this->inDraft(fn () => $this->walker()->walk($parent));

        $sharedEntries = array_values(array_filter(
            $result,
            fn (array $entry) => get_class($entry['record']) === ApiTestOwnedGrandchildObject::class
                && (int) $entry['record']->ID === (int) $shared->ID
        ));

        $this->assertCount(1, $sharedEntries, 'the shared node must appear exactly once, not once per path');
        $this->assertSame(1, $sharedEntries[0]['depth'], 'the shallower path\'s depth must win');
    }

    public function testAMisconfiguredOwnsEntryIsLoggedNotSilentlyDropped(): void
    {
        Config::modify()->set(ApiTestOwnedParentObject::class, 'owns', ['Children', 'NoSuchRelation']);

        $parent = $this->inDraft(function () {
            $parent = ApiTestOwnedParentObject::create(['Title' => 'Parent']);
            $parent->write();

            return $parent;
        });

        $logger = new class implements \Psr\Log\LoggerInterface {
            use \Psr\Log\LoggerTrait;

            public array $messages = [];

            public function log($level, $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $walker = OwnedTreeWalker::create();
        $walker->logger = $logger;

        $walker->walk($parent);

        $this->assertNotEmpty($logger->messages);
        $this->assertStringContainsString('NoSuchRelation', $logger->messages[0]);
    }
}
