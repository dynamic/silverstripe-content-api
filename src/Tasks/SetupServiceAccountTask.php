<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Tasks\Support\ServiceAccountProvisioner;
use Dynamic\ContentApi\Tasks\Support\TaskResultRenderer;
use Dynamic\ContentApi\Tasks\Support\TaskStatus;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Branch `2` (this branch, SS6) entry point. All business logic lives in
 * {@see ServiceAccountProvisioner} — see #65/#96; this adapter only
 * translates Symfony Console input/output. Branch `1`'s SS5 copy of this
 * file wraps the same service around the legacy `BuildTask::run($request)`
 * entry point instead — there is no shared entry point between the two
 * branches, so a business-logic fix belongs in `ServiceAccountProvisioner`
 * (shared) rather than either adapter.
 *
 * Usage: `sake tasks:SetupContentApiServiceAccount --group="Content API Service Accounts"`
 * (add `--populate` too if the account needs batch/compositions/asset writes/page actions).
 */
class SetupServiceAccountTask extends BuildTask
{
    protected static string $commandName = 'SetupContentApiServiceAccount';

    protected string $title = 'Set up a content API service-account group';

    protected static string $description = 'Provisions (or updates) a permission Group with the grants a '
        . 'content API service account needs: CONTENT_API_ACCESS + VIEW_DRAFT_CONTENT always, '
        . 'CONTENT_API_POPULATE with --populate. Idempotent.';

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
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $result = ServiceAccountProvisioner::create()->provision(
            (string) $input->getOption('group'),
            (bool) $input->getOption('populate'),
        );

        $exitCode = TaskResultRenderer::render($output, $result);

        if ($result->status === TaskStatus::Success) {
            $output->writeln('Mint a token: sake tasks:MintContentApiToken --email=<member-email>');
        }

        return $exitCode;
    }
}
