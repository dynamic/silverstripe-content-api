<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Tasks\MintApiTokenTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Member;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;

/**
 * Adapter-only coverage for branch `1`'s SS6 entry point: option parsing and
 * exit-code mapping. The minting business logic is exercised once,
 * branch-neutrally, in `Tasks/Support/ApiTokenMinterTest` — see #65. This
 * task previously had no test coverage at all, before or after the #65
 * extraction.
 */
class MintApiTokenTaskTest extends SapphireTest
{
    // See ServiceAccountProvisionerTest — no fixture file to imply DB use,
    // but this writes a real Member token.
    protected $usesDatabase = true;

    public function testSuccessMapsToCommandSuccess(): void
    {
        $member = Member::create(['Email' => 'mint-adapter-test@example.com']);
        $member->write();

        $result = $this->runTask(['--email' => 'mint-adapter-test@example.com']);

        $this->assertSame(Command::SUCCESS, $result);

        $member = Member::get()->byID($member->ID);
        $this->assertNotEmpty($member->ApiToken);
    }

    public function testMissingEmailMapsToCommandInvalid(): void
    {
        $this->assertSame(Command::INVALID, $this->runTask([]));
    }

    public function testUnknownEmailMapsToCommandFailure(): void
    {
        $this->assertSame(Command::FAILURE, $this->runTask(['--email' => 'no-such-adapter-member@example.com']));
    }

    protected function runTask(array $options): int
    {
        $task = MintApiTokenTask::create();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput($options, $definition);
        $output = PolyOutput::create(PolyOutput::FORMAT_ANSI);

        return $task->run($input, $output);
    }
}
