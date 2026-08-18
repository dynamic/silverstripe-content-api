<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Tasks\Support\ServiceAccountMemberProvisioner;
use Dynamic\ContentApi\Tasks\Support\ServiceAccountProvisioner;
use Dynamic\ContentApi\Tasks\Support\TaskResultRenderer;
use Dynamic\ContentApi\Tasks\Support\TaskStatus;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Branch `2` (this branch, SS6) entry point. All business logic lives in
 * {@see ServiceAccountProvisioner} (group + permission codes) and, when
 * `--member` is passed, {@see ServiceAccountMemberProvisioner} (#124: the
 * Member itself) — see #65/#96; this adapter only translates Symfony
 * Console input/output. Branch `1`'s SS5 copy of this file wraps the same
 * services around the legacy `BuildTask::run($request)` entry point
 * instead — there is no shared entry point between the two branches, so a
 * business-logic fix belongs in the shared `Support/` classes rather than
 * either adapter.
 *
 * Usage: `sake tasks:SetupContentApiServiceAccount --group="Content API Service Accounts"`
 * (add `--populate` too if the account needs batch/compositions/asset writes/page actions).
 * Add `--member=agent@example.com` to also find-or-create that Member and attach it to the
 * group — explicit opt-in, not run by default, since it can create a login-capable account.
 */
class SetupServiceAccountTask extends BuildTask
{
    protected static string $commandName = 'SetupContentApiServiceAccount';

    protected string $title = 'Set up a content API service-account group';

    protected static string $description = 'Provisions (or updates) a permission Group with the grants a '
        . 'content API service account needs: CONTENT_API_ACCESS + VIEW_DRAFT_CONTENT always, '
        . 'CONTENT_API_POPULATE with --populate. Idempotent. Pass --member=<email> to also '
        . 'find-or-create that Member and attach it to the group (#124).';

    public function getOptions(): array
    {
        return [
            new InputOption(
                'group',
                null,
                InputOption::VALUE_REQUIRED,
                'Title of the Group to create or update',
                'Content API Service Accounts'
            ),
            new InputOption(
                'populate',
                null,
                InputOption::VALUE_NONE,
                'Also grant CONTENT_API_POPULATE (batch/compositions/asset writes/page actions)'
            ),
            new InputOption(
                'member',
                null,
                InputOption::VALUE_REQUIRED,
                'Email of a Member to find-or-create and attach to the group (#124) — omit to skip '
                    . 'this step entirely'
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $groupTitle = (string) $input->getOption('group');

        $result = ServiceAccountProvisioner::create()->provision(
            $groupTitle,
            (bool) $input->getOption('populate'),
        );

        $exitCode = TaskResultRenderer::render($output, $result);

        if ($result->status !== TaskStatus::Success) {
            return $exitCode;
        }

        $memberEmail = $input->getOption('member');

        if ($memberEmail === null) {
            $output->writeln('Mint a token: sake tasks:MintContentApiToken --email=<member-email>');

            return $exitCode;
        }

        $memberResult = ServiceAccountMemberProvisioner::create()->provision((string) $memberEmail, $groupTitle);
        $exitCode = TaskResultRenderer::render($output, $memberResult);

        if ($memberResult->status === TaskStatus::Success) {
            $output->writeln("Mint a token: sake tasks:MintContentApiToken --email={$memberEmail}");
        }

        return $exitCode;
    }
}
