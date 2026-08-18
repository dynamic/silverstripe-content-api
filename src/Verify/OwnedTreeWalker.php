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
 * {@see walkDuplicates()} reuses all of that to answer a different question
 * — "what did a `duplicate()` just create here" (#174) — over the relations
 * `DataObject::duplicate()` actually consults. Same shape, same
 * cycle/depth/diamond hazards; re-deriving the walk a second time would
 * duplicate the primitive #119 introduced precisely so there'd be one.
 */
class OwnedTreeWalker
{
    use Configurable;
    use Injectable;

    /**
     * Follow `$owns` — "everything this record's publish cascade is
     * responsible for."
     */
    protected const MODE_OWNS = 'owns';

    /**
     * Follow what `DataObject::duplicate()` creates — see
     * {@see duplicatedRelations()}, which is not simply `$cascade_duplicates`.
     */
    protected const MODE_DUPLICATES = 'duplicates';

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
     * @return array<int, array{record: DataObject, depth: int}> every owned
     *   descendant, in walk order — the root itself is never included
     */
    public function walk(DataObject $root, ?int $maxDepth = null): array
    {
        return $this->walkRelations($root, $maxDepth, self::MODE_OWNS);
    }

    /**
     * Every record a `duplicate()` of `$root` just created beneath it — the
     * publish-side counterpart to what `DataObject::duplicateRelations()`
     * writes (#174).
     *
     * Same traversal as {@see walk()}; only the per-record relation list
     * differs, and it is derived to match `duplicate()` rather than read off
     * a single config — see {@see duplicatedRelations()} for the two ways a
     * naive `$cascade_duplicates` read gets it wrong.
     *
     * @return array<int, array{record: DataObject, depth: int}>
     */
    public function walkDuplicates(DataObject $root, ?int $maxDepth = null): array
    {
        return $this->walkRelations($root, $maxDepth, self::MODE_DUPLICATES);
    }

    protected function walkRelations(DataObject $root, ?int $maxDepth, string $mode): array
    {
        $maxDepth ??= (int) static::config()->get('max_depth');
        $visited = [];
        $result = [];

        $this->collect($root, 0, $maxDepth, $mode, $visited, $result);

        return array_values($result);
    }

    /**
     * The relations to follow out of `$record` for a given walk mode.
     *
     * `MODE_OWNS` is a plain `$owns` read. `MODE_DUPLICATES` reproduces what
     * `DataObject::duplicate()` will actually have created, which is NOT the
     * same as reading `$cascade_duplicates`:
     *
     * - **An empty `$cascade_duplicates` falls back to `$owns`.**
     *   `RecursivePublishable::onBeforeDuplicate()` replaces the relation
     *   list with `$owns ∩ (many_many + belongs_to + has_many)`. has_one is
     *   excluded there deliberately, since an owned has_one may be shared
     *   non-exclusively by clone and original. Without this branch the walk
     *   silently loses grandchildren: a page-level `$owns` walk stops at an
     *   element that declares no `$owns`, so nothing else covers them.
     *
     *   This applies to **every** `DataObject`, versioned or not.
     *   `RecursivePublishable` is attached to `DataObject` itself
     *   (`versioned/_config/versionedownership.yml`), so the hook fires
     *   regardless — and `duplicate()`'s docblock line "if using versioned,
     *   this will additionally failover to `owns` config" means "if
     *   silverstripe/versioned is installed", not "if this record is
     *   versioned". Gating on `Versioned` here (an earlier cut of this
     *   method did) prunes at an unversioned intermediate and loses any
     *   versioned records below it — the same failure #174 exists to close,
     *   one node type over, and inconsistent with {@see collect()}, which
     *   walks through unversioned intermediates rather than stopping at
     *   them.
     * - **many_many relations create nothing.** Every other type is cloned
     *   record-by-record (`duplicateHasManyRelation()` calls
     *   `$item->duplicate(false)`), but `duplicateManyManyRelation()` copies
     *   the relation *links* — `$dest->add($item)` attaches the very same
     *   pre-existing records to the clone. Publishing those on the
     *   duplicate's behalf would push shared, possibly deliberately-draft
     *   records live, and — since `publishOwnedTree()` authorization-checks
     *   every target — would newly `403 FORBIDDEN_CLASS` on classes no
     *   project allowlists. `File` is the reachable case: it carries
     *   `InheritedPermissionsExtension`, whose own `$cascade_duplicates` is
     *   four many_manys to `Group`/`Member`.
     *
     * The fallback list is intersected the same way the framework does it,
     * so a `$owns` entry naming a custom non-relation ownership (which
     * `$owns` tolerates and `$cascade_duplicates` does not) is dropped here
     * rather than reaching the `hasMethod()` warning below.
     *
     * @return array<int, string>
     */
    protected function duplicatedRelations(DataObject $record): array
    {
        $manyMany = (array) $record->manyMany();
        $configured = $record->config()->get('cascade_duplicates');

        // `false` is a supported value meaning "duplicate nothing", honoured
        // by both `duplicate()` and `onBeforeDuplicate()`'s own guard. Casting
        // it would give `[false]` — no fallback, and a `hasMethod(false)`
        // warning naming an empty relation.
        if ($configured === false) {
            return [];
        }

        $relations = (array) $configured;

        // No Versioned check: the framework's own guard is just
        // `if ($relations || $relations === false) return;` — see above.
        if ($relations === []) {
            $relations = array_intersect(
                array_merge(
                    array_keys($manyMany),
                    array_keys((array) $record->belongsTo()),
                    array_keys((array) $record->hasMany())
                ),
                (array) $record->config()->get('owns')
            );
        }

        return array_values(array_filter(
            $relations,
            fn ($relationName) => !array_key_exists($relationName, $manyMany)
        ));
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
        string $mode,
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

        $relations = $mode === self::MODE_DUPLICATES
            ? $this->duplicatedRelations($record)
            : (array) $record->config()->get('owns');

        foreach ($relations as $relationName) {
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
                    $mode === self::MODE_DUPLICATES ? 'cascade_duplicates' : 'owns'
                ));

                continue;
            }

            $related = $record->$relationName();

            if ($related instanceof DataObject) {
                if ($related->exists()) {
                    $this->collect($related, $depth + 1, $maxDepth, $mode, $visited, $result);
                }

                continue;
            }

            if (is_iterable($related)) {
                foreach ($related as $child) {
                    if ($child instanceof DataObject) {
                        $this->collect($child, $depth + 1, $maxDepth, $mode, $visited, $result);
                    }
                }
            }
        }
    }
}
