<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Serialize\RecordSerializer;
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

        return $this->inDraft(function () use ($request, $className, $action, $body, $context, $verb) {
            $record = $this->reader->fetchRecord($className, (string) $request->param('ID'));
            $this->policy->checkRecordAccess($record, $verb, $context->member);

            $extraMeta = [];

            switch ($action) {
                case 'publish':
                    $mode = $this->publishModeFromBody($body);
                    $liveOnly = !empty($body['liveOnly']);
                    $dryRun = !empty($body['dryRun']);

                    // liveOnly/dryRun only mean something for mode: "subtree"
                    // — PublishOrchestrator::publish() itself just ignores
                    // them on every other mode, which would silently perform
                    // a real write while a caller reasonably believed
                    // "dryRun": true guaranteed nothing changed. Refuse the
                    // combination outright instead.
                    if (($liveOnly || $dryRun) && $mode !== 'subtree') {
                        throw new ApiError(
                            ErrorCode::PAYLOAD_INVALID,
                            'liveOnly/dryRun apply to "mode": "subtree" only.'
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
                    $this->publisher->unpublish($record, !empty($body['force']));
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
