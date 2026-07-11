<?php

namespace Dynamic\ContentApi\Tasks;

use Colymba\RESTfulAPI\Authenticators\TokenAuthenticator;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Member;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Mint (or rotate) a content API token for a member without a password
 * round-trip — the standard way to provision agent/service accounts. Uses
 * colymba/silverstripe-restfulapi's TokenAuthenticator, so the token works on
 * both the /api and /content-api/v1 surfaces.
 *
 * Usage: `sake tasks:MintContentApiToken --email=agent@example.com`
 */
class MintApiTokenTask extends BuildTask
{
    protected static string $commandName = 'MintContentApiToken';

    protected string $title = 'Mint content API token';

    protected static string $description = 'Generates a new content API token for the member with the given '
        . 'email address, replacing any existing token. The plaintext token is shown once; colymba '
        . 'stores it in plaintext on the Member record.';

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

        $auth = Injector::inst()->create(TokenAuthenticator::class);
        $auth->resetToken((int) $member->ID);
        $token = $auth->getToken((int) $member->ID);
        $expires = date('c', (int) Member::get()->byID($member->ID)->ApiTokenExpire);

        $output->writeln("Token minted for {$email} (member #{$member->ID}), expires {$expires}:");
        $output->writeln('');
        $output->writeln("  {$token}");
        $output->writeln('');
        $output->writeln('Note: colymba/silverstripe-restfulapi stores tokens in plaintext on the');
        $output->writeln('Member record — anyone with CMS access to Members can read them.');

        return Command::SUCCESS;
    }
}
