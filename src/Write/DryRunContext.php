<?php

namespace Dynamic\ContentApi\Write;

/**
 * Whether the current request is running under `content_batch`'s
 * `dryRun: true` — a static flag any project code can check, not something
 * this module infers or intercepts on its own.
 *
 * #203: `DbTransaction::run()` plus the framework's own nested-transaction
 * (savepoint) support already correctly rolls back a plain `onBeforeWrite()`
 * side effect that writes through the ORM on the same connection — covered
 * by `BatchTest::testDryRunRollsBackASideEffectWriteToAnUnrelatedTable()`
 * and its atomic-rollback companion. What no DB transaction can ever undo
 * is a side effect OUTSIDE the database entirely: an HTTP call, a queued
 * job dispatch, a write to an external cache/service. `ElementOembed`'s
 * real-world oEmbed lookup (confirmed live, Rockline Industrial: orphan
 * `EmbedObject` rows survived both a rolled-back composition and a
 * `dryRun: true` probe) is exactly this shape — this module doesn't own
 * that class and can't skip its fetch on its behalf, so the fix here is
 * visibility, not interception: `ElementOembed` (or any project code with
 * a non-transactional side effect in a write hook or `ValueTransformer`)
 * can check {@see isActive()} and skip that side effect itself when true.
 */
class DryRunContext
{
    private static bool $active = false;

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * Runs `$work` with {@see isActive()} true for its duration, always
     * restoring the prior value afterward — including when `$work` throws,
     * which `BatchProcessor::runDryRun()` does unconditionally
     * ({@see \Dynamic\ContentApi\Batch\DryRunCompleteException}).
     */
    public static function run(callable $work): mixed
    {
        $previous = self::$active;
        self::$active = true;

        try {
            return $work();
        } finally {
            self::$active = $previous;
        }
    }
}
