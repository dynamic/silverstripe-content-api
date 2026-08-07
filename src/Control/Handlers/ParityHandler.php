<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Verify\OwnedTreeWalker;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * `GET records/$ClassRef/$ID/parity` (#120) — "does this record, and
 * everything it `$owns`, match between draft and live, and where do they
 * differ?" Answers in one call what the module's users previously had to
 * hand-roll per project (`DraftLiveParityTask`, ~230 lines on
 * `sheboygan-youth-sailing-installer`, explicitly documented there as "the
 * canonical step after any go-live publish" — a recurring need, not a
 * one-off script).
 *
 * Two independent checks, both driven by the same DRAFT read:
 * - **Root fields**: a configurable set of the record's own fields
 *   (`Title`/`ParentID`/`ClassName`/`ShowInMenus`/`URLSegment`/`Sort` by
 *   default, filtered to whichever the class actually declares), draft vs
 *   live. The record not existing on live at all is a legitimate state
 *   (`liveExists: false`), not a failure — a placeholder or a page never
 *   published is not "broken."
 * - **Owned tree** (`?include=owned`, the default; `?include=none` skips
 *   it; `?depth=N` caps how far {@see OwnedTreeWalker} recurses): every
 *   record this one `$owns`, recursively, with its own live/draft status
 *   and depth — but NOT a field-level diff of each one (matching the
 *   prototype task's own scope: presence-on-live only for owned
 *   descendants, full field comparison only for the root).
 *
 * An owned descendant's live status disagreeing with the root's own is a
 * genuine mismatch (`ok: false`): root live + descendant draft-only is
 * the exact bug class this endpoint primarily exists to catch (a live
 * page 404ing because some piece it owns, several levels down, never got
 * published); root NOT live + descendant live is the mirror image
 * (stranded content the root's own publish history should have carried
 * or never published). Root live + descendant live, or root not-live +
 * descendant not-live, are both consistent — an owned tree that's
 * uniformly draft because the whole branch was never published is not a
 * problem.
 */
class ParityHandler
{
    use Configurable;
    use Injectable;
    use StageAwareTrait;

    /**
     * Filtered per class to whichever of these fields it actually declares
     * — a class missing `ShowInMenus` (anything that isn't `SiteTree`)
     * simply skips that entry rather than erroring.
     */
    private static array $default_fields = ['Title', 'ParentID', 'ClassName', 'ShowInMenus', 'URLSegment', 'Sort'];

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'policy' => '%$' . PermissionPolicy::class,
        'walker' => '%$' . OwnedTreeWalker::class,
        'reader' => '%$' . RecordsHandler::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?PermissionPolicy $policy = null;

    public ?OwnedTreeWalker $walker = null;

    public ?RecordsHandler $reader = null;

    public function parity(HTTPRequest $request, AuthContext $context): array
    {
        $className = $this->registry->resolve((string) $request->param('ClassRef'));
        $this->policy->checkClassAccess($className, 'read', $context->member);

        if (!DataObject::singleton($className)->hasExtension(Versioned::class)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('"%s" is not a versioned class — draft/live parity has nothing to compare.', $className)
            );
        }

        $idParam = (string) $request->param('ID');
        $includeOwned = $this->resolveInclude($request);
        $depth = $this->resolveDepth($request);

        $draftRecord = $this->withStage($className, 'draft', function () use ($className, $idParam, $context) {
            $record = $this->reader->fetchRecord($className, $idParam);
            $this->policy->checkRecordAccess($record, 'read', $context->member);

            return $record;
        });

        // Walked from the DRAFT copy — the draft tree is always the
        // canonical/superset one to walk (an owned descendant that exists
        // on live but was since removed from draft is a documented gap,
        // same as the installer prototype this generalizes).
        $owned = $includeOwned
            ? $this->withStage($className, 'draft', function () use ($draftRecord, $depth) {
                return $this->walker->walk($draftRecord, $depth);
            })
            : [];

        // Authorize every owned record before emitting anything about it —
        // same check-everything-before-emitting-anything precedent as
        // PublishOrchestrator::collectSubtreeTargets().
        foreach ($owned as $entry) {
            $entryClass = get_class($entry['record']);
            $this->policy->checkClassAccess($entryClass, 'read', $context->member);
            $this->policy->checkRecordAccess($entry['record'], 'read', $context->member);
        }

        $fieldResult = $this->compareFields($className, $draftRecord);
        $ownedResult = $this->compareOwned($owned, $fieldResult['liveExists']);

        return [
            'data' => [
                'id' => (int) $draftRecord->ID,
                'className' => $className,
                'liveExists' => $fieldResult['liveExists'],
                'fields' => $fieldResult['fields'],
                'owned' => $ownedResult['owned'],
                'ok' => $fieldResult['ok'] && $ownedResult['ok'],
                'report' => array_merge($fieldResult['report'], $ownedResult['report']),
            ],
            'meta' => ['operation' => 'parity'],
        ];
    }

    /**
     * `?include=owned` (default) or `?include=none` — anything else is
     * rejected rather than silently treated as "owned", matching
     * `RecordsHandler::resolveStage()`'s convention for an unrecognized
     * enum value.
     */
    protected function resolveInclude(HTTPRequest $request): bool
    {
        $raw = $request->getVar('include');

        // `=== ''`, not `?:` — `?:` treats the string "0" as falsy too, so
        // an explicit "?include=0" would have silently fallen back to
        // "owned" instead of being rejected as the malformed value it is.
        // Same distinction resolveDepth() makes for the same reason.
        $include = ($raw === null || $raw === '') ? 'owned' : strtolower((string) $raw);

        if (!in_array($include, ['owned', 'none'], true)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'The "include" parameter must be "owned" or "none".');
        }

        return $include === 'owned';
    }

    /**
     * Absent or empty means "use `OwnedTreeWalker`'s configured default" —
     * `null`, not `0`. A present-but-invalid value (non-digit, e.g. a typo
     * or an empty-string edge case from a query-string builder) is
     * rejected rather than silently coercing to `(int) '' === 0`, which
     * would disable the owned walk entirely without any indication why.
     */
    protected function resolveDepth(HTTPRequest $request): ?int
    {
        $raw = $request->getVar('depth');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (!ctype_digit((string) $raw)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'The "depth" parameter must be a non-negative integer.');
        }

        return (int) $raw;
    }

    /**
     * @return array{liveExists: bool, fields: array<string, array{draft: mixed, live: mixed, match: bool}>,
     *   ok: bool, report: array<int, array{label: string, ok: bool, message: string}>}
     */
    protected function compareFields(string $className, DataObject $draftRecord): array
    {
        $label = sprintf('%s #%d', $className, $draftRecord->ID);

        // Queried by base class, not $className: DataQuery filters by
        // ClassName IN (subclassesFor($dataClass)) whenever the query
        // class differs from the base class (DataQuery::
        // getFinalisedQuery()) — with no exemption for a LIVE-stage read.
        // A record converted to a different class between publishes
        // (POST pages/$ID/convert with publish: "none") would otherwise
        // make its own live row invisible to this exact query, silently
        // reporting a genuinely-live, genuinely-divergent record as
        // "liveExists: false" / "ok: true" — the false-clean this
        // endpoint exists to prevent. A real class difference still
        // surfaces correctly afterward, as an ordinary ClassName field
        // mismatch in the comparison below.
        $liveRecord = $this->withStage($className, 'live', function () use ($draftRecord) {
            return DataObject::get($draftRecord->baseClass())->byID($draftRecord->ID);
        });

        if (!$liveRecord) {
            return [
                'liveExists' => false,
                'fields' => [],
                'ok' => true,
                'report' => [[
                    'label' => $label,
                    'ok' => true,
                    'message' => 'not live — draft-only, or never published',
                ]],
            ];
        }

        $schema = DataObject::getSchema();
        $fields = [];
        $mismatches = [];
        $ok = true;

        foreach ((array) static::config()->get('default_fields') as $field) {
            if ($field !== 'ID' && !$schema->fieldSpec($className, $field)) {
                continue;
            }

            $draftValue = $draftRecord->getField($field);
            $liveValue = $liveRecord->getField($field);
            $match = (string) $draftValue === (string) $liveValue;

            $fields[$field] = ['draft' => $draftValue, 'live' => $liveValue, 'match' => $match];

            if (!$match) {
                $ok = false;
                $mismatches[] = sprintf('%s: draft="%s" live="%s"', $field, $draftValue, $liveValue);
            }
        }

        return [
            'liveExists' => true,
            'fields' => $fields,
            'ok' => $ok,
            'report' => [[
                'label' => $label,
                'ok' => $ok,
                'message' => $ok ? 'fields match' : 'field mismatch — ' . implode(', ', $mismatches),
            ]],
        ];
    }

    /**
     * @param array<int, array{record: DataObject, depth: int}> $owned
     * @return array{owned: array<int, array{className: string, id: int, depth: int, live: bool}>,
     *   ok: bool, report: array<int, array{label: string, ok: bool, message: string}>}
     */
    protected function compareOwned(array $owned, bool $rootIsLive): array
    {
        $out = [];
        $report = [];
        $ok = true;

        foreach ($owned as $entry) {
            $record = $entry['record'];
            $className = get_class($record);
            $depth = $entry['depth'];

            // Queried by base class, not the owned record's own concrete
            // class — same reasoning as compareFields()'s live lookup.
            $isLive = $this->withStage($className, 'live', function () use ($record) {
                return DataObject::get($record->baseClass())->filter('ID', $record->ID)->exists();
            });

            $out[] = [
                'className' => $className,
                'id' => (int) $record->ID,
                'depth' => $depth,
                'live' => $isLive,
            ];

            // A genuine parity mismatch is the descendant's live status
            // disagreeing with the root's — not just "descendant not
            // live while the root is." A live descendant under a
            // NON-live root is stranded content (the root's own publish
            // history should have carried the descendant with it, or
            // never published it in the first place), the mirror image
            // of the more common "root live, descendant draft-only" bug
            // this endpoint primarily exists to catch.
            $entryOk = $isLive === $rootIsLive;

            if (!$entryOk) {
                $ok = false;
            }

            $message = match (true) {
                $isLive && $rootIsLive => 'live',
                !$isLive && !$rootIsLive => 'draft-only, consistent with the root',
                !$isLive && $rootIsLive => 'draft-only — not live, but the root is',
                default => 'live — but the root is not (stranded content)',
            };

            $report[] = [
                'label' => sprintf('%s #%d (depth %d)', $className, $record->ID, $depth),
                'ok' => $entryOk,
                'message' => $message,
            ];
        }

        return ['owned' => $out, 'ok' => $ok, 'report' => $report];
    }
}
