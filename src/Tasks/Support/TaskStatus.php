<?php

namespace Dynamic\ContentApi\Tasks\Support;

/**
 * Outcome of a branch-neutral task-support call
 * ({@see ServiceAccountProvisioner}, {@see ApiTokenMinter}) — identical on
 * both branch `1` (SS6) and `ss5`, so a test asserting on `TaskResult::$status`
 * is byte-identical across branches too, unlike asserting on message wording.
 *
 * Deliberately carries no mapping to Symfony Console's exit-code constants
 * here — `symfony/console` arrives on the SS6 line via polyexecution, but
 * `ss5`'s composer.json doesn't require it directly, and a `use` import for
 * a package outside a branch's real dependency tree fails that branch's own
 * PHPStan run even if the method is never called. Branch `1`'s
 * `SetupServiceAccountTask`/`MintApiTokenTask` adapters own that mapping
 * themselves — see their `execute()` methods.
 */
enum TaskStatus
{
    case Success;
    case Invalid;
    case Failure;
}
