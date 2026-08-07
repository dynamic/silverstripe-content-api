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
 * A draft-only owned descendant only counts as a genuine mismatch
 * (`ok: false`) when the ROOT is live — the exact bug class this endpoint
 * exists to catch (a live page 404ing because some piece it owns, three
 * levels down, never got published). When the root itself isn't live,
 * every owned descendant being draft too is simply consistent, not a
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
        $includeOwned = strtolower((string) ($request->getVar('include') ?: 'owned')) !== 'none';
        $depthParam = $request->getVar('depth');
        $depth = $depthParam !== null ? max(0, (int) $depthParam) : null;

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
     * @return array{liveExists: bool, fields: array<string, array{draft: mixed, live: mixed, match: bool}>,
     *   ok: bool, report: array<int, array{label: string, ok: bool, message: string}>}
     */
    protected function compareFields(string $className, DataObject $draftRecord): array
    {
        $label = sprintf('%s #%d', $className, $draftRecord->ID);

        $liveRecord = $this->withStage($className, 'live', function () use ($className, $draftRecord) {
            return DataObject::get($className)->byID($draftRecord->ID);
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

            $isLive = $this->withStage($className, 'live', function () use ($className, $record) {
                return DataObject::get($className)->filter('ID', $record->ID)->exists();
            });

            $out[] = [
                'className' => $className,
                'id' => (int) $record->ID,
                'depth' => $depth,
                'live' => $isLive,
            ];

            // Only a genuine mismatch when the root itself is live — a
            // draft-only owned descendant under a draft-only (or
            // never-published) root is consistent, not a problem. This is
            // exactly the invariant a live page 404ing on a draft-only
            // owned piece depends on: root live + descendant not live.
            $entryOk = $isLive || !$rootIsLive;

            if (!$entryOk) {
                $ok = false;
            }

            $report[] = [
                'label' => sprintf('%s #%d (depth %d)', $className, $record->ID, $depth),
                'ok' => $entryOk,
                'message' => $isLive
                    ? 'live'
                    : ($rootIsLive ? 'draft-only — not live, but the root is' : 'draft-only, consistent with the root'),
            ];
        }

        return ['owned' => $out, 'ok' => $ok, 'report' => $report];
    }
}
