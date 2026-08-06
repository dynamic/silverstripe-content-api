<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Tasks\Support\ServiceAccountProvisioner;
use Dynamic\ContentApi\Tasks\Support\TaskStatus;
use SilverStripe\Dev\BuildTask;

/**
 * `ss5` (this branch) entry point. All business logic lives in
 * {@see ServiceAccountProvisioner} — see #65/#96; this adapter only
 * translates the legacy `BuildTask::run($request)` request vars and echoes
 * the result. Branch `1`'s SS6 copy of this file wraps the same service
 * around `execute(InputInterface, PolyOutput): int` instead — there is no
 * shared entry point between the two branches, so a business-logic fix
 * belongs in `ServiceAccountProvisioner` (shared) rather than either
 * adapter.
 *
 * Usage: `sake dev/tasks/SetupContentApiServiceAccount group="Content API Service Accounts"`
 * (add `populate=1` too if the account needs batch/compositions/asset writes/page actions).
 */
class SetupServiceAccountTask extends BuildTask
{
    private static string $segment = 'SetupContentApiServiceAccount';

    protected $title = 'Set up a content API service-account group';

    protected $description = 'Provisions (or updates) a permission Group with the grants a '
        . 'content API service account needs: CONTENT_API_ACCESS + VIEW_DRAFT_CONTENT always, '
        . 'CONTENT_API_POPULATE with populate=1. Idempotent.';

    public function run($request)
    {
        $rawGroup = $request->getVar('group');
        $title = $rawGroup === null ? 'Content API Service Accounts' : trim((string) $rawGroup);

        // Presence-based, not value-based — matches the old VALUE_NONE
        // --populate flag's semantics (there was no way to pass "false" to
        // it either). A PHP truthy check on the raw string would otherwise
        // treat `populate=false` or `populate=no` as granting it.
        $populate = $request->getVar('populate') !== null;

        $result = ServiceAccountProvisioner::create()->provision($title, $populate);

        foreach ($result->lines as $line) {
            echo $line . "\n";
        }

        if ($result->status === TaskStatus::Success) {
            echo "\n";
            echo "Mint a token: sake dev/tasks/MintContentApiToken email=<member-email>\n";
        }
    }
}
