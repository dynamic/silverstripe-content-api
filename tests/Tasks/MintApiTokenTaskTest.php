<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Tasks\MintApiTokenTask;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * Adapter-only coverage for this branch's legacy `run($request)` entry
 * point: request-var parsing and that {@see TaskResult} lines reach stdout.
 * The minting business logic is exercised once, branch-neutrally, in
 * `Tasks/Support/ApiTokenMinterTest` — see #65/#96. This task previously
 * had no test coverage at all on this branch.
 */
class MintApiTokenTaskTest extends SapphireTest
{
    // See ServiceAccountProvisionerTest — no fixture file to imply DB use,
    // but this writes a real Member token.
    protected $usesDatabase = true;

    public function testSuccessMintsAndEchoesTheToken(): void
    {
        $member = Member::create(['Email' => 'mint-adapter-test@example.com']);
        $member->write();

        $output = $this->runTask(['email' => 'mint-adapter-test@example.com']);

        $this->assertStringContainsString('Token minted for mint-adapter-test@example.com', $output);

        $member = Member::get()->byID($member->ID);
        $this->assertNotEmpty($member->ApiToken);
    }

    public function testMissingEmailIsReported(): void
    {
        $output = $this->runTask([]);

        $this->assertStringContainsString('Missing required option', $output);
    }

    public function testUnknownEmailIsReported(): void
    {
        $output = $this->runTask(['email' => 'no-such-adapter-member@example.com']);

        $this->assertStringContainsString('No member found', $output);
    }

    protected function runTask(array $vars): string
    {
        $task = MintApiTokenTask::create();
        $request = new HTTPRequest('GET', '/', $vars);

        ob_start();
        $task->run($request);

        return ob_get_clean();
    }
}
