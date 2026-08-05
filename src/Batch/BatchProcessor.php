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
use Throwable;

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
            $verified = $this->verifyRollback($operations, $result['results']);
            $result['rolledBack'] = $verified;

            if ($verified) {
                throw new ApiError(
                    ErrorCode::VALIDATION_FAILED,
                    sprintf('Atomic batch failed at operation %d — rolled back.', $aborted->failedIndex),
                    [$result]
                );
            }

            // A caught exception unwinding through DbTransaction::run() ran
            // the transaction's rollback branch, but a re-check of the
            // records this batch reported as created shows at least one is
            // still there — the SQL rollback didn't actually take effect
            // (confirmed cause: #70, a PHP diagnostic that isn't a
            // Throwable — e.g. a deprecation notice from application code
            // mid-write — can leave a caller unable to trust the
            // framework's own transaction-nesting bookkeeping).
            // Report this distinctly rather than claiming "rolled back" —
            // the caller must check the database directly before retrying.
            // Every 'created' result's id is listed in $result['results']
            // below; there is no narrower list to give (verifyRollback()
            // stops at the first unconfirmed record, it doesn't collect
            // every one that's still there).
            throw new ApiError(
                ErrorCode::ROLLBACK_UNVERIFIED,
                sprintf(
                    'Atomic batch failed at operation %d, and the rollback could not be verified '
                        . '— some operations reported before the failure may still have committed. '
                        . 'Check the affected records directly before retrying.',
                    $aborted->failedIndex
                ),
                [$result]
            );
        }

        return $outcome;
    }

    /**
     * Re-check every record this batch reported as created, by id, after
     * the transaction was supposed to roll back — confirms a real SQL
     * ROLLBACK actually happened rather than trusting the exception-unwind
     * path alone. Deliberately checked outside the failed transaction's own
     * versioned-mode context (that context no longer applies once the
     * transaction has unwound), reading the same DRAFT stage every write in
     * this batch targeted.
     *
     * Only covers 'created' results — an 'updated' op's pre-image isn't
     * retained anywhere to compare against. A 'deleted' op's id IS
     * retained, but whether its absence is actually meaningful depends on
     * the delete mode (an 'unpublish' leaves the draft row untouched
     * either way, so checking it here would read as falsely "fine"
     * regardless of what really happened) — left as a known gap rather
     * than half-verifying it; see dynamic/silverstripe-content-api#75.
     * Catching every created record still there is the cheapest,
     * highest-signal check available without that redesign.
     *
     * Every step here is deliberately defensive: this runs after the
     * batch has already failed, at the exact moment the caller most needs
     * an accurate response — a second, unrelated failure while checking
     * (a lost DB connection, a class that no longer resolves) must never
     * be allowed to erase the original failure's context, so any
     * Throwable here fails toward "can't verify" (false) rather than
     * propagating and replacing the whole response with a bare
     * SERVER_ERROR that drops $results entirely.
     */
    protected function verifyRollback(array $operations, array $results): bool
    {
        $operations = array_values($operations);

        try {
            return Versioned::withVersionedMode(function () use ($operations, $results) {
                Versioned::set_stage(Versioned::DRAFT);

                foreach ($results as $result) {
                    if ($result['status'] !== 'created' || !isset($result['id'])) {
                        continue;
                    }

                    $operation = $operations[$result['index']] ?? null;

                    if (!is_array($operation)) {
                        // The op that produced this exact result is gone
                        // from the list we're checking against — can't
                        // confirm anything about it, fail closed.
                        return false;
                    }

                    $className = $this->registry->resolve((string) ($operation['class'] ?? ''));

                    if (DataObject::get($className)->byID((int) $result['id'])) {
                        return false;
                    }
                }

                return true;
            });
        } catch (Throwable) {
            return false;
        }
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
                        $member
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
