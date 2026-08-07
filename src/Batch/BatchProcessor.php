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
        $dryRun = !empty($payload['dryRun']);
        $defaultPublish = (string) ($payload['defaultPublish'] ?? 'none');

        // Populated by run()/runOperation() as 'updated' results are
        // produced — pre-images for verifyRollback() (#127). Declared here,
        // outside the atomic/non-atomic branch, so both paths share one
        // variable; only the atomic and dry-run paths ever read it back,
        // but a plain by-reference array threaded through the call stack
        // (rather than instance state on this shared/injected service)
        // keeps concurrent or re-entrant process() calls from stepping on
        // each other.
        $preImages = [];

        if ($dryRun) {
            return $this->runDryRun($operations, $defaultPublish, $member, $atomic, $preImages);
        }

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
     * #130: `dryRun: true` — runs the batch exactly as a real request would
     * (same authorization, class/externalId/relation resolution, payload
     * validation, model `validate()` on write — none of that surfaces
     * except by actually attempting the write; see `RecordWriter::write()`)
     * inside a transaction that is UNCONDITIONALLY rolled back afterward,
     * regardless of `$atomic` or whether every op succeeded. Non-atomic
     * dry runs still report per-op errors the same way a real non-atomic
     * run would (`$abortOnError = $atomic`, identical to `process()`'s own
     * real-run branches) — a dry run predicts exactly what a real run
     * would report, it doesn't change the reporting shape.
     *
     * `DryRunCompleteException` is the forcing mechanism: it's thrown
     * unconditionally at the end of the transaction closure whether `run()`
     * returned normally or raised `BatchAbortException` (atomic + a real
     * op failure), so `DbTransaction::run()` always sees an exception and
     * always rolls back — there is no code path here that lets a dry run
     * commit. Caught immediately outside the transaction; never a real
     * error as far as the caller is concerned.
     *
     * Rollback is then verified the same way an atomic failure's rollback
     * is (`verifyRollback()`, #127, called non-strict — see that method's
     * own docblock for why) — "wrapped in a transaction that gets rolled
     * back" is exactly the mechanism #70 proved isn't trustworthy on its
     * own. A dry run that fails verification is the loudest possible
     * failure: it means this "safe preflight" call may have just written
     * real data, so it's reported as `ROLLBACK_UNVERIFIED` (500), the
     * same code a genuinely-failed atomic rollback uses — and, unlike the
     * success/predicted-failure paths below, that response deliberately
     * carries real (unmapped) verbs, not `would*` ones: the caller
     * genuinely can't tell whether the write committed, so real verbs are
     * the honest signal. Never folded into the normal dry-run response.
     *
     * @return array{results: array, summary: array}
     */
    protected function runDryRun(
        array $operations,
        string $defaultPublish,
        Member $member,
        bool $atomic,
        array &$preImages
    ): array {
        try {
            DbTransaction::run(function () use ($operations, $defaultPublish, $member, $atomic, &$preImages) {
                $failedIndex = null;

                try {
                    $outcome = $this->run($operations, $defaultPublish, $member, $atomic, $preImages);
                } catch (BatchAbortException $aborted) {
                    $outcome = $aborted->partialOutcome;
                    $failedIndex = $aborted->failedIndex;
                }

                // Always throws — see this method's own docblock for why
                // an exception must escape the transaction closure
                // unconditionally, whether $outcome came from a normal
                // return or an atomic abort.
                throw new DryRunCompleteException($outcome, $failedIndex);
            });

            // DbTransaction::run() never returns normally from the closure
            // above — it always throws DryRunCompleteException, and
            // withTransaction() itself only ever returns without invoking
            // the callback at all (transactions unsupported) by throwing
            // BadMethodCallException first. Reaching this line would mean
            // neither of those held — the closure never ran, so nothing
            // was written; SERVER_ERROR is the honest signal, not
            // ROLLBACK_UNVERIFIED (which specifically means "something MAY
            // have been written and we can't tell").
            throw new ApiError(
                ErrorCode::SERVER_ERROR,
                'Dry run did not complete as expected.'
            );
        } catch (DryRunCompleteException $complete) {
            $outcome = $complete->outcome;
            $failedIndex = $complete->failedIndex;
        }

        // Non-strict (#127's $strict = false): a relations-only `update` —
        // the module's normal element-attach shape, see
        // docs/en/06_write-payloads.md#relations-has_many--many_many — has
        // no pre-image to check, same as it does on a real run. In strict
        // mode (the real-atomic-failure caller below) that's deliberately
        // treated as "can't confirm, fail closed"; here, where nothing
        // failed and the whole batch is guaranteed rolled back regardless,
        // treating an uncheckable field set as proof of a BROKEN rollback
        // would make dry-run unusable for the single most common op shape.
        // "Nothing to check" and "checked and wrong" are different things —
        // only the latter should ever produce ROLLBACK_UNVERIFIED here.
        $verified = $this->verifyRollback($operations, $outcome['results'], $preImages, false);

        if (!$verified) {
            throw new ApiError(
                ErrorCode::ROLLBACK_UNVERIFIED,
                'Dry run could not be verified as having made zero persistent changes '
                    . '— check the affected records directly before trusting this preflight.',
                [$outcome + ['dryRun' => true]]
            );
        }

        if ($failedIndex !== null) {
            // Predict exactly what a real atomic run would report for the
            // same failure — mapped through the same would* vocabulary as
            // the success path below, so a caller parsing this error's
            // `details[0].results[].status` never sees a real-run status
            // for a batch that was guaranteed rolled back. The only
            // difference from a real failed atomic run: `rolledBack` is
            // unconditionally true here (just proven above), where a real
            // run's rollback still has to be independently verified
            // per-request.
            throw new ApiError(
                ErrorCode::VALIDATION_FAILED,
                sprintf('Dry run: atomic batch would fail at operation %d.', $failedIndex),
                [[
                    'results' => $this->toDryRunResults($outcome['results']),
                    'summary' => $this->toDryRunSummary($outcome['summary']),
                    'rolledBack' => true,
                    'dryRun' => true,
                ]]
            );
        }

        return [
            'results' => $this->toDryRunResults($outcome['results']),
            'summary' => $this->toDryRunSummary($outcome['summary']),
        ];
    }

    /**
     * Maps a real run's per-op status vocabulary to its dry-run
     * equivalent — `created`/`updated`/`deleted` become `wouldCreate`/
     * `wouldUpdate`/`wouldDelete` (matching #102's `would*` convention for
     * `publishDryRun`), `error` is unchanged. A dry-run response replaces
     * the normal envelope rather than augmenting it (also #102's
     * convention) specifically so a caller can never mistake a `status`
     * field for confirmation that something was actually written.
     */
    protected function toDryRunResults(array $results): array
    {
        $map = ['created' => 'wouldCreate', 'updated' => 'wouldUpdate', 'deleted' => 'wouldDelete'];

        return array_map(static function (array $result) use ($map) {
            $originalStatus = $result['status'];
            $result['status'] = $map[$originalStatus] ?? $originalStatus;

            // RecordWriter::delete()'s result carries a literal
            // "deleted": true — accurate on a real run, actively
            // misleading on a dry run (the record still exists). Every
            // other passed-through key (id/className/externalId/mode) is
            // still accurate regardless of whether the write actually
            // happened.
            if ($originalStatus === 'deleted') {
                $result['deleted'] = false;
            }

            return $result;
        }, $results);
    }

    protected function toDryRunSummary(array $summary): array
    {
        $map = ['created' => 'wouldCreate', 'updated' => 'wouldUpdate', 'deleted' => 'wouldDelete'];
        $out = [];

        // Same map as toDryRunResults(), applied key-wise instead of
        // hardcoded — a future write-verb bucket run() ever adds shows up
        // here automatically (passed through unmapped) instead of
        // silently vanishing from the dry-run summary the way a fixed
        // key list would drop it.
        foreach ($summary as $key => $count) {
            $out[$map[$key] ?? $key] = $count;
        }

        return $out;
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
     * @param bool $strict When true (the real-atomic-failure caller, i.e.
     *   `process()`), an 'updated' result with no captured pre-image
     *   (relations-only payload — this pre-image mechanism never covers
     *   relations) fails closed: a genuine failure justifies not claiming
     *   confidence this method doesn't actually have. When false (the
     *   dry-run caller, `runDryRun()`, where nothing failed and the whole
     *   batch is guaranteed rolled back regardless), the same situation is
     *   skipped instead — "nothing was captured to check" is not evidence
     *   the rollback was broken, and treating it as such would make a dry
     *   run of the module's normal element-attach shape (an `update` whose
     *   payload is `relations` only) permanently report a false
     *   `ROLLBACK_UNVERIFIED`.
     */
    protected function verifyRollback(
        array $operations,
        array $results,
        array $preImages = [],
        bool $strict = true
    ): bool {
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

            $verify = function () use ($operations, $results, $preImages, $createdKeys, $strict) {
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
                            // nothing to diff. In strict mode (a real
                            // atomic failure), fail closed rather than
                            // implicitly reporting "verified" for a check
                            // that never actually ran. In non-strict mode
                            // (a dry run, where the batch is guaranteed
                            // rolled back regardless of this check),
                            // "nothing to check" isn't evidence of
                            // anything wrong — skip it and let the rest of
                            // the batch's checks decide.
                            if ($strict) {
                                return false;
                            }

                            continue;
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
            };

            return Versioned::withVersionedMode($verify);
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
