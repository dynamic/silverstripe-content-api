<?php

namespace Dynamic\ContentApi\Tests\Tasks\Support;

use Dynamic\ContentApi\Security\ContentApiPermissions;
use Dynamic\ContentApi\Tasks\Support\ServiceAccountProvisioner;
use Dynamic\ContentApi\Tasks\Support\TaskStatus;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;

/**
 * Business-logic coverage for the branch-neutral provisioner (#65/#96) —
 * asserts on {@see TaskStatus}, a real enum, rather than parsing rendered
 * output. Byte-identical to branch `1`'s copy of this test, since both
 * branches' adapters now call the same `ServiceAccountProvisioner`; each
 * branch's own `SetupServiceAccountTaskTest` only needs to cover its thin
 * adapter (input parsing, output rendering, exit-code mapping).
 */
class ServiceAccountProvisionerTest extends SapphireTest
{
    // No fixture file, so SapphireTest's DB-needed detection (testNeedsDB())
    // won't infer it from one — writes Group/Permission rows via a real
    // Group::write(), so this must opt in explicitly or the test runs
    // against the live dev DB with no per-test transaction/rollback.
    protected $usesDatabase = true;

    public function testCreatesGroupWithBaseGrants(): void
    {
        $result = $this->provision('Test Service Accounts');

        $this->assertSame(TaskStatus::Success, $result->status);

        $group = Group::get()->filter('Title', 'Test Service Accounts')->first();
        $this->assertNotNull($group);
        $this->assertGranted($group, ContentApiPermissions::ACCESS);
        $this->assertGranted($group, 'VIEW_DRAFT_CONTENT');
        $this->assertNotGranted($group, ContentApiPermissions::POPULATE);
    }

    public function testPopulateFlagAlsoGrantsPopulate(): void
    {
        $this->provision('Test Populate Accounts', true);

        $group = Group::get()->filter('Title', 'Test Populate Accounts')->first();
        $this->assertGranted($group, ContentApiPermissions::ACCESS);
        $this->assertGranted($group, 'VIEW_DRAFT_CONTENT');
        $this->assertGranted($group, ContentApiPermissions::POPULATE);
    }

    public function testIsIdempotent(): void
    {
        $this->provision('Test Idempotent');
        $this->provision('Test Idempotent');

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
     * population access. Nothing before this asserted the populate flag
     * actually takes effect on a group that already exists.
     */
    public function testRerunningWithPopulateAddsTheGrantToAnExistingGroup(): void
    {
        $this->deleteGroupsTitled('Test Upgrade To Populate');

        $this->provision('Test Upgrade To Populate');
        $group = Group::get()->filter('Title', 'Test Upgrade To Populate')->first();
        $this->assertNotGranted($group, ContentApiPermissions::POPULATE);

        $this->provision('Test Upgrade To Populate', true);

        $this->assertGranted($group, ContentApiPermissions::POPULATE);
    }

    /**
     * Regression for #42 code review: the provisioner has no revoke path —
     * it must not be mistaken for one. Omitting the populate flag on a
     * later run must not remove a grant a prior run already made.
     */
    public function testRerunningWithoutPopulateDoesNotRevokeAnExistingGrant(): void
    {
        $this->deleteGroupsTitled('Test Keep Populate');

        $this->provision('Test Keep Populate', true);
        $group = Group::get()->filter('Title', 'Test Keep Populate')->first();
        $this->assertGranted($group, ContentApiPermissions::POPULATE);

        $this->provision('Test Keep Populate');

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

        $this->provision('Test Partial Grants');

        $this->assertGranted($group, ContentApiPermissions::ACCESS);
        $this->assertGranted($group, 'VIEW_DRAFT_CONTENT');
    }

    /**
     * Regression for #42 code review: although Group::write() itself
     * enforces a unique Title, pre-existing/legacy data (an import, a
     * migration, a direct DB write) could still leave two groups sharing a
     * title. The provisioner must refuse to guess which one to grant
     * content API permissions to in that case — silently picking one could
     * escalate access on an unrelated, pre-existing group.
     */
    public function testRefusesToGuessWhenMultipleGroupsShareTheTitle(): void
    {
        $this->deleteGroupsTitled('Test Ambiguous Title');

        Group::create(['Title' => 'Test Ambiguous Title'])->write();
        // Group::write() enforces a unique Title via validate(). Branch `2`'s
        // (this branch's) write() does have a skipValidation flag (see
        // SetupServiceAccountTaskTest's equivalent test), but branch `1`'s
        // doesn't — insert the second row directly so this test stays
        // byte-identical across both branches, constructing the
        // pre-existing-duplicate-data scenario the provisioner must defend
        // against.
        DB::query(sprintf(
            "INSERT INTO \"Group\" (\"ClassName\", \"Title\") VALUES ('%s', '%s')",
            Group::class,
            'Test Ambiguous Title'
        ));

        $result = $this->provision('Test Ambiguous Title');

        $this->assertSame(TaskStatus::Failure, $result->status);
        $this->assertStringContainsStringIgnoringCase('refusing to guess', implode("\n", $result->lines));
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
        $result = $this->provision('');

        $this->assertSame(TaskStatus::Invalid, $result->status);
        $this->assertNull(Group::get()->filter('Title', 'Content API Service Accounts')->first());
    }

    public function testWhitespaceOnlyGroupIsRejected(): void
    {
        $result = $this->provision('   ');

        $this->assertSame(TaskStatus::Invalid, $result->status);
        $this->assertNull(Group::get()->filter('Title', 'Content API Service Accounts')->first());
    }

    /**
     * Defensive cleanup for the tests above that write() a Group directly by
     * a hardcoded title rather than through a fixture — guards against a
     * leftover row from an interrupted prior run.
     */
    protected function deleteGroupsTitled(string $title): void
    {
        foreach (Group::get()->filter('Title', $title) as $group) {
            $group->delete();
        }
    }

    protected function provision(string $groupTitle, bool $populate = false)
    {
        return ServiceAccountProvisioner::create()->provision($groupTitle, $populate);
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
