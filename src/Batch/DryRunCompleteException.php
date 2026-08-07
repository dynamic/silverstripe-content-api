<?php

namespace Dynamic\ContentApi\Batch;

use Exception;

/**
 * Internal control-flow exception: forces the transaction a `dryRun: true`
 * batch runs inside to roll back regardless of outcome — success or an
 * atomic abort — since a dry run must never persist anything either way.
 * Thrown unconditionally at the end of `BatchProcessor::runDryRun()`'s
 * transaction closure and caught immediately outside it; never escapes to
 * a caller.
 */
class DryRunCompleteException extends Exception
{
    public function __construct(
        public readonly array $outcome,
        public readonly ?int $failedIndex = null,
    ) {
        parent::__construct('Dry-run batch complete — forcing rollback.');
    }
}
