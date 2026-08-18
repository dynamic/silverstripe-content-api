<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Serialize\RecordSerializer;
use Dynamic\ContentApi\Write\DbTransaction;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Versioned\Versioned;

/**
 * `POST records/$ClassRef/$ID/publish|unpublish|archive` — the versioned
 * stage actions colymba's generic CRUD has no concept of.
 *
 * (Generic create/update/delete live on colymba's `/api` surface; population
 * writes go through batch/compositions, which share the same internal
 * RecordWriter pipeline.)
 */
class RecordActionsHandler
{
    use Injectable;

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'policy' => '%$' . PermissionPolicy::class,
        'serializer' => '%$' . RecordSerializer::class,
        'publisher' => '%$' . PublishOrchestrator::class,
        'reader' => '%$' . RecordsHandler::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?PermissionPolicy $policy = null;

    public ?RecordSerializer $serializer = null;

    public ?PublishOrchestrator $publisher = null;

    public ?RecordsHandler $reader = null;

    public function recordAction(HTTPRequest $request, AuthContext $context): array
    {
        $className = $this->registry->resolve((string) $request->param('ClassRef'));
        $action = (string) $request->param('RecordAction');

        if (!in_array($action, ['publish', 'unpublish', 'archive'], true)) {
            throw new ApiError(
                ErrorCode::NOT_FOUND,
                sprintf('Unknown record action "%s" — use publish, unpublish or archive.', $action)
            );
        }

        // Archive is a soft-delete from both stages — gate it on the 'delete'
        // verb (canDelete() at record level, the class's delete grant at class
        // level), not 'action'/canEdit(). publish/unpublish stay on 'action'
        // since those really are edit operations. See #45.
        $verb = $action === 'archive' ? 'delete' : 'action';

        $this->policy->checkClassAccess($className, $verb, $context->member);
        $body = $this->jsonBody($request);

        // #80: unpublish's `force: true` bypasses the descendant-cascade
        // guard, and PublishOrchestrator::unpublish() then hits the exact
        // same SiteTree::onBeforeDelete() cascade archive/delete does — a
        // delete-shaped outcome, gated everywhere else in the module
        // (archive above; the batch delete op via RecordWriter::delete())
        // on 'delete', not 'action'. Checked here (class-level, once the
        // body is parseable) in addition to — not instead of — the
        // 'action' check above: plain unpublish stays reachable on
        // 'action' alone, only the forced/cascading variant also needs
        // 'delete'.
        //
        // Gated by forceCouldStrandDescendants(), not just "$action ===
        // 'unpublish' && force" — #89 scoped the guard force actually
        // bypasses to SiteTree classes with enforce_strict_hierarchy on;
        // requiring 'delete' unconditionally would demand a verb for a
        // bypass that was never going to cascade anything on every other
        // class, a real breaking change for a client that defensively
        // always sends force:true.
        $forceUnpublish = $action === 'unpublish'
            && !empty($body['force'])
            && $this->publisher->forceCouldStrandDescendants($className);

        if ($forceUnpublish) {
            $this->policy->checkClassAccess($className, 'delete', $context->member);
        }

        // #130: archive never supports dryRun, regardless of any mode —
        // reject outright rather than silently performing the real,
        // irreversible action. publish's and unpublish's own mode-specific
        // dryRun rules are checked inline below, once $mode is known, so
        // the error can name which mode was actually the problem.
        if ($action === 'archive' && !empty($body['dryRun'])) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, '"dryRun" is not supported on "archive".');
        }

        // #102: liveOnly is a Hierarchy-tree concept ("don't resurrect a
        // branch deliberately taken offline") that only ever applies to
        // publish mode=subtree — meaningless for unpublish/archive, and
        // refused outright rather than silently ignored for the same
        // reason dryRun is.
        if ($action !== 'publish' && !empty($body['liveOnly'])) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, '"liveOnly" is not supported on "' . $action . '".');
        }

        return $this->inDraft(function () use (
            $request,
            $className,
            $action,
            $body,
            $context,
            $verb,
            $forceUnpublish
        ) {
            $record = $this->reader->fetchRecord($className, (string) $request->param('ID'));
            $this->policy->checkRecordAccess($record, $verb, $context->member);

            if ($forceUnpublish) {
                $this->policy->checkRecordAccess($record, 'delete', $context->member);
            }

            $extraMeta = [];

            switch ($action) {
                case 'publish':
                    $mode = $this->publishModeFromBody($body);
                    $liveOnly = !empty($body['liveOnly']);
                    $dryRun = !empty($body['dryRun']);

                    // dryRun means something for "subtree" and "owns" alike
                    // — both walk a set of descendants before writing.
                    // liveOnly is "subtree"-only: it means "don't resurrect
                    // a Hierarchy-tree branch deliberately taken offline",
                    // a tree-hierarchy concept "owns" (an owned-relation
                    // walk, not a tree walk) has no equivalent for.
                    // PublishOrchestrator::publish() itself just ignores
                    // dryRun/liveOnly on every other mode, which would
                    // silently perform a real write while a caller
                    // reasonably believed "dryRun": true guaranteed nothing
                    // changed, or that "liveOnly": true was doing something
                    // on a mode where it can't. Refuse both combinations
                    // outright instead.
                    if ($dryRun && !in_array($mode, ['subtree', 'owns'], true)) {
                        throw new ApiError(
                            ErrorCode::PAYLOAD_INVALID,
                            'dryRun applies to "mode": "subtree" or "owns" only.'
                        );
                    }

                    if ($liveOnly && $mode !== 'subtree') {
                        throw new ApiError(
                            ErrorCode::PAYLOAD_INVALID,
                            'liveOnly applies to "mode": "subtree" only.'
                        );
                    }

                    $entries = $this->publisher->publish($record, $mode, $context->member, $liveOnly, $dryRun);

                    // dryRun never wrote anything — serializing "the
                    // record" as published would be misleading, so this
                    // response shape replaces the normal one entirely
                    // rather than adding to it.
                    if ($dryRun) {
                        return [
                            'data' => ['wouldPublish' => $entries ?? []],
                            'meta' => ['operation' => 'publishDryRun', 'mode' => $mode],
                        ];
                    }

                    // A real subtree run keeps the normal serialized-record
                    // response below (unchanged contract for existing
                    // callers) but adds what was actually published — no
                    // separate skipped list, a liveOnly-skipped descendant
                    // simply never appears here. Without this, confirming
                    // what a real liveOnly call did required a separate
                    // dryRun call beforehand, which isn't atomic with the
                    // real one (#102).
                    if ($entries !== null) {
                        $extraMeta['published'] = $entries;
                    }
                    break;
                case 'unpublish':
                    $unpublishMode = $this->unpublishModeFromBody($body);
                    $dryRun = !empty($body['dryRun']);

                    // #119: dryRun is meaningful only for mode=owns — it
                    // walks a set of descendants before writing. A plain
                    // "single" unpublish has nothing to preview, and
                    // silently ignoring dryRun there would perform a real,
                    // irreversible write while the caller reasonably
                    // believed nothing would be touched — same reasoning
                    // as publish's own dryRun/liveOnly rejection above.
                    if ($dryRun && $unpublishMode !== 'owns') {
                        throw new ApiError(
                            ErrorCode::PAYLOAD_INVALID,
                            'dryRun applies to "mode": "owns" only.'
                        );
                    }

                    // #119: unpublishOwnedTree()'s owns-mode write loop can
                    // leave a live orphan (root unpublished, some but not
                    // all descendants) if a doUnpublish() hook throws
                    // partway through — wrapped in a transaction the same
                    // way PageHandler::applyTemplate() wraps its own
                    // owned-tree cascade, so a mid-cascade failure rolls
                    // back cleanly instead of leaving inconsistent live
                    // state with no way to tell from the response alone.
                    $result = null;

                    try {
                        DbTransaction::run(function () use (
                            $record,
                            $body,
                            $unpublishMode,
                            $context,
                            $dryRun,
                            &$result
                        ) {
                            $result = $this->publisher->unpublish(
                                $record,
                                !empty($body['force']),
                                $unpublishMode,
                                $context->member,
                                $dryRun
                            );
                        });
                    } catch (ApiError $error) {
                        throw new ApiError(
                            $error->getErrorCode(),
                            $error->getMessage() . ' (unpublish rolled back)',
                            $error->getDetails(),
                            $error->getStatus()
                        );
                    }

                    // Same dryRun response-shape contract as publish above:
                    // nothing was written, so replace rather than augment.
                    if ($dryRun) {
                        return [
                            'data' => [
                                'wouldUnpublish' => $result['unpublished'] ?? [],
                                'skipped' => $result['skipped'] ?? [],
                            ],
                            'meta' => ['operation' => 'unpublishDryRun', 'mode' => $unpublishMode],
                        ];
                    }

                    // Same "augment, don't replace" contract as a real
                    // subtree publish — "single" mode returns null and adds
                    // nothing here, matching its pre-#119 response exactly.
                    if ($result !== null) {
                        $extraMeta['unpublished'] = $result['unpublished'];
                        $extraMeta['skipped'] = $result['skipped'];
                    }
                    break;
                case 'archive':
                    $this->publisher->archive($record, !empty($body['force']));

                    return [
                        'data' => [
                            'id' => (int) $record->ID,
                            'className' => $record->ClassName,
                            'archived' => true,
                        ],
                        'meta' => ['operation' => 'archived'],
                    ];
            }

            return [
                'data' => $this->serializer->serialize($record),
                'meta' => ['operation' => $action . 'ed'] + $extraMeta,
            ];
        });
    }

    /**
     * `{"mode": "subtree"}` (or any `PublishOrchestrator::MODES` value)
     * takes precedence when present — the explicit, forward-compatible
     * form. `{"recursive": true}` remains supported for backward
     * compatibility with callers that predate `subtree`. Neither present
     * defaults to `single`, matching this action's pre-existing behavior.
     */
    protected function publishModeFromBody(array $body): string
    {
        if (isset($body['mode'])) {
            return (string) $body['mode'];
        }

        return !empty($body['recursive']) ? 'recursive' : 'single';
    }

    /**
     * `{"mode": "owns"}` mirrors `publishModeFromBody()`'s explicit form —
     * no legacy boolean shorthand to carry forward here, since `owns` is
     * unpublish's only cascade mode (#119) and there was never a prior
     * flag for it. Not present defaults to `single`, unpublish's
     * pre-existing (and only) behavior before this mode existed.
     */
    protected function unpublishModeFromBody(array $body): string
    {
        if (isset($body['mode'])) {
            return (string) $body['mode'];
        }

        return 'single';
    }

    /**
     * Stage actions run against the draft stage.
     */
    protected function inDraft(callable $callback): mixed
    {
        return Versioned::withVersionedMode(function () use ($callback) {
            Versioned::set_stage(Versioned::DRAFT);

            return $callback();
        });
    }

    protected function jsonBody(HTTPRequest $request): array
    {
        $raw = $request->getBody();

        if (!$raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Request body is not valid JSON.');
        }

        return $decoded;
    }
}
