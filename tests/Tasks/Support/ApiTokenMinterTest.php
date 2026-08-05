<?php

namespace Dynamic\ContentApi\Tests\Tasks\Support;

use Dynamic\ContentApi\Tasks\Support\ApiTokenMinter;
use Dynamic\ContentApi\Tasks\Support\TaskStatus;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * Branch-neutral coverage for the token minter (#65) — asserts on
 * {@see TaskStatus} rather than parsing rendered output.
 */
class ApiTokenMinterTest extends SapphireTest
{
    // See ServiceAccountProvisionerTest — no fixture file to imply DB use,
    // but this writes a real Member, so it must opt in explicitly.
    protected $usesDatabase = true;

    public function testMissingEmailIsInvalid(): void
    {
        $result = ApiTokenMinter::create()->mint('');

        $this->assertSame(TaskStatus::Invalid, $result->status);
    }

    public function testWhitespaceOnlyEmailIsInvalid(): void
    {
        $result = ApiTokenMinter::create()->mint('   ');

        $this->assertSame(TaskStatus::Invalid, $result->status);
    }

    public function testUnknownEmailFails(): void
    {
        $result = ApiTokenMinter::create()->mint('no-such-member@example.com');

        $this->assertSame(TaskStatus::Failure, $result->status);
        $this->assertStringContainsString('No member found', implode("\n", $result->lines));
    }

    public function testMintsATokenForAnExistingMember(): void
    {
        $member = Member::create(['Email' => 'token-minter-test@example.com']);
        $member->write();

        $result = ApiTokenMinter::create()->mint('token-minter-test@example.com');

        $this->assertSame(TaskStatus::Success, $result->status);
        $output = implode("\n", $result->lines);
        $this->assertStringContainsString('Token minted for token-minter-test@example.com', $output);

        $member = Member::get()->byID($member->ID);
        $this->assertNotEmpty($member->ApiToken, 'a real token must have been persisted to the member');
    }
}
