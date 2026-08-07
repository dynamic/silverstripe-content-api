<?php

namespace Dynamic\ContentApi\Verify;

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
 * Two corrections versus that prototype, both load-bearing:
 * - **Cycle guard.** The prototype recurses with no visited-set at all — a
 *   cyclic `$owns` graph (a real possibility: nothing in SilverStripe
 *   itself prevents declaring `$owns` in a loop) would recurse forever.
 * - **Depth cap**, independent of the cycle guard — a very deep (but
 *   acyclic) owned tree is bounded rather than walked without limit.
 *
 * Same core rule as the prototype: an owned relation is only followed for
 * a record that itself `hasExtension(Versioned::class)` — a
 * `$owns`-declared relation to an unversioned class has nothing this
 * walker cares about (no draft/live state to report or cascade), so that
 * branch is pruned rather than walked past.
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

    /**
     * @return array<int, array{record: DataObject, depth: int}> every owned
     *   descendant, in walk order — the root itself is never included
     */
    public function walk(DataObject $root, ?int $maxDepth = null): array
    {
        $maxDepth ??= (int) static::config()->get('max_depth');
        $visited = [];
        $result = [];

        $this->collect($root, 0, $maxDepth, $visited, $result);

        return $result;
    }

    /**
     * @param array<string, true> $visited keyed "ClassName:id", by reference
     * @param array<int, array{record: DataObject, depth: int}> $result by reference
     */
    protected function collect(DataObject $record, int $depth, int $maxDepth, array &$visited, array &$result): void
    {
        // Matches the prototype's own first check, verbatim in spirit:
        // an unversioned record has no draft/live state this walker (or
        // anything consuming its output) cares about, so its own owned
        // relations are never followed either — this branch of the tree
        // simply ends here.
        if (!$record->hasExtension(Versioned::class)) {
            return;
        }

        $key = get_class($record) . ':' . $record->ID;

        if (isset($visited[$key])) {
            return;
        }

        $visited[$key] = true;

        if ($depth > 0) {
            $result[] = ['record' => $record, 'depth' => $depth];
        }

        if ($depth >= $maxDepth) {
            return;
        }

        foreach ((array) $record->config()->get('owns') as $relationName) {
            if (!$record->hasMethod($relationName)) {
                continue;
            }

            $related = $record->$relationName();

            if ($related instanceof DataObject) {
                if ($related->exists()) {
                    $this->collect($related, $depth + 1, $maxDepth, $visited, $result);
                }

                continue;
            }

            if (is_iterable($related)) {
                foreach ($related as $child) {
                    if ($child instanceof DataObject) {
                        $this->collect($child, $depth + 1, $maxDepth, $visited, $result);
                    }
                }
            }
        }
    }
}
