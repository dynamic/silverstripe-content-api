<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Auth\TokenAuthenticator;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Member;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Mint (or rotate) a content API token for a member without a password
 * round-trip — the standard way to provision agent/service accounts.
 *
 * Usage: `sake tasks:MintContentApiToken --email=agent@example.com`
 */
class MintApiTokenTask extends BuildTask
{
    protected static string $commandName = 'MintContentApiToken';

    protected string $title = 'Mint content API token';

    protected static string $description = 'Generates a new content API token for the member with the given '
        . 'email address, replacing any existing token. The plaintext token is shown once and stored '
        . 'only as a hash.';

    public function getOptions(): array
    {
        return [
            new InputOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'Email address of the member to mint a token for'
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $email = (string) $input->getOption('email');

        if ($email === '') {
            $output->writeln('<error>Missing required option: --email</error>');

            return Command::INVALID;
        }

        $member = Member::get()->filter('Email', $email)->first();

        if (!$member) {
            $output->writeln("<error>No member found with email {$email}</error>");

            return Command::FAILURE;
        }

        $token = TokenAuthenticator::singleton()->mintToken($member);
        $expires = date('c', (int) $member->ContentApiTokenExpire);

        $output->writeln("Token minted for {$email} (member #{$member->ID}), expires {$expires}:");
        $output->writeln('');
        $output->writeln("  {$token}");
        $output->writeln('');
        $output->writeln('Store it now — only its hash is persisted.');

        return Command::SUCCESS;
    }
}
