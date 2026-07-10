<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Serialize\RecordSerializer;
use Dynamic\ContentApi\Write\RecordWriter;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Versioned\Versioned;

/**
 * Write endpoints (thin HTTP layer over RecordWriter):
 *
 * - `POST records/$ClassRef` — create (or upsert with `"mode": "upsert"`)
 * - `PATCH records/$ClassRef/$ID` — sparse update (numeric or ext: id)
 * - `DELETE records/$ClassRef/$ID?mode=archive|unpublish|hard`
 * - `POST records/$ClassRef/$ID/publish|unpublish|archive` — stage actions
 *
 * Payload: `{ "fields": {}, "relations": {}, "externalId": "", "mode": "",
 * "publish": "none|single|recursive" }`. All writes run against DRAFT.
 */
class RecordsWriteHandler
{
    use Injectable;

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'policy' => '%$' . PermissionPolicy::class,
        'serializer' => '%$' . RecordSerializer::class,
        'writer' => '%$' . RecordWriter::class,
        'publisher' => '%$' . PublishOrchestrator::class,
        'reader' => '%$' . RecordsHandler::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?PermissionPolicy $policy = null;

    public ?RecordSerializer $serializer = null;

    public ?RecordWriter $writer = null;

    public ?PublishOrchestrator $publisher = null;

    public ?RecordsHandler $reader = null;

    public function create(HTTPRequest $request, AuthContext $context): array
    {
        $className = $this->registry->resolve((string) $request->param('ClassRef'));
        $body = $this->jsonBody($request);
        $mode = (string) ($body['mode'] ?? 'create');

        return $this->inDraft(function () use ($className, $body, $mode, $context) {
            $result = $this->writer->upsert($className, $body, $context->member, $mode);

            return $this->writeResponse($result, $result['operation'] === 'created' ? 201 : 200);
        });
    }

    public function update(HTTPRequest $request, AuthContext $context): array
    {
        $className = $this->registry->resolve((string) $request->param('ClassRef'));
        $body = $this->jsonBody($request);

        return $this->inDraft(function () use ($request, $className, $body, $context) {
            $record = $this->reader->fetchRecord($className, (string) $request->param('ID'));

            return $this->writeResponse($this->writer->update($record, $body, $context->member));
        });
    }

    public function delete(HTTPRequest $request, AuthContext $context): array
    {
        $className = $this->registry->resolve((string) $request->param('ClassRef'));
        $mode = (string) ($request->getVar('mode') ?: 'archive');

        return $this->inDraft(function () use ($request, $className, $mode, $context) {
            $record = $this->reader->fetchRecord($className, (string) $request->param('ID'));
            $result = $this->writer->delete($record, $mode, $context->member);

            return [
                'data' => $result['data'],
                'meta' => ['operation' => $result['operation']],
            ];
        });
    }

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

        $this->policy->checkClassAccess($className, 'action', $context->member);
        $body = $this->jsonBody($request);

        return $this->inDraft(function () use ($request, $className, $action, $body, $context) {
            $record = $this->reader->fetchRecord($className, (string) $request->param('ID'));
            $this->policy->checkRecordAccess($record, 'action', $context->member);

            switch ($action) {
                case 'publish':
                    $this->publisher->publish($record, !empty($body['recursive']) ? 'recursive' : 'single');
                    break;
                case 'unpublish':
                    $this->publisher->unpublish($record);
                    break;
                case 'archive':
                    $this->publisher->archive($record);

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
                'meta' => ['operation' => $action . 'ed'],
            ];
        });
    }

    /**
     * @param array{record: \SilverStripe\ORM\DataObject, operation: string, warnings: array} $result
     */
    protected function writeResponse(array $result, int $status = 200): array
    {
        $meta = ['operation' => $result['operation']];

        if ($result['warnings'] !== []) {
            $meta['warnings'] = $result['warnings'];
        }

        return [
            'data' => $this->serializer->serialize($result['record']),
            'meta' => $meta,
            'status' => $status,
        ];
    }

    /**
     * All write operations run against the draft stage.
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
