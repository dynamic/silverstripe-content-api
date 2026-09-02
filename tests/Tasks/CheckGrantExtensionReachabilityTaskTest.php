<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Tasks\CheckGrantExtensionReachabilityTask;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantMissingExtensionObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestGrantUnreachableObject;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;

/**
 * Adapter-only coverage for this branch's legacy `run($request)` entry
 * point: that {@see GrantExtensionReachabilityChecker}'s findings reach
 * stdout in the expected shape. The reachability logic itself is
 * exercised once, branch-neutrally, in
 * `Tasks/Support/GrantExtensionReachabilityCheckerTest` — see #103 and
 * #197. `ApiTestGrantUnreachableObject` (a permanent test stub, not
 * scoped to one test class) guarantees at least one real check() finding
 * whenever this suite runs; `ApiTestGrantMissingExtensionObject` is
 * registered here explicitly so the second (#197) rendering block also
 * always has something to show.
 */
class CheckGrantExtensionReachabilityTaskTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        ApiTestGrantMissingExtensionObject::class,
    ];

    public function testFindingsAreRenderedWithClassMethodAndVerb(): void
    {
        $output = $this->runTask();

        $this->assertStringContainsString(ApiTestGrantUnreachableObject::class, $output);
        $this->assertStringContainsString('canEdit()', $output);
        $this->assertStringContainsString('"action"', $output);
        $this->assertStringContainsString('docs/en/04_security-model.md', $output);
    }

    public function testMissingExtensionFindingsAreRenderedSeparately(): void
    {
        $output = $this->runTask();

        $this->assertStringContainsString(ApiTestGrantMissingExtensionObject::class, $output);
        $this->assertStringContainsString('api_writable_fields', $output);
        $this->assertStringContainsString('docs/en/02_configuration.md', $output);
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
