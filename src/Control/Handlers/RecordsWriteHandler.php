<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Serialize\RecordSerializer;
use Dynamic\ContentApi\Write\WriteApplicator;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Write endpoints:
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
        'externalIds' => '%$' . ExternalIdResolver::class,
        'applicator' => '%$' . WriteApplicator::class,
        'publisher' => '%$' . PublishOrchestrator::class,
        'reader' => '%$' . RecordsHandler::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?PermissionPolicy $policy = null;

    public ?RecordSerializer $serializer = null;

    public ?ExternalIdResolver $externalIds = null;

    public ?WriteApplicator $applicator = null;

    public ?PublishOrchestrator $publisher = null;

    public ?RecordsHandler $reader = null;

    public function create(HTTPRequest $request, AuthContext $context): array
    {
        $className = $this->registry->resolve((string) $request->param('ClassRef'));
        $body = $this->jsonBody($request);
        $mode = (string) ($body['mode'] ?? 'create');
        $externalId = isset($body['externalId']) ? (string) $body['externalId'] : null;

        if (!in_array($mode, ['create', 'upsert'], true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                'POST mode must be "create" or "upsert".'
            );
        }

        return $this->inDraft(function () use ($className, $body, $mode, $externalId, $context) {
            $existing = null;

            if ($externalId !== null) {
                $this->externalIds->assertSupported($className);
                $existing = $this->externalIds->tryFind($className, $externalId);
            }

            if ($existing && $mode === 'create') {
                throw new ApiError(
                    ErrorCode::ALREADY_EXISTS,
                    sprintf(
                        '%s with external id "%s" already exists (#%d) — use "mode": "upsert" to update it.',
                        $className,
                        $externalId,
                        $existing->ID
                    )
                );
            }

            if ($existing) {
                $this->policy->checkClassAccess($className, 'update', $context->member);
                $this->policy->checkRecordAccess($existing, 'update', $context->member);

                return $this->performWrite($existing, $body, 'updated');
            }

            $this->policy->checkClassAccess($className, 'create', $context->member);
            $this->policy->checkCreateAccess($className, $context->member, (array) ($body['fields'] ?? []));

            /** @var DataObject $record */
            $record = Injector::inst()->create($className);

            if ($externalId !== null) {
                $record->setField($this->externalIds->fieldName(), $externalId);
            }

            return $this->performWrite($record, $body, 'created', 201);
        });
    }

    public function update(HTTPRequest $request, AuthContext $context): array
    {
        $className = $this->registry->resolve((string) $request->param('ClassRef'));
        $this->policy->checkClassAccess($className, 'update', $context->member);

        $body = $this->jsonBody($request);

        return $this->inDraft(function () use ($request, $className, $body, $context) {
            $record = $this->reader->fetchRecord($className, (string) $request->param('ID'));
            $this->policy->checkRecordAccess($record, 'update', $context->member);

            if (isset($body['externalId'])) {
                $this->externalIds->assertSupported($className);
                $record->setField($this->externalIds->fieldName(), (string) $body['externalId']);
            }

            return $this->performWrite($record, $body, 'updated');
        });
    }

    public function delete(HTTPRequest $request, AuthContext $context): array
    {
        $className = $this->registry->resolve((string) $request->param('ClassRef'));
        $this->policy->checkClassAccess($className, 'delete', $context->member);

        $mode = (string) ($request->getVar('mode') ?: 'archive');

        return $this->inDraft(function () use ($request, $className, $mode, $context) {
            $record = $this->reader->fetchRecord($className, (string) $request->param('ID'));
            $this->policy->checkRecordAccess($record, 'delete', $context->member);

            $summary = [
                'id' => (int) $record->ID,
                'className' => $record->ClassName,
            ];

            if ($this->externalIds->supports($record->ClassName)) {
                $summary['externalId'] = $record->getField($this->externalIds->fieldName()) ?: null;
            }

            $this->publisher->delete($record, $mode);

            return [
                'data' => $summary + ['deleted' => true, 'mode' => $mode],
                'meta' => ['operation' => 'deleted'],
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
     * Shared field-apply → write → relations → publish pipeline.
     */
    protected function performWrite(DataObject $record, array $body, string $operation, int $status = 200): array
    {
        $fields = (array) ($body['fields'] ?? []);
        $relations = (array) ($body['relations'] ?? []);
        $publishMode = (string) ($body['publish'] ?? 'none');

        $this->publisher->assertValidMode($publishMode);

        $requestedUrlSegment = $fields['URLSegment'] ?? null;

        $this->applicator->applyFields($record, $fields);

        try {
            $record->write();
        } catch (ValidationException $exception) {
            throw $this->validationError($exception);
        }

        $warnings = $this->applicator->getWarnings();

        // A green response must never hide a URLSegment dedup bump
        // (SiteTree silently rewrites collisions to segment-2).
        if ($requestedUrlSegment !== null && $record->getField('URLSegment') !== $requestedUrlSegment) {
            $warnings[] = [
                'code' => ErrorCode::URLSEGMENT_COLLISION->value,
                'message' => sprintf(
                    'Requested URLSegment "%s" was taken — record saved as "%s".',
                    $requestedUrlSegment,
                    $record->getField('URLSegment')
                ),
                'field' => 'URLSegment',
            ];
        }

        if ($relations !== []) {
            $this->applicator->applyRelations($record, $relations);
        }

        $this->publisher->publish($record, $publishMode);

        $meta = ['operation' => $operation];

        if ($warnings !== []) {
            $meta['warnings'] = $warnings;
        }

        return [
            'data' => $this->serializer->serialize($record),
            'meta' => $meta,
            'status' => $status,
        ];
    }

    protected function validationError(ValidationException $exception): ApiError
    {
        $details = [];

        foreach ($exception->getResult()->getMessages() as $message) {
            $details[] = [
                'field' => ($message['fieldName'] ?? '') !== '' ? $message['fieldName'] : null,
                'code' => 'VALIDATION',
                'message' => (string) ($message['message'] ?? ''),
            ];
        }

        return new ApiError(
            ErrorCode::VALIDATION_FAILED,
            sprintf('%d field(s) failed validation.', max(1, count($details))),
            $details
        );
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
