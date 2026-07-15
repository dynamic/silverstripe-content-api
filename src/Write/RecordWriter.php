<?php

namespace Dynamic\ContentApi\Write;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Security\PermissionPolicy;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Request-independent write pipeline shared by the single-record endpoints,
 * the batch processor and the composition service: ACL → sparse field apply →
 * write (validation mapped) → relation writes → publish.
 *
 * Every method assumes it is already running inside a DRAFT versioned mode.
 */
class RecordWriter
{
    use Injectable;

    private static array $dependencies = [
        'applicator' => '%$' . WriteApplicator::class,
        'publisher' => '%$' . PublishOrchestrator::class,
        'externalIds' => '%$' . ExternalIdResolver::class,
        'policy' => '%$' . PermissionPolicy::class,
    ];

    public ?WriteApplicator $applicator = null;

    public ?PublishOrchestrator $publisher = null;

    public ?ExternalIdResolver $externalIds = null;

    public ?PermissionPolicy $policy = null;

    /**
     * Create a record, or upsert by external id when mode is `upsert`.
     *
     * `$internalFields` is a trusted, request-independent channel (see
     * `WriteApplicator::applyFields()`) for server-derived structural fields
     * — it is a dedicated parameter, deliberately NOT a `$payload` key,
     * because `$payload` here is frequently the caller's raw, client-supplied
     * operation body verbatim (e.g. `BatchProcessor::runOperation()` passes
     * the parsed request JSON straight through as `$payload`) — a request
     * body must never be able to populate this channel merely by including a
     * same-named key. Only pass a non-empty value from in-process code that
     * itself computed it (e.g. `CompositionService`).
     *
     * @param array{fields?: array, relations?: array, externalId?: string,
     *   publish?: string} $payload
     * @return array{record: DataObject, operation: string, warnings: array}
     * @throws ApiError
     */
    public function upsert(
        string $className,
        array $payload,
        Member $member,
        string $mode = 'create',
        array $internalFields = []
    ): array {
        if (!in_array($mode, ['create', 'upsert'], true)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Write mode must be "create" or "upsert".');
        }

        $externalId = isset($payload['externalId']) ? (string) $payload['externalId'] : null;
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
            $this->policy->checkClassAccess($className, 'update', $member);
            $this->policy->checkRecordAccess($existing, 'update', $member);

            return $this->write($existing, $payload, 'updated', $internalFields);
        }

        $this->policy->checkClassAccess($className, 'create', $member);

        // canCreate() context hydration should see trusted fields too (e.g. a
        // composition element's ParentID) — it only informs permission
        // checks, it isn't a writability gate, so merging here is safe.
        $this->policy->checkCreateAccess(
            $className,
            $member,
            array_merge((array) ($payload['fields'] ?? []), $internalFields)
        );

        /** @var DataObject $record */
        $record = Injector::inst()->create($className);

        if ($externalId !== null) {
            $record->setField($this->externalIds->fieldName(), $externalId);
        }

        return $this->write($record, $payload, 'created', $internalFields);
    }

    /**
     * Sparse update of an already-fetched record. See `upsert()` for why
     * `$internalFields` is a dedicated parameter rather than a `$payload` key.
     *
     * @return array{record: DataObject, operation: string, warnings: array}
     * @throws ApiError
     */
    public function update(DataObject $record, array $payload, Member $member, array $internalFields = []): array
    {
        $className = get_class($record);

        $this->policy->checkClassAccess($className, 'update', $member);
        $this->policy->checkRecordAccess($record, 'update', $member);

        if (isset($payload['externalId'])) {
            $this->externalIds->assertSupported($className);
            $record->setField($this->externalIds->fieldName(), (string) $payload['externalId']);
        }

        return $this->write($record, $payload, 'updated', $internalFields);
    }

    /**
     * Delete with an explicit mode; returns a summary of what was removed.
     *
     * @return array{data: array, operation: string}
     * @throws ApiError
     */
    public function delete(DataObject $record, string $mode, Member $member): array
    {
        $className = get_class($record);

        $this->policy->checkClassAccess($className, 'delete', $member);
        $this->policy->checkRecordAccess($record, 'delete', $member);

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
            'operation' => 'deleted',
        ];
    }

    /**
     * The shared apply → write → relations → publish core.
     *
     * @return array{record: DataObject, operation: string, warnings: array}
     */
    protected function write(DataObject $record, array $payload, string $operation, array $internalFields = []): array
    {
        $fields = (array) ($payload['fields'] ?? []);
        $relations = (array) ($payload['relations'] ?? []);
        $publishMode = (string) ($payload['publish'] ?? 'none');

        $this->publisher->assertValidMode($publishMode);

        $requestedUrlSegment = $fields['URLSegment'] ?? null;

        $this->applicator->applyFields($record, $fields, $internalFields);

        try {
            $record->write();
        } catch (ValidationException $exception) {
            throw ApiError::fromValidation($exception);
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

        return [
            'record' => $record,
            'operation' => $operation,
            'warnings' => $warnings,
        ];
    }
}
