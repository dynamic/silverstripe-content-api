<?php

namespace Dynamic\ContentApi\Write;

use SilverStripe\ORM\DB;

/**
 * Thin wrapper around `DB::get_conn()->withTransaction()` with this module's
 * fixed defaults (no error callback, default transaction mode, always error
 * if the underlying connection doesn't support transactions) — used by every
 * write path that needs an atomic unit of work, so the arguments only need
 * to be right in one place.
 *
 * `withTransaction()` itself (silverstripe/framework `Database::withTransaction()`)
 * only ever catches `Exception`, not `Throwable` — a PHP `Error` (`TypeError`,
 * `ArgumentCountError`, etc., all real occurrences elsewhere in this codebase,
 * not hypothetical) escaping `$work` would leave `transactionRollback()`
 * uncalled and the framework's transaction-nesting counter incremented for
 * the rest of the request (#136). `$work` is wrapped here to close that gap
 * at the one place every caller already goes through, rather than requiring
 * every `DbTransaction::run()` call site to guard against it separately.
 *
 * Deliberately catches `\Error`, not `\Throwable` — `Throwable` is exactly
 * `Exception | Error`, and also catching `Exception` here would roll back
 * *twice* for the `Exception` path `withTransaction()` already handles
 * correctly on its own. This wrapper only ever needs to cover the gap the
 * framework leaves, not duplicate what it already does.
 */
class DbTransaction
{
    public static function run(callable $work): void
    {
        $connection = DB::get_conn();

        $connection->withTransaction(function () use ($work, $connection) {
            try {
                $work();
            } catch (\Error $error) {
                if ($connection->supportsTransactions()) {
                    $connection->transactionRollback();
                }

                throw $error;
            }
        }, null, false, true);
    }
}
