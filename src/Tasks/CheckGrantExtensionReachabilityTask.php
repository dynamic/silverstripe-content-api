<?php

namespace Dynamic\ContentApi\Tasks;

use Dynamic\ContentApi\Tasks\Support\GrantExtensionReachabilityChecker;
use SilverStripe\Dev\BuildTask;

/**
 * Branch `1` (this branch) entry point. All business logic lives in
 * {@see GrantExtensionReachabilityChecker} — see #103; this adapter only
 * renders the result. Branch `2`'s SS6 copy of this file wraps the same
 * service around `execute(InputInterface, PolyOutput): int` instead —
 * there is no shared entry point between the two branches, so a
 * business-logic fix belongs in `GrantExtensionReachabilityChecker`
 * (shared) rather than either adapter.
 *
 * Usage: `sake dev/tasks/CheckGrantExtensionReachability`
 */
class CheckGrantExtensionReachabilityTask extends BuildTask
{
    private static string $segment = 'CheckGrantExtensionReachability';

    protected $title = 'Check ContentApiGrantExtension reachability';

    protected $description = 'Flags any class carrying ContentApiGrantExtension whose own '
        . 'can*() method (for a verb the class itself declares) never calls extendedCan() — '
        . 'the grant is silently unreachable there, per #103. Heuristic, not a proof: see '
        . 'GrantExtensionReachabilityChecker\'s own docblock for what it can and can\'t catch.';

    public function run($request)
    {
        $findings = GrantExtensionReachabilityChecker::create()->check();

        if ($findings === []) {
            echo "No unreachable grants found.\n";
            echo "(Heuristic check — see GrantExtensionReachabilityChecker's docblock for its limits.)\n";

            return;
        }

        echo "Found " . count($findings) . " class/method pair(s) where ContentApiGrantExtension\n";
        echo "is applied and would grant at least one verb, but the resolved can*() method's\n";
        echo "source has no visible extendedCan() call:\n\n";

        foreach ($findings as $finding) {
            $selfDeclared = $finding['class'] === $finding['declaringClass']
                ? ''
                : sprintf(' (inherited from %s)', $finding['declaringClass']);

            echo sprintf(
                "  %s::%s()%s — declares the \"%s\" verb(s)\n",
                $finding['class'],
                $finding['method'],
                $selfDeclared,
                implode('", "', $finding['verbs'])
            );
        }

        echo "\n";
        echo "Each of these classes' own can*() override needs to call extendedCan() first (the\n";
        echo "same pattern SiteTree's own can*() methods follow) for ContentApiGrantExtension to\n";
        echo "have any effect — see docs/en/04_security-model.md#grant-extension.\n";
    }
}
