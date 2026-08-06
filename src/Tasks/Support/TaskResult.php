<?php

namespace Dynamic\ContentApi\Tasks\Support;

/**
 * Return value of a branch-neutral task-support call — a status enum plus
 * the human-readable lines a BuildTask adapter renders. Keeping the lines as
 * an ordered array (rather than a single string) lets each branch's adapter
 * decide how to emit them (`PolyOutput::writeln()` per line on branch `2`,
 * `echo` per line on branch `1`) without either side parsing the other's output.
 */
final class TaskResult
{
    /**
     * @param array<int, string> $lines
     */
    public function __construct(
        public readonly TaskStatus $status,
        public readonly array $lines = [],
    ) {
    }
}
