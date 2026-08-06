<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Tasks\Support\GrantExtensionReachabilityChecker;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Branch `2` (this branch, SS6) entry point. All business logic lives in
 * {@see GrantExtensionReachabilityChecker} — see #103; this adapter only
 * renders the result. Branch `1`'s SS5 copy of this file wraps the same
 * service around the legacy `BuildTask::run($request)` entry point instead —
 * there is no shared entry point between the two branches, so a
 * business-logic fix belongs in `GrantExtensionReachabilityChecker`
 * (shared) rather than either adapter. The findings-to-lines rendering
 * below is duplicated (not extracted into `Tasks/Support/`, unlike
 * {@see \Dynamic\ContentApi\Tasks\Support\TaskResult}/{@see
 * \Dynamic\ContentApi\Tasks\Support\TaskResultRenderer}) because
 * `GrantExtensionReachabilityChecker::check()` returns raw structured
 * findings, not a branch-neutral `TaskResult` — there's no shared return
 * shape to render identically, only shared business logic to format.
 *
 * Usage: `sake tasks:CheckGrantExtensionReachability`
 */
class CheckGrantExtensionReachabilityTask extends BuildTask
{
    protected static string $commandName = 'CheckGrantExtensionReachability';

    protected string $title = 'Check ContentApiGrantExtension reachability';

    protected static string $description = 'Flags any class carrying ContentApiGrantExtension whose own '
        . 'can*() method (for a verb the class itself declares) never calls extendedCan() — '
        . 'the grant is silently unreachable there, per #103. Heuristic, not a proof: see '
        . 'GrantExtensionReachabilityChecker\'s own docblock for what it can and can\'t catch.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $findings = GrantExtensionReachabilityChecker::create()->check();

        if ($findings === []) {
            $output->writeln('No unreachable grants found.');
            $output->writeln("(Heuristic check — see GrantExtensionReachabilityChecker's docblock for its limits.)");

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            'Found %d class/method pair(s) where ContentApiGrantExtension',
            count($findings)
        ));
        $output->writeln('is applied and would grant at least one verb, but the resolved can*() method\'s');
        $output->writeln('source has no visible extendedCan() call:');
        $output->writeln('');

        foreach ($findings as $finding) {
            $selfDeclared = $finding['class'] === $finding['declaringClass']
                ? ''
                : sprintf(' (inherited from %s)', $finding['declaringClass']);

            $output->writeln(sprintf(
                '  %s::%s()%s — declares the "%s" verb(s)',
                $finding['class'],
                $finding['method'],
                $selfDeclared,
                implode('", "', $finding['verbs'])
            ));
        }

        $output->writeln('');
        $output->writeln("Each of these classes' own can*() override needs to call extendedCan() first (the");
        $output->writeln("same pattern SiteTree's own can*() methods follow) for ContentApiGrantExtension to");
        $output->writeln('have any effect — see docs/en/04_security-model.md#grant-extension.');

        return Command::SUCCESS;
    }
}
