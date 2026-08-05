<?php

namespace Dynamic\ContentApi\Tasks\Support;

use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;

/**
 * Renders a branch-neutral {@see TaskResult} to Symfony Console's
 * `PolyOutput` and maps its {@see TaskStatus} to a `Command::*` exit code —
 * the one piece of SS6-specific glue both `Tasks/` adapters need. Kept out
 * of `TaskStatus` itself (which `ss5` also loads, but without a
 * `symfony/console` dependency to match) and out of each adapter (so the
 * `<error>` wrapping logic has a single test target instead of two
 * copy-pasted ones) — SS6 (branch `1`) only.
 */
class TaskResultRenderer
{
    public static function render(PolyOutput $output, TaskResult $result): int
    {
        $isError = $result->status !== TaskStatus::Success;

        foreach ($result->lines as $line) {
            $output->writeln($isError && $line !== '' ? "<error>{$line}</error>" : $line);
        }

        return match ($result->status) {
            TaskStatus::Success => Command::SUCCESS,
            TaskStatus::Invalid => Command::INVALID,
            TaskStatus::Failure => Command::FAILURE,
        };
    }
}
