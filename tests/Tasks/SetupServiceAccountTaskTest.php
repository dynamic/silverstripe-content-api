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

class SetupServiceAccountTaskTest extends SapphireTest
{
    public function testCreatesGroupWithBaseGrants(): void
    {
        $result = $this->runTask(['--group' => 'Test Service Accounts']);

        $this->assertSame(Command::SUCCESS, $result);

        $group = Group::get()->filter('Title', 'Test Service Accounts')->first();
        $this->assertNotNull($group);
        $this->assertGranted($group, ContentApiPermissions::ACCESS);
        $this->assertGranted($group, 'VIEW_DRAFT_CONTENT');
        $this->assertNotGranted($group, ContentApiPermissions::POPULATE);
    }

    public function testPopulateFlagAlsoGrantsPopulate(): void
    {
        $this->runTask(['--group' => 'Test Populate Accounts', '--populate' => true]);

        $group = Group::get()->filter('Title', 'Test Populate Accounts')->first();
        $this->assertGranted($group, ContentApiPermissions::ACCESS);
        $this->assertGranted($group, 'VIEW_DRAFT_CONTENT');
        $this->assertGranted($group, ContentApiPermissions::POPULATE);
    }

    public function testIsIdempotent(): void
    {
        $this->runTask(['--group' => 'Test Idempotent']);
        $this->runTask(['--group' => 'Test Idempotent']);

        $groups = Group::get()->filter('Title', 'Test Idempotent');
        $this->assertSame(1, $groups->count(), 're-running must not create a duplicate group');

        $group = $groups->first();
        $grants = Permission::get()->filter(['GroupID' => (int) $group->ID, 'Code' => ContentApiPermissions::ACCESS]);
        $this->assertSame(1, $grants->count(), 're-running must not duplicate a grant');
    }

    public function testExplicitEmptyGroupIsRejected(): void
    {
        $result = $this->runTask(['--group' => '']);

        $this->assertSame(Command::INVALID, $result);
    }

    /**
     * Regression for #42 code review: --group is VALUE_REQUIRED with a
     * default, so omitting the flag never actually leaves it empty — this
     * confirms the default title applies rather than the task silently
     * accepting some other unintended state.
     */
    public function testOmittingGroupFallsBackToTheDefaultTitle(): void
    {
        $result = $this->runTask([]);

        $this->assertSame(Command::SUCCESS, $result);
        $this->assertNotNull(Group::get()->filter('Title', 'Content API Service Accounts')->first());
    }

    protected function runTask(array $options): int
    {
        $task = SetupServiceAccountTask::create();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput($options, $definition);
        $output = PolyOutput::create(PolyOutput::FORMAT_ANSI);

        return $task->run($input, $output);
    }

    protected function assertGranted(Group $group, string $code): void
    {
        $this->assertTrue(
            Permission::get()->filter(['GroupID' => (int) $group->ID, 'Code' => $code])->exists(),
            "expected {$code} to be granted to group #{$group->ID}"
        );
    }

    protected function assertNotGranted(Group $group, string $code): void
    {
        $this->assertFalse(
            Permission::get()->filter(['GroupID' => (int) $group->ID, 'Code' => $code])->exists(),
            "expected {$code} to not be granted to group #{$group->ID}"
        );
    }
}
