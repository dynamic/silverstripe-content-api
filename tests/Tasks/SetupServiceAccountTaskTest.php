<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Security\ContentApiPermissions;
use Dynamic\ContentApi\Tasks\SetupServiceAccountTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;

/**
 * Adapter-only coverage for branch `2`'s (this branch's) SS6 entry point: option parsing and
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

    /**
     * #124: omitting `--member` entirely keeps the pre-existing behavior —
     * group provisioning only, no member step run at all. Explicit
     * opt-in, not run by default.
     */
    public function testOmittingMemberSkipsMemberProvisioningEntirely(): void
    {
        Member::get()->filter('Email', 'adapter-member-test@example.com')->removeAll();

        $result = $this->runTask(['--group' => 'Test Adapter No Member']);

        $this->assertSame(Command::SUCCESS, $result);
        $this->assertNull(Member::get()->filter('Email', 'adapter-member-test@example.com')->first());
    }

    /**
     * #124: passing --member also find-or-creates that Member and attaches
     * it to the just-provisioned group.
     */
    public function testMemberOptionAlsoProvisionsAndAttachesTheMember(): void
    {
        Member::get()->filter('Email', 'adapter-member-test@example.com')->removeAll();

        $result = $this->runTask([
            '--group' => 'Test Adapter With Member',
            '--member' => 'adapter-member-test@example.com',
        ]);

        $group = Group::get()->filter('Title', 'Test Adapter With Member')->first();
        $member = Member::get()->filter('Email', 'adapter-member-test@example.com')->first();

        $this->assertSame(Command::SUCCESS, $result);
        $this->assertNotNull($member);
        $this->assertTrue($member->inGroup($group));

        Member::get()->filter('Email', 'adapter-member-test@example.com')->removeAll();
    }

    /**
     * An empty --member value maps to Command::INVALID the same way an
     * empty --group does — the three-way exit-code mapping applies to the
     * member step too, not just the group step.
     */
    public function testEmptyMemberOptionMapsToCommandInvalid(): void
    {
        $result = $this->runTask(['--group' => 'Test Adapter Empty Member', '--member' => '']);

        $this->assertSame(Command::INVALID, $result);
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
