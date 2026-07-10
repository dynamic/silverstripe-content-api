<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiFunctionalTest;
use SilverStripe\Security\Member;

class AuthTest extends ContentApiFunctionalTest
{
    private const PASSWORD = 'ap1-T3st-passw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, 'apiUser');
        $member->changePassword(self::PASSWORD);
    }

    public function testLoginReturnsToken(): void
    {
        $response = $this->apiPost('auth/login', [
            'email' => 'agent@example.com',
            'password' => self::PASSWORD,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->decode($response);

        $this->assertNull($body['error']);
        $this->assertSame(64, strlen($body['data']['token']));
        $this->assertGreaterThan(time(), $body['data']['expires']);
        $this->assertSame('agent@example.com', $body['data']['member']['email']);

        // Plaintext token must not be stored.
        $member = $this->objFromFixture(Member::class, 'apiUser');
        $this->assertNotSame($body['data']['token'], $member->ContentApiTokenHash);
        $this->assertSame(hash('sha256', $body['data']['token']), $member->ContentApiTokenHash);
    }

    public function testLoginRejectsBadCredentials(): void
    {
        $response = $this->apiPost('auth/login', [
            'email' => 'agent@example.com',
            'password' => 'wrong',
        ]);

        $this->assertErrorCode($response, 'UNAUTHENTICATED', 401);
    }

    public function testLoginRejectsMissingBody(): void
    {
        $response = $this->apiPost('auth/login', []);

        $this->assertErrorCode($response, 'PAYLOAD_INVALID', 400);
    }

    public function testLoginRequiresPost(): void
    {
        $response = $this->apiGet('auth/login');

        $this->assertErrorCode($response, 'METHOD_NOT_ALLOWED', 405);
    }

    public function testSessionReportsMemberAndPermissions(): void
    {
        $token = $this->mintTokenFor('apiUser');

        $response = $this->apiGet('auth/session', $token);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('agent@example.com', $body['data']['email']);
        $this->assertContains('CONTENT_API_ACCESS', $body['data']['permissions']);
        $this->assertNotContains('CONTENT_API_POPULATE', $body['data']['permissions']);
    }

    public function testRequestWithoutTokenIsRejected(): void
    {
        $response = $this->apiGet('auth/session');

        $this->assertErrorCode($response, 'UNAUTHENTICATED', 401);
    }

    public function testRequestWithInvalidTokenIsRejected(): void
    {
        $response = $this->apiGet('auth/session', str_repeat('0', 64));

        $this->assertErrorCode($response, 'UNAUTHENTICATED', 401);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->mintTokenFor('apiUser');

        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, 'apiUser');
        $member->ContentApiTokenExpire = time() - 60;
        $member->write();

        $response = $this->apiGet('auth/session', $token);

        $this->assertErrorCode($response, 'TOKEN_EXPIRED', 401);
    }

    public function testLogoutRevokesToken(): void
    {
        $token = $this->mintTokenFor('apiUser');

        $response = $this->apiPost('auth/logout', [], $token);
        $this->assertSame(200, $response->getStatusCode());

        $response = $this->apiGet('auth/session', $token);
        $this->assertErrorCode($response, 'UNAUTHENTICATED', 401);
    }

    public function testRefreshRotatesToken(): void
    {
        $token = $this->mintTokenFor('apiUser');

        $response = $this->apiPost('auth/refresh', [], $token);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());

        $newToken = $body['data']['token'];
        $this->assertNotSame($token, $newToken);

        // Old token is dead, new token works.
        $this->assertErrorCode($this->apiGet('auth/session', $token), 'UNAUTHENTICATED', 401);
        $this->assertSame(200, $this->apiGet('auth/session', $newToken)->getStatusCode());
    }

    public function testUnknownAuthActionIs404(): void
    {
        $response = $this->apiPost('auth/bogus', []);

        $this->assertErrorCode($response, 'NOT_FOUND', 404);
    }
}
