<?php

namespace Dynamic\ContentApi\Tests\Tasks\Support;

use Dynamic\ContentApi\Tasks\Support\ServiceAccountMemberProvisioner;
use Dynamic\ContentApi\Tasks\Support\TaskStatus;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;

/**
 * Business-logic coverage for #124's branch-neutral member provisioner,
 * mirroring `ServiceAccountProvisionerTest`'s own conventions (no fixture
 * file, real DB writes cleaned up by hardcoded-title deletion, assert on
 * {@see TaskStatus} rather than parsing rendered output).
 */
class ServiceAccountMemberProvisionerTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteGroupsTitled('Test Member Group');
        $this->deleteMembersWithEmail('member-provisioner-test@example.com');

        Group::create(['Title' => 'Test Member Group'])->write();
    }

    protected function tearDown(): void
    {
        $this->deleteGroupsTitled('Test Member Group');
        $this->deleteMembersWithEmail('member-provisioner-test@example.com');

        parent::tearDown();
    }

    public function testCreatesAndAttachesANewMember(): void
    {
        $result = $this->provision('member-provisioner-test@example.com');

        $this->assertSame(TaskStatus::Success, $result->status);

        $member = Member::get()->filter('Email', 'member-provisioner-test@example.com')->first();
        $this->assertNotNull($member);
        $this->assertTrue($member->inGroup($this->group()));
    }

    /**
     * This class never sets a password explicitly — confirmed live that
     * `Member::write()` auto-generates a random, unknown one when none is
     * set (framework/stack behavior, not this class's own), which is
     * functionally equivalent for lockout purposes (nobody knows it) but
     * worth pinning so a future stack change that silently drops that
     * default doesn't go unnoticed here.
     */
    public function testCreatedMemberGetsAnUnknownPasswordNotAFixedOrEmptyOne(): void
    {
        $this->provision('member-provisioner-test@example.com');

        $member = Member::get()->filter('Email', 'member-provisioner-test@example.com')->first();
        $this->assertNotSame('', (string) $member->Password);
    }

    public function testIsIdempotent(): void
    {
        $this->provision('member-provisioner-test@example.com');
        $this->provision('member-provisioner-test@example.com');

        $members = Member::get()->filter('Email', 'member-provisioner-test@example.com');
        $this->assertSame(1, $members->count(), 're-running must not create a duplicate member');

        $member = $members->first();
        $groups = $member->Groups()->filter('Title', 'Test Member Group');
        $this->assertSame(1, $groups->count(), 're-running must not add a duplicate group relation');
    }

    /**
     * A pre-existing Member (found by email, not created by this class)
     * must simply be attached, never recreated or have its password/other
     * fields touched.
     */
    public function testAttachesAPreExistingMemberWithoutRecreatingIt(): void
    {
        $existing = Member::create();
        $existing->Email = 'member-provisioner-test@example.com';
        $existing->FirstName = 'Pre Existing';
        $existing->write();
        $existingId = $existing->ID;

        $this->provision('member-provisioner-test@example.com');

        $member = Member::get()->filter('Email', 'member-provisioner-test@example.com')->first();
        $this->assertSame($existingId, $member->ID, 'must reuse the existing record, not create a new one');
        $this->assertSame('Pre Existing', $member->FirstName, 'must not overwrite unrelated fields');
        $this->assertTrue($member->inGroup($this->group()));
    }

    public function testFailsWhenTheGroupDoesNotExist(): void
    {
        $result = ServiceAccountMemberProvisioner::create()
            ->provision('member-provisioner-test@example.com', 'Test Nonexistent Group');

        $this->assertSame(TaskStatus::Failure, $result->status);
        $this->assertNull(Member::get()->filter('Email', 'member-provisioner-test@example.com')->first());
    }

    /**
     * Same "refuse to guess" contract as ServiceAccountProvisioner —
     * pre-existing/legacy data could leave two groups sharing a title, and
     * silently attaching to one would be a guess this class shouldn't make.
     */
    public function testRefusesToGuessWhenMultipleGroupsShareTheTitle(): void
    {
        DB::query(sprintf(
            "INSERT INTO \"Group\" (\"ClassName\", \"Title\") VALUES ('%s', '%s')",
            Group::class,
            'Test Member Group'
        ));

        $result = $this->provision('member-provisioner-test@example.com');

        $this->assertSame(TaskStatus::Failure, $result->status);
        $this->assertStringContainsStringIgnoringCase('refusing to guess', implode("\n", $result->lines));
        $this->assertNull(Member::get()->filter('Email', 'member-provisioner-test@example.com')->first());
    }

    public function testExplicitEmptyEmailIsRejected(): void
    {
        $result = $this->provision('');

        $this->assertSame(TaskStatus::Invalid, $result->status);
    }

    public function testWhitespaceOnlyEmailIsRejected(): void
    {
        $result = $this->provision('   ');

        $this->assertSame(TaskStatus::Invalid, $result->status);
    }

    protected function group(): Group
    {
        return Group::get()->filter('Title', 'Test Member Group')->first();
    }

    protected function provision(string $email)
    {
        return ServiceAccountMemberProvisioner::create()->provision($email, 'Test Member Group');
    }

    protected function deleteGroupsTitled(string $title): void
    {
        foreach (Group::get()->filter('Title', $title) as $group) {
            $group->delete();
        }
    }

    protected function deleteMembersWithEmail(string $email): void
    {
        foreach (Member::get()->filter('Email', $email) as $member) {
            $member->delete();
        }
    }
}
