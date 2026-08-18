<?php

namespace Dynamic\ContentApi\Verify;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Walks a `DataObject`'s `$owns` tree recursively — the module's single,
 * reusable primitive for "everything this record's own publish/unpublish
 * cascade is responsible for." No such walker existed anywhere in this
 * module before #120; the closest precedent is the `sheboygan-youth-
 * sailing-installer` project's own hand-rolled `DraftLiveParityTask::
 * collectMissingOwned()` and `GoLivePublishTask::publishOwnedRecursive()`
 * — this generalizes that shape (built for #120's parity endpoint, and the
 * foundation #119's owned-relation publish/unpublish cascade will need).
 *
 * Corrections versus that prototype, all load-bearing:
 * - **Cycle guard.** The prototype recurses with no visited-set at all — a
 *   cyclic `$owns` graph (a real possibility: nothing in SilverStripe
 *   itself prevents declaring `$owns` in a loop) would recurse forever.
 * - **Depth cap**, independent of the cycle guard — a very deep (but
 *   acyclic) owned tree is bounded rather than walked without limit.
 * - **An unversioned intermediate is walked THROUGH, not pruned at.**
 *   `RecursivePublishable::rollbackRelations()` (the real framework
 *   mechanism `publishRecursive()` etc. build on) recurses into an
 *   unversioned owned record's own owned relations — it only skips
 *   *reporting* draft/live state for something that has none. Pruning
 *   the whole branch there (this class's own first cut) would silently
 *   miss a versioned, draft-only descendant sitting one unversioned hop
 *   below an otherwise-live tree — exactly the bug class this walker
 *   exists to help catch.
 * - **The visited-set tracks the SHALLOWEST depth a node was reached
 *   at, not just "seen or not."** `$owns` can form a diamond (the same
 *   record reachable via two different owned paths at different
 *   depths — e.g. a shared `File` owned both directly by a page and by
 *   one of its elements) — a plain seen/unseen guard would report
 *   whichever path happened to reach it first, and if that was the
 *   deeper one, the node's own further owned relations could go
 *   unexpanded even though a shallower, still-in-budget path to it
 *   exists. Re-processing when a shallower path is found is still
 *   guaranteed to terminate: depth only ever decreases on a re-visit,
 *   bounded below by 0.
 *
 * The relation-list config it walks is a {@see walk()} parameter rather than
 * hardcoded to `$owns` (#174) — `$cascade_duplicates` has the same shape and
 * needs the same cycle/depth/diamond handling, and re-deriving this walk a
 * second time for it is exactly the duplication #119 exists to stop.
 */
class OwnedTreeWalker
{
    use Configurable;
    use Injectable;

    /**
     * Independent of the cycle guard — bounds a very deep (but acyclic)
     * owned tree. High enough that no real project's `$owns` nesting
     * should ever hit it in practice.
     */
    private static int $max_depth = 25;

    private static array $dependencies = [
        'logger' => '%$' . LoggerInterface::class,
    ];

    public ?LoggerInterface $logger = null;

    /**
     * @param string $relationConfigKey which relation-list config to walk.
     *   Defaults to `owns` — the publish/unpublish-cascade question this
     *   class was built for. `cascade_duplicates` is the other real caller
     *   (#174): `DataObject::duplicate()` consults exactly that config to
     *   decide which relations to copy, so walking it answers "what did a
     *   duplicate() just create underneath this record" with no risk of
     *   drifting from what was actually created. The walk is otherwise
     *   identical — both configs are `[relationName, ...]` lists over the
     *   same relation types.
     * @return array<int, array{record: DataObject, depth: int}> every
     *   descendant reachable via that config, in walk order — the root
     *   itself is never included
     */
    public function walk(DataObject $root, ?int $maxDepth = null, string $relationConfigKey = 'owns'): array
    {
        $maxDepth ??= (int) static::config()->get('max_depth');
        $visited = [];
        $result = [];

        $this->collect($root, 0, $maxDepth, $relationConfigKey, $visited, $result);

        return array_values($result);
    }

    /**
     * @param array<string, int> $visited "ClassName:id" => the shallowest
     *   depth reached at so far, by reference
     * @param array<int, array{record: DataObject, depth: int}> $result by
     *   reference; may briefly contain gaps (unset(), not re-indexed) when
     *   a shallower path replaces a deeper entry for the same node —
     *   walk() re-indexes with array_values() once collection finishes
     */
    protected function collect(
        DataObject $record,
        int $depth,
        int $maxDepth,
        string $relationConfigKey,
        array &$visited,
        array &$result
    ): void {
        $key = get_class($record) . ':' . $record->ID;

        if (isset($visited[$key]) && $visited[$key] <= $depth) {
            // Already reached this exact node at an equal or shallower
            // depth — re-processing it here can't discover anything new.
            return;
        }

        $visited[$key] = $depth;

        $isVersioned = $record->hasExtension(Versioned::class);

        if ($isVersioned && $depth > 0) {
            // A re-visit at a shallower depth than before replaces the
            // earlier (deeper, now-stale) entry rather than appending a
            // duplicate.
            foreach ($result as $index => $existing) {
                if (
                    get_class($existing['record']) === get_class($record)
                    && (int) $existing['record']->ID === (int) $record->ID
                ) {
                    unset($result[$index]);
                    break;
                }
            }

            $result[] = ['record' => $record, 'depth' => $depth];
        }

        if ($depth >= $maxDepth) {
            return;
        }

        foreach ((array) $record->config()->get($relationConfigKey) as $relationName) {
            if (!$record->hasMethod($relationName)) {
                // A misconfigured entry (a typo, a relation that was
                // renamed/removed) would otherwise silently prune this
                // branch of the tree with no indication anything was
                // wrong — worth a warning even though it isn't fatal to
                // the walk itself.
                $this->logger->warning(sprintf(
                    '%s declares "%s" in $%s, but has no such method — that branch cannot be walked.',
                    get_class($record),
                    $relationName,
                    $relationConfigKey
                ));

                continue;
            }

            $related = $record->$relationName();

            if ($related instanceof DataObject) {
                if ($related->exists()) {
                    $this->collect($related, $depth + 1, $maxDepth, $relationConfigKey, $visited, $result);
                }

                continue;
            }

            if (is_iterable($related)) {
                foreach ($related as $child) {
                    if ($child instanceof DataObject) {
                        $this->collect($child, $depth + 1, $maxDepth, $relationConfigKey, $visited, $result);
                    }
                }
            }
        }
    }
}
