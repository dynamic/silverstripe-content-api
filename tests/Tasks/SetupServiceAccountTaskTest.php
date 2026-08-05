<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Security\ContentApiPermissions;
use Dynamic\ContentApi\Tasks\SetupServiceAccountTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;

/**
 * Adapter-only coverage for branch `1`'s SS6 entry point: option parsing and
 * exit-code mapping. The provisioning business logic (idempotency,
 * self-healing, the ambiguous-title refusal, etc.) is exercised once,
 * branch-neutrally, in `Tasks/Support/ServiceAccountProvisionerTest` — see
 * #65.
 */
class SetupServiceAccountTaskTest extends SapphireTest
{
    // See ServiceAccountProvisionerTest — same reasoning, this adapter test
    // writes real Group/Permission rows too.
    protected $usesDatabase = true;

    public function testSuccessMapsToCommandSuccess(): void
    {
        $result = $this->runTask(['--group' => 'Test Adapter Success']);

        $this->assertSame(Command::SUCCESS, $result);
        $this->assertNotNull(Group::get()->filter('Title', 'Test Adapter Success')->first());
    }

    public function testPopulateOptionReachesTheProvisioner(): void
    {
        $this->runTask(['--group' => 'Test Adapter Populate', '--populate' => true]);

        $group = Group::get()->filter('Title', 'Test Adapter Populate')->first();
        $this->assertTrue(
            Permission::get()->filter(['GroupID' => (int) $group->ID, 'Code' => ContentApiPermissions::POPULATE])
                ->exists()
        );
    }

    public function testEmptyGroupMapsToCommandInvalid(): void
    {
        $this->assertSame(Command::INVALID, $this->runTask(['--group' => '']));
    }

    /**
     * Regression for #42 code review: --group is VALUE_REQUIRED with a
     * default, so omitting the flag never actually leaves it empty — this
     * confirms the default title applies rather than the adapter silently
     * accepting some other unintended state.
     */
    public function testOmittingGroupFallsBackToTheDefaultTitle(): void
    {
        $result = $this->runTask([]);

        $this->assertSame(Command::SUCCESS, $result);
        $this->assertNotNull(Group::get()->filter('Title', 'Content API Service Accounts')->first());
    }

    /**
     * A group-title collision maps to Command::FAILURE, not INVALID or
     * SUCCESS — the three-way exit-code mapping is adapter behavior, not
     * covered by the provisioner test's TaskStatus assertion alone.
     */
    public function testAmbiguousTitleMapsToCommandFailure(): void
    {
        Group::create(['Title' => 'Test Adapter Ambiguous'])->write();
        $duplicate = Group::create();
        $duplicate->Title = 'Test Adapter Ambiguous';
        $duplicate->write(false, false, false, false, true);

        $this->assertSame(Command::FAILURE, $this->runTask(['--group' => 'Test Adapter Ambiguous']));
    }

    protected function runTask(array $options): int
    {
        $task = SetupServiceAccountTask::create();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput($options, $definition);
        $output = PolyOutput::create(PolyOutput::FORMAT_ANSI);

        return $task->run($input, $output);
    }
}
