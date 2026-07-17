<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Security\ContentApiPermissions;
use Dynamic\ContentApi\Tasks\SetupServiceAccountTask;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;

class SetupServiceAccountTaskTest extends SapphireTest
{
    public function testCreatesGroupWithBaseGrants(): void
    {
        $this->runTask(['group' => 'Test Service Accounts']);

        $group = Group::get()->filter('Title', 'Test Service Accounts')->first();
        $this->assertNotNull($group);
        $this->assertGranted($group, ContentApiPermissions::ACCESS);
        $this->assertGranted($group, 'VIEW_DRAFT_CONTENT');
        $this->assertNotGranted($group, ContentApiPermissions::POPULATE);
    }

    public function testPopulateFlagAlsoGrantsPopulate(): void
    {
        $this->runTask(['group' => 'Test Populate Accounts', 'populate' => '1']);

        $group = Group::get()->filter('Title', 'Test Populate Accounts')->first();
        $this->assertGranted($group, ContentApiPermissions::ACCESS);
        $this->assertGranted($group, 'VIEW_DRAFT_CONTENT');
        $this->assertGranted($group, ContentApiPermissions::POPULATE);
    }

    public function testIsIdempotent(): void
    {
        $this->runTask(['group' => 'Test Idempotent']);
        $this->runTask(['group' => 'Test Idempotent']);

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
     * population access. Nothing before this asserted populate=1 actually
     * takes effect on a group that already exists.
     */
    public function testRerunningWithPopulateAddsTheGrantToAnExistingGroup(): void
    {
        $this->deleteGroupsTitled('Test Upgrade To Populate');

        $this->runTask(['group' => 'Test Upgrade To Populate']);
        $group = Group::get()->filter('Title', 'Test Upgrade To Populate')->first();
        $this->assertNotGranted($group, ContentApiPermissions::POPULATE);

        $this->runTask(['group' => 'Test Upgrade To Populate', 'populate' => '1']);

        $this->assertGranted($group, ContentApiPermissions::POPULATE);
    }

    /**
     * Regression for #42 code review: the task has no revoke path — it must
     * not be mistaken for one. Omitting populate on a later run must not
     * remove a grant a prior run already made.
     */
    public function testRerunningWithoutPopulateDoesNotRevokeAnExistingGrant(): void
    {
        $this->deleteGroupsTitled('Test Keep Populate');

        $this->runTask(['group' => 'Test Keep Populate', 'populate' => '1']);
        $group = Group::get()->filter('Title', 'Test Keep Populate')->first();
        $this->assertGranted($group, ContentApiPermissions::POPULATE);

        $this->runTask(['group' => 'Test Keep Populate']);

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

        $this->runTask(['group' => 'Test Partial Grants']);

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

        // Group::write() enforces a unique Title via validate(), which has no
        // skip-validation write() flag on this framework line — insert the
        // second row directly to construct the pre-existing-duplicate-data
        // scenario the task must defend against.
        Group::create(['Title' => 'Test Ambiguous Title'])->write();
        DB::query(sprintf(
            "INSERT INTO \"Group\" (\"ClassName\", \"Title\") VALUES ('%s', '%s')",
            Group::class,
            'Test Ambiguous Title'
        ));

        $output = $this->runTask(['group' => 'Test Ambiguous Title']);

        $this->assertStringContainsString(
            'refusing to guess',
            $output,
            'must report why it declined, not just silently do nothing'
        );
        $this->assertSame(
            0,
            Permission::get()->filter([
                'GroupID' => Group::get()->filter('Title', 'Test Ambiguous Title')->column('ID'),
                'Code' => ContentApiPermissions::ACCESS,
            ])->count(),
            'must not grant anything when it cannot determine which group is intended'
        );
    }

    /**
     * Regression for #52 code review: assert on the task's own rejection
     * message, not just the absence of a group titled "" — an assertion on
     * absence alone can't distinguish "correctly rejected" from "silently
     * fell back to the default title" (a real risk if the null-check on
     * $rawGroup were ever loosened to an empty() check).
     */
    public function testExplicitEmptyGroupIsRejected(): void
    {
        $output = $this->runTask(['group' => '']);

        $this->assertStringContainsString('cannot be empty', $output);
        $this->assertNull(Group::get()->filter('Title', '')->first());
        $this->assertNull(Group::get()->filter('Title', 'Content API Service Accounts')->first());
    }

    public function testWhitespaceOnlyGroupIsRejected(): void
    {
        $output = $this->runTask(['group' => '   ']);

        $this->assertStringContainsString('cannot be empty', $output);
        $this->assertNull(Group::get()->filter('Title', '   ')->first());
        $this->assertNull(Group::get()->filter('Title', 'Content API Service Accounts')->first());
    }

    /**
     * Regression for #42 code review: omitting group entirely (vs passing
     * it explicitly empty) falls back to the default title rather than
     * being rejected — confirms the two are distinguished, not conflated.
     */
    public function testOmittingGroupFallsBackToTheDefaultTitle(): void
    {
        $this->runTask([]);

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

    protected function runTask(array $vars): string
    {
        $task = SetupServiceAccountTask::create();
        $request = new HTTPRequest('GET', '/', $vars);

        ob_start();
        $task->run($request);

        return ob_get_clean();
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
