<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use SilverStripe\Security\Member;

/**
 * Authentication is colymba/silverstripe-restfulapi's TokenAuthenticator;
 * these tests cover our adapter (error mapping, session introspection) and
 * the cross-surface contract with colymba's own auth endpoints.
 */
class AuthTest extends ContentApiTestCase
{
    private const PASSWORD = 'ap1-T3st-passw0rd!';

    public function testSessionReportsMemberAndPermissions(): void
    {
        $token = $this->mintTokenFor('apiUser');

        $response = $this->apiGet('auth/session', $token);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('agent@example.com', $body['data']['email']);
        $this->assertContains('CONTENT_API_ACCESS', $body['data']['permissions']);
        $this->assertNotContains('CONTENT_API_POPULATE', $body['data']['permissions']);
        $this->assertGreaterThan(time(), $body['data']['expires']);
        $this->assertSame('colymba/silverstripe-restfulapi', $body['meta']['tokenProvider']);
    }

    public function testRequestWithoutTokenIsRejected(): void
    {
        $response = $this->apiGet('auth/session');

        $this->assertErrorCode($response, 'UNAUTHENTICATED', 401);
    }

    public function testRequestWithInvalidTokenIsRejected(): void
    {
        $response = $this->apiGet('auth/session', 'not-a-real-token');

        $this->assertErrorCode($response, 'UNAUTHENTICATED', 401);
    }

    public function testExpiredTokenMapsToTokenExpired(): void
    {
        $token = $this->mintTokenFor('apiUser');

        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, 'apiUser');
        // Our surface enforces STRICT expiry — a merely-past ApiTokenExpire is
        // expired, not colymba's now - tokenLife grace window.
        $member->ApiTokenExpire = time() - 60;
        $member->write();

        $response = $this->apiGet('auth/session', $token);

        $this->assertErrorCode($response, 'TOKEN_EXPIRED', 401);
    }

    public function testTokenInColymbaGraceWindowIsStillRejected(): void
    {
        $token = $this->mintTokenFor('apiUser');

        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, 'apiUser');
        // now - 60 is INSIDE colymba's validity window (ApiTokenExpire >
        // now - tokenLife, i.e. > now - 7 days), so colymba's authenticate()
        // would accept it — but it is past the advertised expiry, and our
        // strict adapter rejects it.
        $graceTimestamp = time() - 60;
        $member->ApiTokenExpire = $graceTimestamp;
        $member->write();

        $this->assertGreaterThan(
            $this->expiredTokenTimestamp(),
            $graceTimestamp,
            'sanity: now-60 is inside colymba grace window'
        );
        $this->assertErrorCode($this->apiGet('auth/session', $token), 'TOKEN_EXPIRED', 401);
    }

    public function testOurLoginEndpointIsGone(): void
    {
        $response = $this->apiPost('auth/login', [
            'email' => 'agent@example.com',
            'password' => AuthTest::PASSWORD,
        ]);

        $this->assertSame(404, $response->getStatusCode(), 'login moved to colymba api/auth/login');
    }

    public function testColymbaLoginTokenWorksOnOurSurface(): void
    {
        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, 'apiUser');
        $member->changePassword(AuthTest::PASSWORD);

        // Colymba login uses email/pwd REQUEST VARS (not a JSON body).
        $response = $this->get(
            'api/auth/login?email=' . urlencode('agent@example.com')
            . '&pwd=' . urlencode(AuthTest::PASSWORD)
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $login = json_decode((string) $response->getBody(), true);
        $this->assertTrue($login['result'], (string) $response->getBody());
        $this->assertNotEmpty($login['token']);

        // The colymba-issued token authenticates on content-api/v1.
        $session = $this->apiGet('auth/session', $login['token']);
        $this->assertSame(200, $session->getStatusCode(), (string) $session->getBody());
        $this->assertSame(
            'agent@example.com',
            $this->decode($session)['data']['email']
        );
    }

    public function testQueryVarTokenIsRejectedOnOurSurface(): void
    {
        // Header-only: our adapter does NOT honour colymba's ?token= query-var
        // fallback (tokens in URLs leak into logs; combined with colymba's
        // session login it would mint a CMS session from a shared link).
        $token = $this->mintTokenFor('apiUser');

        $response = $this->get('content-api/v1/auth/session?token=' . urlencode($token));

        $this->assertErrorCode($response, 'UNAUTHENTICATED', 401);
    }

    public function testAuthenticatingDoesNotEstablishASession(): void
    {
        // getOwner() (not authenticate()) resolves the member with no
        // IdentityStore login — the API stays stateless.
        $token = $this->mintTokenFor('apiUser');

        $response = $this->apiGet('auth/session', $token);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringNotContainsStringIgnoringCase(
            'set-cookie',
            implode("\n", array_keys($response->getHeaders())),
            'authenticated API responses must not set a session cookie'
        );
    }
}
