<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\TokenAuthenticator;
use Dynamic\ContentApi\Control\ContentApiController;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * Endpoints: POST auth/login, POST auth/logout, POST auth/refresh,
 * GET auth/session.
 *
 * Login credentials travel in the POST JSON body only — never query vars.
 */
class AuthHandler
{
    use Injectable;

    private static array $dependencies = [
        'authenticator' => '%$' . TokenAuthenticator::class,
    ];

    public ?TokenAuthenticator $authenticator = null;

    public function handle(HTTPRequest $request, ContentApiController $controller): array
    {
        $action = $request->param('Action');

        return match ($action) {
            'login' => $this->login($request),
            'logout' => $this->logout($request),
            'refresh' => $this->refresh($request),
            'session' => $this->session($request),
            default => throw new ApiError(
                ErrorCode::NOT_FOUND,
                sprintf('Unknown auth action "%s".', $action)
            ),
        };
    }

    private function login(HTTPRequest $request): array
    {
        $this->assertMethod($request, 'POST');
        $body = $this->jsonBody($request);

        $email = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                'Login requires "email" and "password" in the JSON body.'
            );
        }

        $result = $this->authenticator->login($email, $password, $request);

        return [
            'data' => [
                'token' => $result['token'],
                'expires' => $result['expires'],
                'member' => [
                    'id' => (int) $result['member']->ID,
                    'email' => $result['member']->Email,
                ],
            ],
        ];
    }

    private function logout(HTTPRequest $request): array
    {
        $this->assertMethod($request, 'POST');
        $context = $this->authenticator->authenticate($request);

        $this->authenticator->revokeToken($context->member);

        return [
            'data' => ['revoked' => true],
        ];
    }

    private function refresh(HTTPRequest $request): array
    {
        $this->assertMethod($request, 'POST');
        $context = $this->authenticator->authenticate($request);

        $token = $this->authenticator->mintToken($context->member);

        return [
            'data' => [
                'token' => $token,
                'expires' => (int) $context->member->ContentApiTokenExpire,
            ],
        ];
    }

    private function session(HTTPRequest $request): array
    {
        $this->assertMethod($request, 'GET');
        $context = $this->authenticator->authenticate($request);
        Security::setCurrentUser($context->member);

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
        ];
    }

    private function assertMethod(HTTPRequest $request, string $method): void
    {
        if ($request->httpMethod() !== $method) {
            throw new ApiError(
                ErrorCode::METHOD_NOT_ALLOWED,
                sprintf('This endpoint requires %s.', $method)
            );
        }
    }

    private function jsonBody(HTTPRequest $request): array
    {
        $raw = $request->getBody();

        if (!$raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Request body is not valid JSON.');
        }

        return $decoded;
    }
}
