<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Security\ContentApiPermissions;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Provision (or update) the permission Group a content API service account
 * needs. Idempotent — re-running only adds missing grants, never duplicates
 * or removes existing ones.
 *
 * Grants CONTENT_API_ACCESS + VIEW_DRAFT_CONTENT unconditionally — the pair
 * a service account needs so a draft-only write (batch/composition default
 * `publish: "none"`) can be read back by the same account that wrote it.
 * Without VIEW_DRAFT_CONTENT, silverstripe/versioned's own canView() hook
 * denies the read the moment draft and live diverge, regardless of any
 * app-level canView() grant — see
 * docs/en/04_security-model.md#service-account-permissions (#42).
 * CONTENT_API_POPULATE is added only with --populate — a separate, narrower
 * grant most service accounts don't need.
 *
 * This task provisions permission *codes* only. A service account also
 * needs an app-level canView()/canEdit() grant extension on the classes it
 * writes — that's application code this task can't inject.
 *
 * Usage: `sake tasks:SetupContentApiServiceAccount --group="API Service Accounts" [--populate]`
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
        $title = (string) $input->getOption('group');

        if ($title === '') {
            $output->writeln('<error>--group cannot be empty.</error>');

            return Command::INVALID;
        }

        $group = Group::get()->filter('Title', $title)->first();

        if (!$group) {
            $group = Group::create();
            $group->Title = $title;
            $group->write();
            $output->writeln("Created group \"{$title}\" (#{$group->ID}).");
        } else {
            $output->writeln("Using existing group \"{$title}\" (#{$group->ID}).");
        }

        $codes = [ContentApiPermissions::ACCESS, 'VIEW_DRAFT_CONTENT'];

        if ($input->getOption('populate')) {
            $codes[] = ContentApiPermissions::POPULATE;
        }

        foreach ($codes as $code) {
            Permission::grant((int) $group->ID, $code);
            $output->writeln("  granted {$code}");
        }

        $output->writeln('');
        $output->writeln(
            'This task provisions permission codes only. A service account also needs an '
                . 'app-level canView()/canEdit() grant extension on the classes it writes — that\'s '
                . 'application code this task can\'t inject. Assign a Member to this group, then mint '
                . 'a token: sake tasks:MintContentApiToken --email=<member-email>'
        );

        return Command::SUCCESS;
    }
}
