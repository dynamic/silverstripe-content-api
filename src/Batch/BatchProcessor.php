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

        // Populated by run()/runOperation() as 'updated' results are
        // produced — pre-images for verifyRollback() (#127). Declared here,
        // outside the atomic/non-atomic branch, so both paths share one
        // variable; only the atomic path ever reads it back, but a plain
        // by-reference array threaded through the call stack (rather than
        // instance state on this shared/injected service) keeps concurrent
        // or re-entrant process() calls from stepping on each other.
        $preImages = [];

        if (!$atomic) {
            return $this->run($operations, $defaultPublish, $member, false, $preImages);
        }

        $outcome = null;

        try {
            DbTransaction::run(function () use ($operations, $defaultPublish, $member, &$outcome, &$preImages) {
                $outcome = $this->run($operations, $defaultPublish, $member, true, $preImages);
            });
        } catch (BatchAbortException $aborted) {
            $result = $aborted->partialOutcome;
            $verified = $this->verifyRollback($operations, $result['results'], $preImages);
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
            // Every 'created'/verifiable-'deleted' result's id is listed in
            // $result['results'] below; there is no narrower list to give
            // (verifyRollback() stops at the first unconfirmed record, it
            // doesn't collect every one that's still there).
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
     * Re-check every record this batch reported as created or deleted, by
     * id, after the transaction was supposed to roll back — confirms a
     * real SQL ROLLBACK actually happened rather than trusting the
     * exception-unwind path alone. Deliberately checked outside the failed
     * transaction's own versioned-mode context (that context no longer
     * applies once the transaction has unwound), reading the same DRAFT
     * stage every write in this batch targeted.
     *
     * An 'updated' op is now verified too (#127): `runOperation()` captures
     * a pre-image of the declared field keys (via `RecordWriter::write()`)
     * before the write happens, threaded down from `process()` as
     * `$preImages`. If a result claims 'updated' but no pre-image was
     * captured for its index — an update whose payload declared no
     * `fields` at all, e.g. a relations-only change — there is nothing to
     * compare against, and this fails closed (unverified) rather than
     * silently treating "nothing to check" as "fine". Relation changes
     * themselves are never covered by this pre-image, only field values;
     * an atomic batch that only ever touches relations on its `update`
     * ops gets no rollback coverage from this method, same as before #127.
     * Verification also only ever reads DRAFT — an `update` with
     * `publish: "single"`/`"recursive"`/`"subtree"` also wrote LIVE, which
     * this method has no way to check.
     *
     * A 'deleted' op's id IS retained, and is verified — but only when the
     * delete could actually have touched DRAFT: an 'unpublish'-mode delete
     * on a versioned class only removes the LIVE row, leaving DRAFT
     * untouched either way, so checking for its presence there would read
     * as falsely "fine" regardless of what really happened (#75). 'archive'
     * mode, and any mode at all on an unversioned class (every delete
     * mode converges on a real delete() there — see
     * PublishOrchestrator::delete()), do reach DRAFT and are verified the
     * same way a created record is: still-present after a claimed
     * rollback means the delete committed for real.
     *
     * Every step here is deliberately defensive: this runs after the
     * batch has already failed, at the exact moment the caller most needs
     * an accurate response — a second, unrelated failure while checking
     * (a lost DB connection, a class that no longer resolves) must never
     * be allowed to erase the original failure's context, so any
     * Throwable here fails toward "can't verify" (false) rather than
     * propagating and replacing the whole response with a bare
     * SERVER_ERROR that drops $results entirely.
     *
     * @param array<int, array<string, mixed>> $preImages keyed by operation
     *   index, only present for 'updated' results — see `RecordWriter::write()`
     */
    protected function verifyRollback(array $operations, array $results, array $preImages = []): bool
    {
        $operations = array_values($operations);

        try {
            return Versioned::withVersionedMode(function () use ($operations, $results, $preImages) {
                Versioned::set_stage(Versioned::DRAFT);

                foreach ($results as $result) {
                    $status = $result['status'] ?? '';

                    if (!in_array($status, ['created', 'deleted', 'updated'], true) || !isset($result['id'])) {
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

                    if ($status === 'deleted') {
                        $mode = (string) ($operation['mode'] ?? 'archive');
                        $isVersioned = DataObject::has_extension($className, Versioned::class);

                        if ($mode !== 'archive' && $isVersioned) {
                            // 'unpublish' (or 'hard', rejected earlier for
                            // versioned classes) on a versioned class never
                            // touches DRAFT — nothing here to verify.
                            continue;
                        }

                        if (!DataObject::get($className)->byID((int) $result['id'])) {
                            return false;
                        }

                        continue;
                    }

                    if ($status === 'updated') {
                        $preImage = $preImages[$result['index']] ?? null;

                        if (!is_array($preImage) || $preImage === []) {
                            // No fields were snapshotted for this result —
                            // nothing to diff. Fail closed rather than
                            // implicitly reporting "verified" for a check
                            // that never actually ran.
                            return false;
                        }

                        $record = DataObject::get($className)->byID((int) $result['id']);

                        if (!$record) {
                            // A claimed "rolled back" update can't leave the
                            // record itself missing — something else (not
                            // this update) destroyed it; can't confirm the
                            // rollback either way.
                            return false;
                        }

                        foreach ($preImage as $field => $originalValue) {
                            if ((string) $record->getField($field) !== (string) $originalValue) {
                                return false;
                            }
                        }

                        continue;
                    }

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
     * @param array<int, array<string, mixed>> $preImages written into by
     *   reference as 'updated' results are produced — see verifyRollback()
     * @throws BatchAbortException when atomic and an op fails
     */
    protected function run(
        array $operations,
        string $defaultPublish,
        Member $member,
        bool $abortOnError,
        array &$preImages
    ): array {
        $results = [];
        $summary = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0];

        foreach (array_values($operations) as $index => $operation) {
            $result = Versioned::withVersionedMode(
                function () use ($operation, $defaultPublish, $member, $index, &$preImages) {
                    Versioned::set_stage(Versioned::DRAFT);

                    return $this->runOperation((array) $operation, $defaultPublish, $member, $index, $preImages);
                }
            );

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

    /**
     * @param array<int, array<string, mixed>> $preImages written into by
     *   reference when this operation produces an 'updated' result
     */
    protected function runOperation(
        array $operation,
        string $defaultPublish,
        Member $member,
        int $index,
        array &$preImages
    ): array {
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

            // #127: carried out of the public $out below — a rollback
            // pre-image is verifyRollback()'s internal bookkeeping, never
            // part of the API response.
            if (($result['preImage'] ?? null) !== null) {
                $preImages[$index] = $result['preImage'];
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

            $record = DataObject::get($className)->byID((int) $idParam);

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
