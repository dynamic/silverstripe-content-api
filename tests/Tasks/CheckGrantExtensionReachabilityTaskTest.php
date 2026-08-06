<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Tasks\CheckGrantExtensionReachabilityTask;
use Dynamic\ContentApi\Tasks\Support\GrantExtensionReachabilityChecker;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantUnreachableObject;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adapter-only coverage for branch `2`'s (this branch's) SS6 entry point:
 * that {@see GrantExtensionReachabilityChecker}'s findings reach the
 * rendered output in the expected shape, and the exit-code mapping this
 * branch's `execute(): int` return adds over branch `1`'s `void run()` (the
 * one behavior genuinely new in this branch's port, so the one thing the
 * sibling adapter tests' pattern — see `MintApiTokenTaskTest`,
 * `SetupServiceAccountTaskTest` — wouldn't already cover for free). The
 * reachability logic itself is exercised once, branch-neutrally, in
 * `Tasks/Support/GrantExtensionReachabilityCheckerTest` — see #103.
 * `ApiTestGrantUnreachableObject` (a permanent test stub, not scoped to
 * one test class) guarantees at least one real finding whenever this
 * suite runs, so this test doesn't need its own fixture.
 */
class CheckGrantExtensionReachabilityTaskTest extends SapphireTest
{
    public function testFindingsAreRenderedWithClassMethodAndVerb(): void
    {
        [$exitCode, $output] = $this->runTask();

        $this->assertStringContainsString(ApiTestGrantUnreachableObject::class, $output);
        $this->assertStringContainsString('canEdit()', $output);
        $this->assertStringContainsString('"action"', $output);
        $this->assertStringContainsString('docs/en/04_security-model.md', $output);

        // Deliberate: a diagnostic reporting findings is not itself a task
        // failure — same "diagnostic, not a gate" reasoning the task's own
        // class docblock states for why this isn't wired into dev/build. A
        // caller wanting CI to fail on any finding should grep this task's
        // own output, not rely on the exit code.
        $this->assertSame(Command::SUCCESS, $exitCode, 'reporting findings must not itself fail the task');
    }

    /**
     * ApiTestGrantUnreachableObject is a permanent, suite-wide stub (not
     * scoped to one test class), so the checker never actually reports zero
     * findings while this suite is running — exercising the task's separate
     * "nothing found" return branch needs the checker service itself
     * swapped out, not just a differently-configured fixture.
     */
    public function testNoFindingsAlsoMapsToCommandSuccess(): void
    {
        Injector::inst()->registerService(
            new class extends GrantExtensionReachabilityChecker {
                public function check(): array
                {
                    return [];
                }
            },
            GrantExtensionReachabilityChecker::class
        );

        [$exitCode, $output] = $this->runTask();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No unreachable grants found.', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    protected function runTask(): array
    {
        $task = CheckGrantExtensionReachabilityTask::create();
        $input = new ArrayInput([]);
        $buffer = new BufferedOutput();
        $output = PolyOutput::create(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_NORMAL, false, $buffer);

        $exitCode = $task->run($input, $output);

        return [$exitCode, $buffer->fetch()];
    }
}
