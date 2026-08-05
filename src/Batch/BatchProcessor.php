<?php

namespace Dynamic\ContentApi\Batch;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Serialize\RecordSerializer;
use Dynamic\ContentApi\Write\DbTransaction;
use Dynamic\ContentApi\Write\RecordWriter;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * Executes an ordered list of write operations with per-operation results —
 * the agent self-correction contract. Non-atomic by default: an op that
 * fails reports an error result and the rest continue. `"atomic": true`
 * wraps the whole batch in a DB transaction and rolls everything back on the
 * first failure.
 *
 * Operation shape:
 * `{ "op": "create|upsert|update|delete", "class": "ClassRef",
 *    "id": 123 | "ext:...", "externalId": "...", "fields": {},
 *    "relations": {}, "publish": "...", "mode": "archive" }`
 */
class BatchProcessor
{
    use Injectable;

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'writer' => '%$' . RecordWriter::class,
        'serializer' => '%$' . RecordSerializer::class,
        'externalIds' => '%$' . ExternalIdResolver::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?RecordWriter $writer = null;

    public ?RecordSerializer $serializer = null;

    public ?ExternalIdResolver $externalIds = null;

    /**
     * @return array{results: array, summary: array, rolledBack?: bool}
     */
    public function process(array $payload, Member $member): array
    {
        $operations = $payload['operations'] ?? null;

        if (!is_array($operations) || $operations === []) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Batch requires a non-empty "operations" array.');
        }

        $atomic = !empty($payload['atomic']);
        $defaultPublish = (string) ($payload['defaultPublish'] ?? 'none');

        if (!$atomic) {
            return $this->run($operations, $defaultPublish, $member, false);
        }

        $outcome = null;

        try {
            DbTransaction::run(function () use ($operations, $defaultPublish, $member, &$outcome) {
                $outcome = $this->run($operations, $defaultPublish, $member, true);
            });
        } catch (BatchAbortException $aborted) {
            $result = $aborted->partialOutcome;
            $result['rolledBack'] = true;

            throw new ApiError(
                ErrorCode::VALIDATION_FAILED,
                sprintf('Atomic batch failed at operation %d — rolled back.', $aborted->failedIndex),
                [$result]
            );
        }

        return $outcome;
    }

    /**
     * @throws BatchAbortException when atomic and an op fails
     */
    protected function run(array $operations, string $defaultPublish, Member $member, bool $abortOnError): array
    {
        $results = [];
        $summary = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0];

        foreach (array_values($operations) as $index => $operation) {
            $result = Versioned::withVersionedMode(function () use ($operation, $defaultPublish, $member, $index) {
                Versioned::set_stage(Versioned::DRAFT);

                return $this->runOperation((array) $operation, $defaultPublish, $member, $index);
            });

            $results[] = $result;

            $bucket = $result['status'] === 'error' ? 'errors' : $result['status'];
            $summary[$bucket] = ($summary[$bucket] ?? 0) + 1;

            if ($result['status'] === 'error' && $abortOnError) {
                throw new BatchAbortException($index, [
                    'results' => $results,
                    'summary' => $summary,
                ]);
            }
        }

        return ['results' => $results, 'summary' => $summary];
    }

    protected function runOperation(array $operation, string $defaultPublish, Member $member, int $index): array
    {
        try {
            $op = (string) ($operation['op'] ?? '');
            $className = $this->registry->resolve((string) ($operation['class'] ?? ''));

            if (!isset($operation['publish']) && $op !== 'delete') {
                $operation['publish'] = $defaultPublish;
            }

            switch ($op) {
                case 'create':
                case 'upsert':
                    $result = $this->writer->upsert($className, $operation, $member, $op);
                    break;

                case 'update':
                    $record = $this->fetch($className, $operation);
                    $result = $this->writer->update($record, $operation, $member);
                    break;

                case 'delete':
                    $record = $this->fetch($className, $operation);
                    $deleted = $this->writer->delete(
                        $record,
                        (string) ($operation['mode'] ?? 'archive'),
                        $member,
                        !empty($operation['force'])
                    );

                    return [
                        'index' => $index,
                        'status' => 'deleted',
                    ] + $deleted['data'];

                default:
                    throw new ApiError(
                        ErrorCode::PAYLOAD_INVALID,
                        sprintf('Unknown op "%s" — use create, upsert, update or delete.', $op)
                    );
            }

            $serialized = $this->serializer->serialize($result['record']);

            $out = [
                'index' => $index,
                'status' => $result['operation'],
                'id' => $serialized['id'],
                'externalId' => $serialized['externalId'] ?? null,
            ];

            if (isset($serialized['stage'])) {
                $out['stage'] = $serialized['stage'];
            }

            if ($result['warnings'] !== []) {
                $out['warnings'] = $result['warnings'];
            }

            return $out;
        } catch (ApiError $error) {
            return [
                'index' => $index,
                'status' => 'error',
                'error' => $error->toArray(),
            ];
        }
    }

    /**
     * Fetch the target of an update/delete op via `id` (numeric or ext:)
     * or `externalId`.
     */
    protected function fetch(string $className, array $operation): DataObject
    {
        if (isset($operation['id'])) {
            $idParam = (string) $operation['id'];

            if (str_starts_with($idParam, 'ext:')) {
                return $this->externalIds->find($className, substr($idParam, 4));
            }

            $record = DataObject::get_by_id($className, (int) $idParam);

            if (!$record) {
                throw new ApiError(
                    ErrorCode::NOT_FOUND,
                    sprintf('No %s found with id %d.', $className, (int) $idParam)
                );
            }

            return $record;
        }

        if (isset($operation['externalId'])) {
            return $this->externalIds->find($className, (string) $operation['externalId']);
        }

        throw new ApiError(
            ErrorCode::PAYLOAD_INVALID,
            'update/delete operations require "id" or "externalId".'
        );
    }
}
