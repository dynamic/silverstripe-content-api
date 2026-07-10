<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Batch\BatchProcessor;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Security\PermissionPolicy;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;

/**
 * `POST batch` — ordered write operations with per-op results.
 * Requires CONTENT_API_POPULATE and an allowed environment.
 */
class BatchHandler
{
    use Injectable;

    private static array $dependencies = [
        'processor' => '%$' . BatchProcessor::class,
        'policy' => '%$' . PermissionPolicy::class,
        'environmentGate' => '%$' . EnvironmentGate::class,
    ];

    public ?BatchProcessor $processor = null;

    public ?PermissionPolicy $policy = null;

    public ?EnvironmentGate $environmentGate = null;

    public function handle(HTTPRequest $request, AuthContext $context): array
    {
        $this->policy->checkPopulateAccess($context->member);
        $this->environmentGate->checkPopulationAllowed();

        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Request body is not valid JSON.');
        }

        return [
            'data' => $this->processor->process($body, $context->member),
        ];
    }
}
