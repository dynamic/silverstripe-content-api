<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Tasks\CheckGrantExtensionReachabilityTask;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantUnreachableObject;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adapter-only coverage for branch `2`'s (this branch's) SS6 entry point:
 * that {@see GrantExtensionReachabilityChecker}'s findings reach the
 * rendered output in the expected shape. The reachability logic itself is
 * exercised once, branch-neutrally, in
 * `Tasks/Support/GrantExtensionReachabilityCheckerTest` — see #103.
 * `ApiTestGrantUnreachableObject` (a permanent test stub, not scoped to
 * one test class) guarantees at least one real finding whenever this
 * suite runs, so this test doesn't need its own fixture.
 */
class CheckGrantExtensionReachabilityTaskTest extends SapphireTest
{
    public function testFindingsAreRenderedWithClassMethodAndVerb(): void
    {
        $output = $this->runTask();

        $this->assertStringContainsString(ApiTestGrantUnreachableObject::class, $output);
        $this->assertStringContainsString('canEdit()', $output);
        $this->assertStringContainsString('"action"', $output);
        $this->assertStringContainsString('docs/en/04_security-model.md', $output);
    }

    protected function runTask(): string
    {
        $task = CheckGrantExtensionReachabilityTask::create();
        $input = new ArrayInput([]);
        $buffer = new BufferedOutput();
        $output = PolyOutput::create(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_NORMAL, false, $buffer);

        $task->run($input, $output);

        return $buffer->fetch();
    }
}
