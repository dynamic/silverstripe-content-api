<?php

namespace Dynamic\ContentApi\Tasks\Support;

use Colymba\RESTfulAPI\Authenticators\TokenAuthenticator;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;

/**
 * Mint (or rotate) a content API token for a member without a password
 * round-trip — the standard way to provision agent/service accounts. Uses
 * colymba/silverstripe-restfulapi's TokenAuthenticator, so the token works on
 * both the /api and /content-api/v1 surfaces. Branch-neutral: both branch
 * `2`'s SS6 `MintApiTokenTask` adapter (`execute(InputInterface, PolyOutput): int`)
 * and branch `1`'s legacy `MintApiTokenTask` adapter (`run($request): void`) call
 * this directly rather than duplicating the business logic — see #65/#96.
 */
class ApiTokenMinter
{
    use Injectable;

    public function mint(string $email): TaskResult
    {
        $email = trim($email);

        if ($email === '') {
            return new TaskResult(TaskStatus::Invalid, ['Missing required option: --email']);
        }

        $member = Member::get()->filter('Email', $email)->first();

        if (!$member) {
            return new TaskResult(TaskStatus::Failure, ["No member found with email {$email}"]);
        }

        $auth = Injector::inst()->create(TokenAuthenticator::class);
        $auth->resetToken((int) $member->ID);
        $token = $auth->getToken((int) $member->ID);
        $expires = date('c', (int) Member::get()->byID($member->ID)->ApiTokenExpire);

        return new TaskResult(TaskStatus::Success, [
            "Token minted for {$email} (member #{$member->ID}), expires {$expires}:",
            '',
            "  {$token}",
            '',
            'Note: colymba/silverstripe-restfulapi stores tokens in plaintext on the',
            'Member record — anyone with CMS access to Members can read them.',
        ]);
    }
}
