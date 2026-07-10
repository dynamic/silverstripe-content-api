<?php

namespace Dynamic\ContentApi\Batch;

use Exception;

/**
 * Internal control-flow exception: aborts an atomic batch so the surrounding
 * transaction rolls back, carrying the partial results for the error body.
 */
class BatchAbortException extends Exception
{
    public function __construct(
        public readonly int $failedIndex,
        public readonly array $partialOutcome,
    ) {
        parent::__construct("Atomic batch aborted at operation {$failedIndex}.");
    }
}
