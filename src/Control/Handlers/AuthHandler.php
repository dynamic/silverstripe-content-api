<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Permission;

/**
 * `GET auth/session` — token introspection: member, held content-API
 * permission codes, expiry.
 *
 * Token lifecycle (login/logout/minting) is colymba/silverstripe-restfulapi's
 * job: `POST api/auth/login` (email/pwd request vars), `api/auth/logout`,
 * or the MintContentApiToken task for service accounts.
 */
class AuthHandler
{
    use Injectable;

    public function session(HTTPRequest $request, AuthContext $context): array
    {
        $codes = ['CONTENT_API_ACCESS', 'CONTENT_API_POPULATE', 'CONTENT_API_SCHEMA'];
        $held = array_values(array_filter(
            $codes,
            fn (string $code) => (bool) Permission::checkMember($context->member, $code)
        ));

        return [
            'data' => [
                'memberId' => (int) $context->member->ID,
                'email' => $context->member->Email,
                'permissions' => $held,
                'expires' => $context->expires,
            ],
            'meta' => [
                'tokenProvider' => 'colymba/silverstripe-restfulapi',
            ],
        ];
    }
}
