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
            // the transaction's rollback branch, but a re-check shows at
            // least one 'created', 'deleted', or (#127) 'updated' result
            // didn't genuinely revert — the SQL rollback didn't actually
            // take effect (confirmed cause: #70, a PHP diagnostic that
            // isn't a Throwable — e.g. a deprecation notice from
            // application code mid-write — can leave a caller unable to
            // trust the framework's own transaction-nesting bookkeeping).
            // Report this distinctly rather than claiming "rolled back" —
            // the caller must check the database directly before retrying.
            // Every checked result's id is listed in $result['results']
            // below; there is no narrower list to give (verifyRollback()
            // stops at the first unconfirmed record, it doesn't collect
            // every one that's still wrong).
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
     * `$preImages` — keyed by "ClassName:id", not by operation index, and
     * merged per COLUMN (earliest write wins), never a whole-array
     * overwrite. Two consequences of that keying, both required for
     * correctness, not just tidiness:
     * - If the same record is written more than once in one batch (e.g.
     *   two `update` ops on the same id, or an `upsert` that resolves to a
     *   row an earlier op in this same batch just created), every
     *   'updated' result for it is checked against the pre-image of EACH
     *   column as it stood before the EARLIEST write that touched THAT
     *   column — a genuine rollback restores every column to its state
     *   before its own earliest write, not before whichever op happened
     *   to run last. A field only ever touched by a later op still gets
     *   its own (later-captured, but still earliest-for-that-field)
     *   snapshot — the per-column merge, not a per-record "first op
     *   wins" shortcut, is what keeps that coverage from silently
     *   disappearing.
     * - An 'updated' result whose id was ALSO reported 'created' earlier
     *   in this same batch is skipped entirely, not compared against a
     *   pre-image at all: the create branch below already asserts the
     *   correct post-rollback state for that id (must be absent), and a
     *   field-level check on top of that would either be redundant (both
     *   pass) or actively contradict it (the record IS gone, which the
     *   'updated' branch would otherwise misreport as "can't confirm").
     *
     * If a result claims 'updated' but no pre-image was captured for its
     * key — an update whose payload declared no `fields` at all, e.g. a
     * relations-only change — there is nothing to compare against, and
     * this fails closed (unverified) rather than silently treating
     * "nothing to check" as "fine". Relation changes themselves are never
     * covered by this pre-image, only field values; an atomic batch that
     * only ever touches relations on its `update` ops gets no rollback
     * coverage from this method, same as before #127. Verification also
     * only ever reads DRAFT — an `update` with
     * `publish: "single"`/`"recursive"`/`"subtree"` also wrote LIVE, which
     * this method has no way to check. A has_one field's pre-image is the
     * raw FK id column, not the related record — comparing the related
     * DataObject itself (or its string cast, which is only ever its class
     * name) would report "verified" regardless of which record is
     * actually linked. A polymorphic has_one's companion `{Name}Class`
     * column is not independently checked.
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
     * @param array<string, array<string, mixed>> $preImages keyed by
     *   "ClassName:id", only present for 'updated' results — see
     *   `RecordWriter::write()` and `runOperation()`
     */
    protected function verifyRollback(array $operations, array $results, array $preImages = []): bool
    {
        $operations = array_values($operations);

        try {
            // A record this same batch also reported 'created' can
            // legitimately be MISSING after a genuine rollback — that's the
            // create's own check (below) passing, not a sign anything is
            // wrong. An 'updated' result for that same id (an upsert that
            // resolved to an existing row created earlier in this very
            // batch, still uncommitted) must not independently re-fail on
            // "the record is gone": the create branch already asserts the
            // correct post-rollback state for it. Built as its own pass so
            // it doesn't depend on 'created' results appearing before the
            // 'updated' ones that reference them. Inside the same try/catch
            // as everything below — an unresolvable class here must fail
            // closed exactly like an unresolvable class anywhere else in
            // this method, not escape as an uncaught ApiError.
            $createdKeys = [];

            foreach ($results as $result) {
                if (($result['status'] ?? '') !== 'created' || !isset($result['id'], $result['index'])) {
                    continue;
                }

                $operation = $operations[$result['index']] ?? null;

                if (is_array($operation)) {
                    $createdClass = $this->registry->resolve((string) ($operation['class'] ?? ''));
                    $createdKeys[$createdClass . ':' . $result['id']] = true;
                }
            }

            return Versioned::withVersionedMode(function () use ($operations, $results, $preImages, $createdKeys) {
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
                        $preImageKey = $className . ':' . $result['id'];

                        if (isset($createdKeys[$preImageKey])) {
                            // This id was also 'created' earlier in this
                            // same batch (an upsert that resolved to it) —
                            // the create branch above already asserts the
                            // correct post-rollback state for it. Checking
                            // it again here, against a snapshot taken
                            // AFTER a create that itself needs to unwind,
                            // would contradict that check rather than
                            // confirm anything.
                            continue;
                        }

                        $preImage = $preImages[$preImageKey] ?? null;

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

                        foreach ($preImage as $column => $originalValue) {
                            $currentValue = $record->getField($column);

                            if (is_object($currentValue)) {
                                // Every column RecordWriter::write() ever
                                // stores a pre-image for is resolved to a
                                // raw, always-scalar DB column — a has_one
                                // FK/Class or a composite's real
                                // sub-column, never the composite/relation
                                // object itself. An object here means that
                                // resolution didn't happen for this
                                // column, and a string cast can't be
                                // trusted not to silently collapse two
                                // different values to the same string
                                // (confirmed possible for a composite
                                // DBField whose own getValue() isn't
                                // overridden) — fail closed instead.
                                return false;
                            }

                            if ((string) $currentValue !== (string) $originalValue) {
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
     * @param array<string, array<string, mixed>> $preImages keyed by
     *   "ClassName:id", written into by reference as 'updated' results are
     *   produced — see verifyRollback()
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
     * @param array<string, array<string, mixed>> $preImages keyed by
     *   "ClassName:id", written into by reference when this operation
     *   produces an 'updated' result
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

            $serialized = $this->serializer->serialize($result['record']);

            // #127: carried out of the public $out below — a rollback
            // pre-image is verifyRollback()'s internal bookkeeping, never
            // part of the API response. Keyed by "ClassName:id" — NOT by
            // operation index — and merged per COLUMN, earliest wins, never
            // overwritten once a column is captured: if the same record is
            // written more than once in one batch (e.g. two `update` ops
            // touching different fields on the same id), a genuine rollback
            // restores it to its state before the EARLIEST write of EACH
            // field, not before whichever op happened to run last. `+`
            // (array union, left operand's keys win on collision) is
            // deliberate here — `??=` on the whole array would keep only
            // the FIRST op's column set wholesale and silently drop
            // verification for any column a later op was the only one to
            // touch.
            if (($result['preImage'] ?? null) !== null) {
                $preImageKey = $className . ':' . $serialized['id'];
                $preImages[$preImageKey] = ($preImages[$preImageKey] ?? []) + $result['preImage'];
            }

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
