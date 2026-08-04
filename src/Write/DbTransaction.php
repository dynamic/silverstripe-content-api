<?php

namespace Dynamic\ContentApi\Write;

use SilverStripe\ORM\DB;

/**
 * Thin wrapper around `DB::get_conn()->withTransaction()` with this module's
 * fixed defaults (no error callback, default transaction mode, always error
 * if the underlying connection doesn't support transactions) — used by every
 * write path that needs an atomic unit of work, so the arguments only need
 * to be right in one place.
 */
class DbTransaction
{
    public static function run(callable $work): void
    {
        DB::get_conn()->withTransaction($work, null, false, true);
    }
}
