<?php

namespace Dynamic\ContentApi\Auth;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;

/**
 * Token authentication for the content API.
 *
 * Flow adapted from colymba/silverstripe-restfulapi's TokenAuthenticator
 * (BSD-3-Clause, (c) Thierry Francois @colymba) with hardening:
 * tokens are stored as sha256 hashes, login is POST-body only, the query-var
 * fallback is off by default, and authentication never touches the session
 * IdentityStore (the controller scopes the current user to the request).
 */
class TokenAuthenticator
{
    use Configurable;
    use Injectable;

    /**
     * Token life in seconds from mint/refresh.
     */
    private static int $token_life = 604800;

    /**
     * When true, a valid authenticated request pushes the expiry forward
     * (throttled to at most one write per 10% of token_life).
     */
    private static bool $auto_refresh = true;

    private static string $header_name = 'X-Silverstripe-Apitoken';

    private static bool $allow_query_var = false;

    private static string $query_var = 'token';

    /**
     * Verify email + password and mint a fresh token.
     *
     * @return array{token: string, expires: int, member: Member}
     * @throws ApiError on authentication failure
     */
    public function login(string $email, string $password, HTTPRequest $request): array
    {
        $member = Injector::inst()->get(MemberAuthenticator::class)->authenticate(
            [
                'Email' => $email,
                'Password' => $password,
            ],
            $request
        );

        if (!$member) {
            throw new ApiError(ErrorCode::UNAUTHENTICATED, 'Authentication failed.');
        }

        $token = $this->mintToken($member);

        return [
            'token' => $token,
            'expires' => (int) $member->ContentApiTokenExpire,
            'member' => $member,
        ];
    }

    /**
     * Generate a new token for a member, store its hash and return the
     * plaintext token (the only time it is available).
     */
    public function mintToken(Member $member): string
    {
        $token = bin2hex(random_bytes(32));

        $member->ContentApiTokenHash = hash('sha256', $token);
        $member->ContentApiTokenExpire = time() + (int) static::config()->get('token_life');
        $member->write();

        return $token;
    }

    /**
     * Invalidate a member's current token.
     */
    public function revokeToken(Member $member): void
    {
        $member->ContentApiTokenHash = null;
        $member->ContentApiTokenExpire = 0;
        $member->write();
    }

    /**
     * Authenticate an API request via its token header.
     *
     * @throws ApiError UNAUTHENTICATED | TOKEN_EXPIRED
     */
    public function authenticate(HTTPRequest $request): AuthContext
    {
        $token = $this->extractToken($request);

        if (!$token) {
            throw new ApiError(ErrorCode::UNAUTHENTICATED, 'No API token provided.');
        }

        $member = Member::get()
            ->filter('ContentApiTokenHash', hash('sha256', $token))
            ->first();

        if (!$member) {
            throw new ApiError(ErrorCode::UNAUTHENTICATED, 'Token invalid.');
        }

        $expires = (int) $member->ContentApiTokenExpire;

        if ($expires <= time()) {
            throw new ApiError(ErrorCode::TOKEN_EXPIRED, 'Token expired.');
        }

        $expires = $this->maybeRefresh($member, $expires);

        return new AuthContext($member, $expires);
    }

    private function extractToken(HTTPRequest $request): ?string
    {
        $token = $request->getHeader((string) static::config()->get('header_name'));

        if (!$token && static::config()->get('allow_query_var')) {
            $token = $request->requestVar((string) static::config()->get('query_var'));
        }

        return $token ?: null;
    }

    /**
     * Push the expiry forward on activity, writing at most once per 10% of
     * token_life so routine requests don't each incur a Member write.
     */
    private function maybeRefresh(Member $member, int $expires): int
    {
        if (!static::config()->get('auto_refresh')) {
            return $expires;
        }

        $life = (int) static::config()->get('token_life');
        $newExpiry = time() + $life;

        if (($newExpiry - $expires) < (int) ($life * 0.1)) {
            return $expires;
        }

        $member->ContentApiTokenExpire = $newExpiry;
        $member->write();

        return $newExpiry;
    }
}
