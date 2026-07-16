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

        foreach ([ContentApiPermissions::ACCESS, 'VIEW_DRAFT_CONTENT'] as $code) {
            $grants = Permission::get()->filter(['GroupID' => (int) $group->ID, 'Code' => $code]);
            $this->assertSame(1, $grants->count(), "re-running must not duplicate the {$code} grant");
        }
    }

    /**
     * Regression for #42 code review: the realistic operational sequence of
     * provisioning an account plain, then later deciding it also needs
     * population access. Nothing before this asserted --populate actually
     * takes effect on a group that already exists.
     */
    public function testRerunningWithPopulateAddsTheGrantToAnExistingGroup(): void
    {
        $this->deleteGroupsTitled('Test Upgrade To Populate');

        $this->runTask(['--group' => 'Test Upgrade To Populate']);
        $group = Group::get()->filter('Title', 'Test Upgrade To Populate')->first();
        $this->assertNotGranted($group, ContentApiPermissions::POPULATE);

        $this->runTask(['--group' => 'Test Upgrade To Populate', '--populate' => true]);

        $this->assertGranted($group, ContentApiPermissions::POPULATE);
    }

    /**
     * Regression for #42 code review: the task has no revoke path — it must
     * not be mistaken for one. Omitting --populate on a later run must not
     * remove a grant a prior run already made.
     */
    public function testRerunningWithoutPopulateDoesNotRevokeAnExistingGrant(): void
    {
        $this->deleteGroupsTitled('Test Keep Populate');

        $this->runTask(['--group' => 'Test Keep Populate', '--populate' => true]);
        $group = Group::get()->filter('Title', 'Test Keep Populate')->first();
        $this->assertGranted($group, ContentApiPermissions::POPULATE);

        $this->runTask(['--group' => 'Test Keep Populate']);

        $this->assertGranted($group, ContentApiPermissions::POPULATE);
    }

    /**
     * Regression for #42 code review: the "self-healing" half of the
     * idempotency contract — a group that already exists but is missing a
     * grant it should have (e.g. manually revoked out of band) must have
     * that grant restored, not left missing because the group already existed.
     */
    public function testHealsAGroupMissingAGrant(): void
    {
        $this->deleteGroupsTitled('Test Partial Grants');

        $group = Group::create();
        $group->Title = 'Test Partial Grants';
        $group->write();
        Permission::grant((int) $group->ID, ContentApiPermissions::ACCESS);
        $this->assertNotGranted($group, 'VIEW_DRAFT_CONTENT');

        $this->runTask(['--group' => 'Test Partial Grants']);

        $this->assertGranted($group, ContentApiPermissions::ACCESS);
        $this->assertGranted($group, 'VIEW_DRAFT_CONTENT');
    }

    /**
     * Regression for #42 code review: although Group::write() itself
     * enforces a unique Title (confirmed empirically — this test bypasses
     * that validation to construct the scenario), pre-existing/legacy data
     * (an import, a migration, a direct DB write) could still leave two
     * groups sharing a title. The task must refuse to guess which one to
     * grant content API permissions to in that case — silently picking one
     * could escalate access on an unrelated, pre-existing group.
     */
    public function testRefusesToGuessWhenMultipleGroupsShareTheTitle(): void
    {
        $this->deleteGroupsTitled('Test Ambiguous Title');

        foreach ([1, 2] as $_) {
            $duplicate = Group::create();
            $duplicate->Title = 'Test Ambiguous Title';
            $duplicate->write(false, false, false, false, true);
        }

        $result = $this->runTask(['--group' => 'Test Ambiguous Title']);

        $this->assertSame(Command::FAILURE, $result);
        $this->assertSame(
            0,
            Permission::get()->filter([
                'GroupID' => Group::get()->filter('Title', 'Test Ambiguous Title')->column('ID'),
                'Code' => ContentApiPermissions::ACCESS,
            ])->count(),
            'must not grant anything when it cannot determine which group is intended'
        );
    }

    public function testExplicitEmptyGroupIsRejected(): void
    {
        $result = $this->runTask(['--group' => '']);

        $this->assertSame(Command::INVALID, $result);
    }

    public function testWhitespaceOnlyGroupIsRejected(): void
    {
        $result = $this->runTask(['--group' => '   ']);

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

    /**
     * Defensive cleanup for the tests below that write() a Group directly
     * by a hardcoded title rather than through a fixture — guards against a
     * leftover row from an interrupted prior run (SapphireTest's per-test
     * reset isn't guaranteed to catch a row written with skipValidation the
     * same way it does fixture-backed/normally-validated ones).
     */
    protected function deleteGroupsTitled(string $title): void
    {
        foreach (Group::get()->filter('Title', $title) as $group) {
            $group->delete();
        }
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
