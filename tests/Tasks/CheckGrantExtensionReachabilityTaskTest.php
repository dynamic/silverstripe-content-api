<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Tasks\CheckGrantExtensionReachabilityTask;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantUnreachableObject;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;

/**
 * Adapter-only coverage for this branch's legacy `run($request)` entry
 * point: that {@see GrantExtensionReachabilityChecker}'s findings reach
 * stdout in the expected shape. The reachability logic itself is
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
        $request = new HTTPRequest('GET', '/', []);

        ob_start();
        $task->run($request);

        return ob_get_clean();
    }
}
