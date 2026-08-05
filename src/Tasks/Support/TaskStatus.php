<?php

namespace Dynamic\ContentApi\Tasks\Support;

use Symfony\Component\Console\Command\Command;

/**
 * Outcome of a branch-neutral task-support call
 * ({@see ServiceAccountProvisioner}, {@see ApiTokenMinter}) — identical on
 * both branch `1` (SS6) and `ss5`, so a test asserting on `TaskResult::$status`
 * is byte-identical across branches too, unlike asserting on message wording.
 */
enum TaskStatus
{
    case Success;
    case Invalid;
    case Failure;

    /**
     * Map to Symfony Console's exit-code constants — only branch `1`'s SS6
     * `execute(InputInterface, PolyOutput): int` entry point needs this;
     * `ss5`'s legacy `run($request): void` has no return value to map.
     */
    public function toCommandExitCode(): int
    {
        return match ($this) {
            self::Success => Command::SUCCESS,
            self::Invalid => Command::INVALID,
            self::Failure => Command::FAILURE,
        };
    }
}
