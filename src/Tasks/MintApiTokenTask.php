<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Tasks\Support\ApiTokenMinter;
use SilverStripe\Dev\BuildTask;

/**
 * `ss5` (this branch) entry point. All business logic lives in
 * {@see ApiTokenMinter} — see #65/#96; this adapter only translates the
 * legacy `BuildTask::run($request)` request vars and echoes the result.
 * Branch `1`'s SS6 copy of this file wraps the same service around
 * `execute(InputInterface, PolyOutput): int` instead — there is no shared
 * entry point between the two branches, so a business-logic fix belongs in
 * `ApiTokenMinter` (shared) rather than either adapter.
 *
 * Usage: `sake dev/tasks/MintContentApiToken email=agent@example.com`
 */
class MintApiTokenTask extends BuildTask
{
    private static string $segment = 'MintContentApiToken';

    protected $title = 'Mint content API token';

    protected $description = 'Generates a new content API token for the member with the given '
        . 'email address, replacing any existing token. The plaintext token is shown once; colymba '
        . 'stores it in plaintext on the Member record.';

    public function run($request)
    {
        $result = ApiTokenMinter::create()->mint((string) $request->getVar('email'));

        foreach ($result->lines as $line) {
            echo $line . "\n";
        }
    }
}
