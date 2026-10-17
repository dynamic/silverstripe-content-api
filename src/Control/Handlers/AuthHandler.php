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
 * Token lifecycle (login/minting) is colymba/silverstripe-restfulapi's job:
 * `POST api/auth/login` (email/pwd request vars), or the MintContentApiToken
 * task for service accounts. colymba's `api/auth/logout` is off by default
 * (ColymbaAuthHardening, #237).
 */
class AuthHandler
{
    use Injectable;

    public function session(HTTPRequest $request, AuthContext $context): array
    {
        // VIEW_DRAFT_CONTENT is a core silverstripe/versioned permission, not
        // a content-api one (see ContentApiPermissions) — but docs/en/04
        // stresses a service account needs it to read back its own draft-only
        // writes, so an agent needs to be able to introspect it here too.
        $codes = ['CONTENT_API_ACCESS', 'CONTENT_API_POPULATE', 'CONTENT_API_SCHEMA', 'VIEW_DRAFT_CONTENT'];
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
