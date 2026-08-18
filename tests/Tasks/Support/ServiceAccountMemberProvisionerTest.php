<?php

namespace Dynamic\ContentApi\Tests\Tasks\Support;

use Dynamic\ContentApi\Tasks\Support\ServiceAccountMemberProvisioner;
use Dynamic\ContentApi\Tasks\Support\TaskStatus;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;
use SilverStripe\Security\Permission;

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
     * #124 review follow-up (critical, caught before merge): a first draft
     * relied on `Member::write()` to "auto-generate a random, unknown"
     * password when none is set. That claim was wrong — an unset `Password`
     * on a new record encrypts the *empty string*, a known credential, not
     * an unknown one (`Member::onBeforeWrite()` re-encrypts on
     * `!$this->ID`, and `$this->Password` is `null` -> `''` at that point).
     * A prior version of this exact test only asserted
     * `Password !== ''` — the stored bcrypt hash of `''` is a 60-char
     * string, so that assertion passed identically whether the password
     * was genuinely random or a hash of nothing, proving nothing.
     *
     * The real, security-relevant assertion: an empty password must not
     * authenticate. This fails against the pre-fix behavior and passes
     * against the fix (an explicit `generateRandomPassword()`).
     */
    public function testCreatedMemberHasARealPasswordAnEmptyPasswordCannotAuthenticateWith(): void
    {
        $this->provision('member-provisioner-test@example.com');

        $member = Member::get()->filter('Email', 'member-provisioner-test@example.com')->first();

        $result = (new MemberAuthenticator())->checkPassword($member, '');

        $this->assertFalse(
            $result->isValid(),
            'an empty password must never authenticate as this member'
        );
    }

    public function testIsIdempotent(): void
    {
        $this->provision('member-provisioner-test@example.com');
        $this->provision('member-provisioner-test@example.com');

        $members = Member::get()->filter('Email', 'member-provisioner-test@example.com');
        $this->assertSame(1, $members->count(), 're-running must not create a duplicate member');

        $member = $members->first();

        // #124 review follow-up (confidence 85): Member::Groups() is a
        // Member_GroupSet, whose linkJoinTable() is a deliberate no-op —
        // it filters "Group"."ID" IN (...), never joins Group_Members. A
        // duplicate row in the join table therefore cannot produce a
        // duplicate row in this list; the original version of this
        // assertion (`$member->Groups()->filter(...)->count()`) passed
        // regardless of whether the join table itself was actually
        // deduplicated. Query the join table directly instead.
        $joinRowCount = (int) DB::query(sprintf(
            'SELECT COUNT(*) FROM "Group_Members" WHERE "MemberID" = %d AND "GroupID" = %d',
            (int) $member->ID,
            (int) $this->group()->ID
        ))->value();
        $this->assertSame(1, $joinRowCount, 're-running must not add a duplicate Group_Members row');
    }

    /**
     * #124 review follow-up (confidence 80): true by construction (the
     * existing-member branch never calls write()), but nothing asserted it
     * — exactly the kind of guarantee a future refactor that adds a
     * write() to that branch would silently break.
     */
    public function testRerunningDoesNotTouchAnExistingMembersPassword(): void
    {
        $this->provision('member-provisioner-test@example.com');

        $member = Member::get()->filter('Email', 'member-provisioner-test@example.com')->first();
        $passwordBefore = $member->Password;
        $saltBefore = $member->Salt;

        $this->provision('member-provisioner-test@example.com');

        $member = Member::get()->filter('Email', 'member-provisioner-test@example.com')->first();
        $this->assertSame($passwordBefore, $member->Password);
        $this->assertSame($saltBefore, $member->Salt);
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

    public function testResultConfirmsNoCmsAccessForAnOrdinaryGroup(): void
    {
        $result = $this->provision('member-provisioner-test@example.com');

        $this->assertStringContainsString(
            'no CMS-admin access',
            implode("\n", $result->lines)
        );
        $this->assertStringNotContainsString('WARNING', implode("\n", $result->lines));
    }

    /**
     * #124 review follow-up (confidence 88): the original "no CMS-admin
     * access" line was printed unconditionally, true only about the
     * permission codes this task itself grants — not about whatever the
     * *resolved group* already carried. `group=Administrators` (or any
     * group sharing a title with, or descended from, a privileged one) is
     * a real, reachable input. This proves the group's own permission
     * codes are actually checked, not just the ones this task adds.
     */
    public function testWarnsWhenTheGroupItselfGrantsAdmin(): void
    {
        Permission::grant((int) $this->group()->ID, 'ADMIN');

        $result = $this->provision('member-provisioner-test@example.com');

        $this->assertSame(TaskStatus::Success, $result->status);
        $this->assertStringContainsString('WARNING', implode("\n", $result->lines));
        $this->assertStringContainsString('ADMIN', implode("\n", $result->lines));
    }

    /**
     * Group membership grants inherit upward through the group tree
     * (`Member_GroupSet::foreignIDFilter()` walks ancestors) — attaching to
     * a child of a privileged group inherits that privilege even though
     * the child group itself grants nothing. The check must walk ancestors,
     * not just the resolved group's own permission rows.
     */
    public function testWarnsWhenAnAncestorGroupGrantsCmsAccess(): void
    {
        $parent = Group::create(['Title' => 'Test Member Group Parent']);
        $parent->write();
        Permission::grant((int) $parent->ID, 'CMS_ACCESS_LeftAndMain');

        $child = $this->group();
        $child->ParentID = $parent->ID;
        $child->write();

        $result = $this->provision('member-provisioner-test@example.com');

        $this->assertStringContainsString('WARNING', implode("\n", $result->lines));

        $parent->delete();
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
