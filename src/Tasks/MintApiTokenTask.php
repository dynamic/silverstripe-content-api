<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Tasks\Support\ApiTokenMinter;
use Dynamic\ContentApi\Tasks\Support\TaskResultRenderer;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * SS6 (branch `1`) entry point. All business logic lives in
 * {@see ApiTokenMinter} — see #65; this adapter only translates Symfony
 * Console input/output. `ss5`'s copy of this file is intended to adopt the
 * same structure around its legacy `BuildTask::run($request)` entry point;
 * until that port lands, `ss5` still carries the inline logic (see the
 * docblock on its own copy of this file).
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
