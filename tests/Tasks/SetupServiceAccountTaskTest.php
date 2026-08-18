<?php

namespace Dynamic\ContentApi\Tests\Tasks;

use Dynamic\ContentApi\Security\ContentApiPermissions;
use Dynamic\ContentApi\Tasks\SetupServiceAccountTask;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Adapter-only coverage for this branch's legacy `run($request)` entry
 * point: request-var parsing and that {@see TaskResult} lines reach stdout.
 * The provisioning business logic (group creation, idempotence, healing,
 * duplicate-title refusal, empty/whitespace rejection) is exercised once,
 * branch-neutrally, in `Tasks/Support/ServiceAccountProvisionerTest` — see
 * #65/#96. This branch keeps two things `ServiceAccountProvisioner` itself
 * has no opinion on, because they're adapter-level request parsing: the
 * default group title when `group` is omitted entirely, and the
 * presence-based (not truthy-based) `populate` semantics.
 */
class SetupServiceAccountTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

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
     * Presence-based, not value-based — matches the old VALUE_NONE
     * `--populate` flag's semantics (there was no way to pass "false" to
     * it either). A plain PHP truthy check on the raw request var would
     * otherwise treat `populate=false` or `populate=no` as granting it,
     * since both non-empty strings are truthy — the opposite of what an
     * operator typing `populate=false` would expect.
     */
    public function testPopulateVarIsPresenceBasedNotTruthyBased(): void
    {
        $this->runTask(['group' => 'Test Adapter Populate Presence', 'populate' => 'false']);

        $group = Group::get()->filter('Title', 'Test Adapter Populate Presence')->first();
        $this->assertNotNull($group);
        $this->assertTrue(
            Permission::get()
                ->filter(['GroupID' => (int) $group->ID, 'Code' => ContentApiPermissions::POPULATE])
                ->exists(),
            'populate=false is still present — the flag has no way to express "off", only "absent"'
        );
    }

    public function testResultLinesReachStdout(): void
    {
        $output = $this->runTask(['group' => 'Test Adapter Output']);

        $this->assertStringContainsString('Test Adapter Output', $output);
        $this->assertStringContainsString(
            'Mint a token: sake dev/tasks/MintContentApiToken email=<member-email>',
            $output
        );
    }

    /**
     * The "Mint a token" hint is adapter-owned (gated on `TaskStatus::Success`
     * in `SetupServiceAccountTask::run()` itself, not inside `TaskResult::$lines`),
     * so it has no coverage from `ServiceAccountProvisionerTest` — confirm it's
     * actually suppressed on a rejected request, not just present on success.
     */
    public function testMintTokenHintIsSuppressedOnFailure(): void
    {
        $output = $this->runTask(['group' => '']);

        $this->assertStringNotContainsString('Mint a token', $output);
    }

    /**
     * ServiceAccountProvisioner's own message text uses branch `2`'s SS6
     * --flag syntax (`--group`) since it's shared between both branches —
     * this adapter translates it to this branch's `key=value` syntax before
     * it reaches the operator, so an SS5 user is never told to pass a flag
     * that this branch's `run($request)` entry point doesn't accept.
     */
    public function testEmptyGroupRejectionUsesThisBranchsSyntax(): void
    {
        $output = $this->runTask(['group' => '']);

        $this->assertStringContainsString('group cannot be empty.', $output);
        $this->assertStringNotContainsString('--group', $output);
    }

    /**
     * #124: omitting `member` entirely keeps the pre-existing behavior —
     * only the group-provisioning "mint a token" hint, no member step run
     * at all. Explicit opt-in, not run by default.
     */
    public function testOmittingMemberSkipsMemberProvisioningEntirely(): void
    {
        Member::get()->filter('Email', 'adapter-member-test@example.com')->removeAll();

        $this->runTask(['group' => 'Test Adapter No Member']);

        $this->assertNull(Member::get()->filter('Email', 'adapter-member-test@example.com')->first());
    }

    /**
     * #124: passing `member=` also find-or-creates that Member and attaches
     * it to the just-provisioned group, and the final "mint a token" hint
     * uses the real email rather than the placeholder.
     */
    public function testMemberVarAlsoProvisionsAndAttachesTheMember(): void
    {
        Member::get()->filter('Email', 'adapter-member-test@example.com')->removeAll();

        $output = $this->runTask([
            'group' => 'Test Adapter With Member',
            'member' => 'adapter-member-test@example.com',
        ]);

        $group = Group::get()->filter('Title', 'Test Adapter With Member')->first();
        $member = Member::get()->filter('Email', 'adapter-member-test@example.com')->first();

        $this->assertNotNull($member);
        $this->assertTrue($member->inGroup($group));
        $this->assertStringContainsString(
            'Mint a token: sake dev/tasks/MintContentApiToken email=adapter-member-test@example.com',
            $output
        );

        Member::get()->filter('Email', 'adapter-member-test@example.com')->removeAll();
    }

    /**
     * ServiceAccountMemberProvisioner's own message text uses branch `2`'s
     * SS6 --flag syntax (`--member`) — this adapter must translate it the
     * same way it already does for `ServiceAccountProvisioner`'s `--group`.
     */
    public function testEmptyMemberRejectionUsesThisBranchsSyntax(): void
    {
        $output = $this->runTask(['group' => 'Test Adapter Empty Member', 'member' => '']);

        $this->assertStringContainsString('member cannot be empty.', $output);
        $this->assertStringNotContainsString('--member', $output);
    }

    protected function runTask(array $vars): string
    {
        $task = SetupServiceAccountTask::create();
        $request = new HTTPRequest('GET', '/', $vars);

        ob_start();
        $task->run($request);

        return ob_get_clean();
    }
}
