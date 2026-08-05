<?php

namespace Dynamic\ContentApi\Tests\Stub;

use Dynamic\ContentApi\Batch\BatchProcessor;

/**
 * Test double for #70: the framework's real transaction-rollback mechanism
 * works correctly under normal (non-corrupted) conditions, so nothing in a
 * real batch request can force `BatchProcessor::verifyRollback()` to
 * genuinely observe a failed rollback. This subclass overrides just that
 * one method to force the "verification failed" outcome, so the full
 * `process()` → `ROLLBACK_UNVERIFIED` response path — otherwise dead code
 * as far as the test suite is concerned — gets exercised end to end.
 */
class ForceUnverifiedRollbackBatchProcessor extends BatchProcessor
{
    protected function verifyRollback(array $operations, array $results): bool
    {
        return false;
    }
}
