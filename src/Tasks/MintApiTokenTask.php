<?php

namespace Dynamic\ContentApi\Tasks;

use Colymba\RESTfulAPI\Authenticators\TokenAuthenticator;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Security\Member;

/**
 * Mint (or rotate) a content API token for a member without a password
 * round-trip — the standard way to provision agent/service accounts. Uses
 * colymba/silverstripe-restfulapi's TokenAuthenticator, so the token works on
 * both the /api and /content-api/v1 surfaces.
 *
 * Usage: `sake tasks:MintContentApiToken email=agent@example.com`
 */
class MintApiTokenTask extends BuildTask
{
    private static string $segment = 'MintContentApiToken';

    protected $title = 'Mint content API token';

    protected $description = 'Generates a new content API token for the member with the given '
        . 'email address, replacing any existing token. The plaintext token is shown once; colymba '
        . 'stores it in plaintext on the Member record.';

    public function run($request)
    {
        $email = (string) $request->getVar('email');

        if ($email === '') {
            echo "Missing required option: email\n";

            return;
        }

        $member = Member::get()->filter('Email', $email)->first();

        if (!$member) {
            echo "No member found with email {$email}\n";

            return;
        }

        $auth = Injector::inst()->create(TokenAuthenticator::class);
        $auth->resetToken((int) $member->ID);
        $token = $auth->getToken((int) $member->ID);
        $expires = date('c', (int) Member::get()->byID($member->ID)->ApiTokenExpire);

        echo "Token minted for {$email} (member #{$member->ID}), expires {$expires}:\n";
        echo "\n";
        echo "  {$token}\n";
        echo "\n";
        echo "Note: colymba/silverstripe-restfulapi stores tokens in plaintext on the\n";
        echo "Member record — anyone with CMS access to Members can read them.\n";
    }
}
