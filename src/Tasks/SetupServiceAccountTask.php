<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Tasks\Support\ServiceAccountMemberProvisioner;
use Dynamic\ContentApi\Tasks\Support\ServiceAccountProvisioner;
use Dynamic\ContentApi\Tasks\Support\TaskStatus;
use SilverStripe\Dev\BuildTask;

/**
 * Branch `1` (this branch) entry point. All business logic lives in
 * {@see ServiceAccountProvisioner} (group + permission codes) and, when
 * `member=` is passed, {@see ServiceAccountMemberProvisioner} (#124: the
 * Member itself) — see #65/#96; this adapter only translates the legacy
 * `BuildTask::run($request)` request vars and echoes the result. Branch
 * `2`'s SS6 copy of this file wraps the same services around
 * `execute(InputInterface, PolyOutput): int` instead — there is no shared
 * entry point between the two branches, so a business-logic fix belongs in
 * the shared `Support/` classes rather than either adapter.
 *
 * Usage: `sake dev/tasks/SetupContentApiServiceAccount group="Content API Service Accounts"`
 * (add `populate=1` too if the account needs batch/compositions/asset writes/page actions).
 * Add `member=agent@example.com` to also find-or-create that Member and attach it to the
 * group — explicit opt-in, not run by default, since it can create a login-capable account.
 */
class SetupServiceAccountTask extends BuildTask
{
    private static string $segment = 'SetupContentApiServiceAccount';

    protected $title = 'Set up a content API service-account group';

    protected $description = 'Provisions (or updates) a permission Group with the grants a '
        . 'content API service account needs: CONTENT_API_ACCESS + VIEW_DRAFT_CONTENT always, '
        . 'CONTENT_API_POPULATE with populate=1. Idempotent. Pass member=<email> to also '
        . 'find-or-create that Member and attach it to the group (#124).';

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
            // ServiceAccountProvisioner is shared with branch `2`'s SS6 --flag-based
            // adapter, so its own message text uses that syntax — translate to this
            // branch's `key=value` request-var syntax before it reaches the operator.
            echo str_replace('--group', 'group', $line) . "\n";
        }

        if ($result->status !== TaskStatus::Success) {
            return;
        }

        $memberEmail = $request->getVar('member');

        if ($memberEmail === null) {
            echo "\n";
            echo "Mint a token: sake dev/tasks/MintContentApiToken email=<member-email>\n";

            return;
        }

        echo "\n";

        $memberResult = ServiceAccountMemberProvisioner::create()->provision((string) $memberEmail, $title);

        foreach ($memberResult->lines as $line) {
            echo str_replace('--member', 'member', $line) . "\n";
        }

        if ($memberResult->status === TaskStatus::Success) {
            echo "\n";
            echo "Mint a token: sake dev/tasks/MintContentApiToken email={$memberEmail}\n";
        }
    }
}
