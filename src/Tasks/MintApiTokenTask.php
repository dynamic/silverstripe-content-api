<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Tasks\Support\ApiTokenMinter;
use Dynamic\ContentApi\Tasks\Support\TaskResultRenderer;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Branch `2` (this branch, SS6) entry point. All business logic lives in
 * {@see ApiTokenMinter} — see #65/#96; this adapter only translates Symfony
 * Console input/output. Branch `1`'s SS5 copy of this file wraps the same
 * service around the legacy `BuildTask::run($request)` entry point instead —
 * there is no shared entry point between the two branches, so a
 * business-logic fix belongs in `ApiTokenMinter` (shared) rather than either
 * adapter.
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
        $result = ApiTokenMinter::create()->mint((string) $input->getOption('email'));

        return TaskResultRenderer::render($output, $result);
    }
}
