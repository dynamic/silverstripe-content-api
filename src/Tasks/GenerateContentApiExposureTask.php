<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Tasks\Support\ExposureScaffolder;
use InvalidArgumentException;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Branch `2` (this branch, SS6) entry point. All business logic lives in
 * {@see ExposureScaffolder} — see #115/#118; this adapter only translates
 * Symfony Console input/output, following the same split as {@see
 * CheckGrantExtensionReachabilityTask}/{@see
 * Support\GrantExtensionReachabilityChecker}. Branch `1`'s SS5 copy of this
 * file wraps the same service around the legacy `BuildTask::run($request)`
 * entry point instead — there is no shared entry point between the two
 * branches, so a business-logic fix belongs in `ExposureScaffolder`
 * (shared) rather than either adapter.
 *
 * Usage: `sake tasks:GenerateContentApiExposure --root=DNADesign\Elemental\Models\BaseElement`
 * (repeat `--root` for more than one; add `--exclude=` to skip a subtree;
 * `--write=<path>` to write the output to a file instead of stdout).
 */
class GenerateContentApiExposureTask extends BuildTask
{
    protected static string $commandName = 'GenerateContentApiExposure';

    protected string $title = 'Generate content-api exposure config';

    protected static string $description = 'Introspects one or more classes (and their concrete '
        . 'subclasses) and prints starting-point api_access/api_writable_fields/'
        . 'api_writable_relations YAML for each — a scaffold to review and paste into your own '
        . 'project config, not something this task applies itself. See #115/#118.';

    /**
     * Default write target for `--write` passed with no value — a project's
     * own `--write=some/other/path.yml` always wins; this is only the
     * fallback when the flag is bare. Numeric prefix keeps SilverStripe's
     * `_config/` manifest load order predictable without needing an
     * `Only`/`After` guard, since — unlike `essentials.yml`/`linkfield.yml`
     * — this file's content isn't gated on another package being installed.
     */
    private const DEFAULT_WRITE_PATH = '_config/999-content-api-generated.yml';

    public function getOptions(): array
    {
        return [
            new InputOption(
                'root',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'FQCN to scaffold (repeatable). Required — there is no default root.'
            ),
            new InputOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'FQCN (and its subclasses) to skip, on top of the module\'s own denylist (repeatable).'
            ),
            new InputOption(
                'write',
                null,
                InputOption::VALUE_OPTIONAL,
                'Write the generated YAML to this path (project-relative), wholesale-overwriting it, '
                    . 'instead of printing to stdout. Bare --write uses '
                    . self::DEFAULT_WRITE_PATH . '.',
                false
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $roots = (array) $input->getOption('root');
        $excludes = (array) $input->getOption('exclude');

        try {
            $yaml = Injector::inst()->get(ExposureScaffolder::class)->generate($roots, $excludes);
        } catch (InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }

        $writeOption = $input->getOption('write');

        if ($writeOption === false) {
            $output->writeln($yaml);

            return Command::SUCCESS;
        }

        $path = BASE_PATH . '/' . ltrim(is_string($writeOption) ? $writeOption : self::DEFAULT_WRITE_PATH, '/');
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $output->writeln(sprintf('<error>Could not create directory "%s".</error>', $dir));

            return Command::FAILURE;
        }

        if (file_put_contents($path, $yaml) === false) {
            $output->writeln(sprintf('<error>Could not write "%s".</error>', $path));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Wrote %s', $path));
        $output->writeln('Review the diff, then `dev/build flush=1` before it takes effect.');

        return Command::SUCCESS;
    }
}
