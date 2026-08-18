<?php

namespace Dynamic\ContentApi\Tests\Verify;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestDuplicateChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestDuplicateLeafObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestDuplicateRootObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestDuplicateUnversionedObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Tests\Stub\ApiTestElementItem;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedGrandchildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnedParentObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestOwnsCycleObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPlainChildObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestVersionedObject;
use Dynamic\ContentApi\Verify\OwnedTreeWalker;
use SilverStripe\Core\Config\Config;
use SilverStripe\Versioned\Versioned;

/**
 * Direct coverage for #120's `OwnedTreeWalker` — the module's first `$owns`
 * walker (no class in `src/` ever declared `$owns` before this feature; see
 * the stub docblocks for why the test fixtures are synthetic). Several
 * corrections versus the `DraftLiveParityTask` prototype this generalizes
 * are the whole point of a dedicated test file — see {@see OwnedTreeWalker}'s
 * own class docblock for the full list (cycle guard, depth cap, walking
 * through rather than pruning at an unversioned intermediate, and resolving
 * a diamond in `$owns` to its shallowest reachable depth); each has its own
 * test below.
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

    /**
     * The same diamond as above, but under a `max_depth` cap that cuts
     * off the DEEP path before the shallow one reunites with it — proves
     * the re-processing a shallower re-visit triggers still respects the
     * cap correctly (re-expanding at depth 1, which is within budget,
     * rather than either re-using the stale depth-2 pass's now-irrelevant
     * "already at the cap" state or blowing past the cap entirely).
     */
    public function testADiamondReunitedWithinBudgetIsStillReportedAtItsShallowestDepth(): void
    {
        Config::modify()->set(OwnedTreeWalker::class, 'max_depth', 2);

        [$parent, $child, $shared] = $this->inDraft(function () {
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

            // Deep path: Parent->Children->Child (depth 1)->Grandchildren
            // is NOT used here — Child's FeaturedGrandchild (depth 2) is
            // the deep path to the shared node, reached first since
            // 'Children' is walked before the parent's own
            // 'FeaturedGrandchild'.
            $parent->FeaturedGrandchildID = $shared->ID;
            $parent->write();

            return [$parent, $child, $shared];
        });

        $result = $this->inDraft(fn () => $this->walker()->walk($parent));

        $depths = [];

        foreach ($result as $entry) {
            $depths[get_class($entry['record']) . ':' . $entry['record']->ID] = $entry['depth'];
        }

        $this->assertSame(1, $depths[ApiTestOwnedChildObject::class . ':' . $child->ID]);
        $this->assertSame(
            1,
            $depths[ApiTestOwnedGrandchildObject::class . ':' . $shared->ID],
            'the shared node must be re-expanded at the shallower depth, not stuck at the deep path\'s depth 2'
        );

        foreach ($result as $entry) {
            $this->assertLessThanOrEqual(2, $entry['depth'], 'no entry may exceed the configured max_depth');
        }
    }

    public function testAMisconfiguredOwnsEntryIsLoggedNotSilentlyDropped(): void
    {
        Config::modify()->set(ApiTestOwnedParentObject::class, 'owns', ['Children', 'NoSuchRelation']);

        [$parent, $child] = $this->inDraft(function () {
            $parent = ApiTestOwnedParentObject::create(['Title' => 'Parent']);
            $parent->write();

            $child = ApiTestOwnedChildObject::create(['Title' => 'Child', 'ParentID' => $parent->ID]);
            $child->write();

            return [$parent, $child];
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

        $result = $walker->walk($parent);

        $this->assertNotEmpty($logger->messages);
        $this->assertStringContainsString('NoSuchRelation', $logger->messages[0]);

        // The bad relation logs a warning but must not stop the walk —
        // 'Children' (declared alongside it in the same $owns array) is
        // still a valid, walkable relation and must still be reached.
        $this->assertCount(
            1,
            $result,
            'a misconfigured $owns entry must not prevent a valid sibling from being walked'
        );
        $this->assertSame((int) $child->ID, (int) $result[0]['record']->ID);
    }

    /**
     * #174: `walkDuplicates()` reads `$cascade_duplicates` where `walk()`
     * reads `$owns`. `ApiTestElement` is the discriminating fixture — it
     * declares `$cascade_duplicates` and no `$owns` at all, so the same
     * record walks to nothing one way and to its children the other.
     * Everything else about the walk (cycle guard, depth cap, dropping
     * unversioned records) is shared code and is covered above.
     */
    public function testWalkDuplicatesReadsCascadeDuplicatesNotOwns(): void
    {
        [$element, $item] = $this->inDraft(function () {
            $element = ApiTestElement::create(['Title' => 'Element']);
            $element->write();

            $item = ApiTestElementItem::create(['Title' => 'Child', 'ElementID' => $element->ID]);
            $item->write();

            $plain = ApiTestPlainChildObject::create(['Title' => 'Unversioned', 'ElementID' => $element->ID]);
            $plain->write();

            return [$element, $item];
        });

        $this->assertCount(
            0,
            $this->inDraft(fn () => $this->walker()->walk($element)),
            'ApiTestElement declares no $owns — walk() must find nothing'
        );

        $result = $this->inDraft(fn () => $this->walker()->walkDuplicates($element));

        // Only the versioned child: PlainItems is walked but, like any
        // unversioned record, never emitted.
        $this->assertCount(1, $result);
        $this->assertSame((int) $item->ID, (int) $result[0]['record']->ID);
        $this->assertSame(1, $result[0]['depth']);
    }

    /**
     * @return array{0: ApiTestDuplicateRootObject, 1: ApiTestVersionedObject,
     *   2: ApiTestDuplicateChildObject, 3: ApiTestDuplicateLeafObject,
     *   4: ApiTestVersionedObject}
     */
    private function duplicateFixture(): array
    {
        return $this->inDraft(function () {
            $root = ApiTestDuplicateRootObject::create(['Title' => 'Root']);
            $root->write();

            $shared = ApiTestVersionedObject::create(['Title' => 'Shared']);
            $shared->write();
            $root->Shared()->add($shared);

            $note = ApiTestVersionedObject::create(['Title' => 'Note']);
            $note->write();

            $child = ApiTestDuplicateChildObject::create([
                'Title' => 'Child',
                'RootID' => $root->ID,
                'NoteID' => $note->ID,
            ]);
            $child->write();

            $leaf = ApiTestDuplicateLeafObject::create(['Title' => 'Leaf', 'ChildID' => $child->ID]);
            $leaf->write();

            return [$root, $shared, $child, $leaf, $note];
        });
    }

    /**
     * A many_many named in `$cascade_duplicates` must NOT be walked, even
     * though `walk()` follows the identical relation under `$owns`.
     *
     * `DataObject::duplicateManyManyRelation()` copies the relation *links*
     * (`$dest->add($item)`) — the clone points at the very same pre-existing
     * records — where every other relation type clones the record itself
     * (`$item->duplicate(false)`). Publishing a link-copied target would push
     * records live that the duplicate never created, and would newly
     * `403 FORBIDDEN_CLASS` on shared classes no project allowlists.
     */
    public function testWalkDuplicatesSkipsManyManyWhileWalkStillFollowsIt(): void
    {
        [$root, $shared] = $this->duplicateFixture();

        $duplicated = $this->inDraft(fn () => $this->walker()->walkDuplicates($root));

        $this->assertNotContains(
            $this->key($shared),
            array_map(fn ($e) => $this->key($e['record']), $duplicated),
            'a many_many is link-copied, not cloned — walkDuplicates() must skip it'
        );

        // The same relation under $owns is still walked: skipping many_many
        // belongs to the duplicate question, not to the walker generally.
        $owned = $this->inDraft(fn () => $this->walker()->walk($root));

        $this->assertSame(
            [$this->key($shared)],
            array_map(fn ($e) => $this->key($e['record']), $owned),
            'walk() must be unaffected by the many_many skip'
        );
    }

    /**
     * The #174 regression that a naive `$cascade_duplicates` read would
     * reintroduce: `ApiTestDuplicateChildObject` declares NO
     * `$cascade_duplicates`, so `RecursivePublishable::onBeforeDuplicate()`
     * substitutes `$owns ∩ (many_many + belongs_to + has_many)` and
     * `duplicate()` clones `Leaves` anyway.
     *
     * Nothing else covers those grandchildren — a page-level `$owns` walk
     * stops at an element declaring no `$owns` — so losing them here loses
     * them entirely, silently, which is the exact failure mode #174 is about.
     *
     * Also pins that the fallback excludes has_one: `Note` is in the child's
     * `$owns` but must not be treated as duplicated, matching the framework's
     * own deliberate exclusion (an owned has_one can be shared
     * non-exclusively by clone and original).
     */
    public function testWalkDuplicatesFollowsTheOwnsFallbackIntoGrandchildren(): void
    {
        [$root, , $child, $leaf, $note] = $this->duplicateFixture();

        $result = $this->inDraft(fn () => $this->walker()->walkDuplicates($root));

        // Keyed by class AND id: these stubs each start their own table, so
        // the child and the leaf both have ID 1 and an id-only map would
        // silently collapse them.
        $depths = [];
        foreach ($result as $entry) {
            $depths[$this->key($entry['record'])] = $entry['depth'];
        }

        $this->assertArrayHasKey($this->key($child), $depths);
        $this->assertSame(1, $depths[$this->key($child)]);

        $this->assertArrayHasKey(
            $this->key($leaf),
            $depths,
            'the depth-2 grandchild duplicate() creates via the $owns fallback must be walked'
        );
        $this->assertSame(2, $depths[$this->key($leaf)]);

        $this->assertArrayNotHasKey(
            $this->key($note),
            $depths,
            'the fallback excludes has_one, matching RecursivePublishable::onBeforeDuplicate()'
        );
    }

    private function key(\SilverStripe\ORM\DataObject $record): string
    {
        return get_class($record) . ':' . (int) $record->ID;
    }

    /**
     * `cascade_duplicates: false` means "duplicate nothing" — a supported
     * value both `duplicate()` and `onBeforeDuplicate()` honour explicitly.
     * It must not fall through to the `$owns` fallback (which is for an
     * *empty* list, a different statement) and must not be cast to `[false]`.
     */
    public function testCascadeDuplicatesFalseMeansNothingRatherThanTheOwnsFallback(): void
    {
        $child = $this->inDraft(function () {
            $child = ApiTestDuplicateChildObject::create(['Title' => 'Child']);
            $child->write();

            $leaf = ApiTestDuplicateLeafObject::create(['Title' => 'Leaf', 'ChildID' => $child->ID]);
            $leaf->write();

            return $child;
        });

        // Without the override the $owns fallback finds 'Leaves'.
        $this->assertCount(1, $this->inDraft(fn () => $this->walker()->walkDuplicates($child)));

        Config::modify()->set(ApiTestDuplicateChildObject::class, 'cascade_duplicates', false);

        $this->assertSame(
            [],
            $this->inDraft(fn () => $this->walker()->walkDuplicates($child)),
            'false is "duplicate nothing", not "fall back to $owns"'
        );
    }

    /**
     * The duplicates-mode counterpart of
     * {@see testAnUnversionedIntermediateIsWalkedThroughNotPrunedAt}.
     *
     * `RecursivePublishable` is attached to `DataObject` itself
     * (`versioned/_config/versionedownership.yml`), so its
     * `onBeforeDuplicate()` `$owns` fallback fires for unversioned records
     * too — `duplicate()` really does clone an unversioned intermediate's
     * owned has_many children. Gating that fallback on `Versioned` (an
     * earlier cut did) pruned the branch here and silently lost the
     * versioned leaf below it, which is #174's own failure mode one node
     * type over.
     */
    public function testWalkDuplicatesFollowsTheFallbackThroughAnUnversionedIntermediate(): void
    {
        [$root, $leaf] = $this->inDraft(function () {
            $root = ApiTestDuplicateRootObject::create(['Title' => 'Root']);
            $root->write();

            $wrapper = ApiTestDuplicateUnversionedObject::create(['Title' => 'Wrapper', 'RootID' => $root->ID]);
            $wrapper->write();

            $leaf = ApiTestDuplicateLeafObject::create(['Title' => 'Wrapped leaf', 'WrapperID' => $wrapper->ID]);
            $leaf->write();

            return [$root, $leaf];
        });

        $result = $this->inDraft(fn () => $this->walker()->walkDuplicates($root));
        $keys = array_map(fn ($e) => $this->key($e['record']), $result);

        $this->assertContains(
            $this->key($leaf),
            $keys,
            'a versioned record below an unversioned intermediate must still be walked'
        );

        // The intermediate itself is walked through, never emitted — it has
        // no draft/live state to publish.
        foreach ($result as $entry) {
            $this->assertNotInstanceOf(ApiTestDuplicateUnversionedObject::class, $entry['record']);
        }
    }

    /**
     * The misconfigured-entry warning names the config it actually read.
     * Without this, a typo in `$cascade_duplicates` would send someone
     * hunting through `$owns` instead.
     */
    public function testTheMisconfiguredEntryWarningNamesCascadeDuplicates(): void
    {
        $element = $this->inDraft(function () {
            $element = ApiTestElement::create(['Title' => 'Element']);
            $element->write();

            return $element;
        });

        Config::modify()->set(ApiTestElement::class, 'cascade_duplicates', ['NoSuchRelation']);

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

        $this->inDraft(fn () => $walker->walkDuplicates($element));

        $this->assertNotEmpty($logger->messages);
        $this->assertStringContainsString('cascade_duplicates', $logger->messages[0]);
        $this->assertStringNotContainsString('in $owns', $logger->messages[0]);
    }

    /**
     * #119: an excluded node is pruned at itself, AND its own owned
     * relations are never followed either — a grandchild reachable ONLY
     * through an excluded child must not leak into `targets` (it isn't
     * independently owned by the parent, so it has no other path in) NOR
     * into `skipped` (it was never visited at all, so there's nothing to
     * report about it specifically).
     */
    public function testWalkOwnedExcludingPrunesAnExcludedNodeAndItsOwnSubtree(): void
    {
        [$parent, $child, $grandchild] = $this->inDraft(function () {
            $parent = ApiTestOwnedParentObject::create(['Title' => 'Excluding Parent']);
            $parent->write();

            $child = ApiTestOwnedChildObject::create(['Title' => 'Excluding Child', 'ParentID' => $parent->ID]);
            $child->write();

            $grandchild = ApiTestOwnedGrandchildObject::create([
                'Title' => 'Excluding Grandchild',
                'ParentID' => $child->ID,
            ]);
            $grandchild->write();

            return [$parent, $child, $grandchild];
        });

        $result = $this->inDraft(
            fn () => $this->walker()->walkOwnedExcluding($parent, [ApiTestOwnedChildObject::class])
        );

        $targetIDs = array_map(fn ($entry) => (int) $entry['record']->ID, $result['targets']);
        $skippedIDs = array_map(fn ($entry) => (int) $entry['record']->ID, $result['skipped']);

        $this->assertSame(
            [],
            $targetIDs,
            'the excluded child, and everything only reachable through it, must not be a target'
        );
        $this->assertSame([(int) $child->ID], $skippedIDs, 'the excluded node itself is reported');
        $this->assertNotContains(
            (int) $grandchild->ID,
            array_merge($targetIDs, $skippedIDs),
            'a node reachable only through an excluded node is never visited at all — not a target, not skipped'
        );
    }

    /**
     * A record NOT excluded, but reachable only through the excluded
     * child's `$owns`, is genuinely unreachable — confirmed by the parent's
     * OWN direct has_one, `FeaturedGrandchild`, still surfacing normally
     * when it's a different, non-excluded record than the one hanging off
     * the pruned child.
     */
    public function testWalkOwnedExcludingLeavesNonExcludedBranchesIntact(): void
    {
        [$parent, $featured] = $this->inDraft(function () {
            $featured = ApiTestOwnedGrandchildObject::create(['Title' => 'Still Reachable']);
            $featured->write();

            $parent = ApiTestOwnedParentObject::create([
                'Title' => 'Mixed Branches Parent',
                'FeaturedGrandchildID' => $featured->ID,
            ]);
            $parent->write();

            $child = ApiTestOwnedChildObject::create(['Title' => 'Pruned Child', 'ParentID' => $parent->ID]);
            $child->write();

            return [$parent, $featured];
        });

        $result = $this->inDraft(
            fn () => $this->walker()->walkOwnedExcluding($parent, [ApiTestOwnedChildObject::class])
        );

        $targetIDs = array_map(fn ($entry) => (int) $entry['record']->ID, $result['targets']);

        $this->assertSame(
            [(int) $featured->ID],
            $targetIDs,
            'a branch not routed through the excluded class must still be walked normally'
        );
    }

    /**
     * `walkOwnedExcluding()` is new API — confirms it doesn't change
     * `walk()`'s own behavior (no excludedClasses argument reaches it).
     */
    public function testWalkOwnedExcludingWithNoExclusionsMatchesPlainWalk(): void
    {
        [$parent, $child] = $this->inDraft(function () {
            $parent = ApiTestOwnedParentObject::create(['Title' => 'No Exclusions Parent']);
            $parent->write();

            $child = ApiTestOwnedChildObject::create(['Title' => 'No Exclusions Child', 'ParentID' => $parent->ID]);
            $child->write();

            return [$parent, $child];
        });

        $plainWalk = $this->inDraft(fn () => $this->walker()->walk($parent));
        $result = $this->inDraft(fn () => $this->walker()->walkOwnedExcluding($parent, []));

        $this->assertSame(
            array_map(fn ($entry) => (int) $entry['record']->ID, $plainWalk),
            array_map(fn ($entry) => (int) $entry['record']->ID, $result['targets'])
        );
        $this->assertSame([], $result['skipped']);
    }
}
