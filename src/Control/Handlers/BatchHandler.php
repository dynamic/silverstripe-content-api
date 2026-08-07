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
        // #130: both gates still apply to a dry run — validate-only still
        // authorizes and resolves everything a real run would, and that
        // alone leaks schema/permission information a caller shouldn't get
        // for free just by adding "dryRun": true.
        $this->policy->checkPopulateAccess($context->member);
        $this->environmentGate->checkPopulationAllowed();

        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Request body is not valid JSON.');
        }

        $data = $this->processor->process($body, $context->member);

        if (empty($body['dryRun'])) {
            return ['data' => $data];
        }

        // A dry-run response carries a distinct meta.operation the same
        // way #102's subtree-publish dry run does — a caller inspecting
        // only `data.results`/`data.summary` shouldn't be able to mistake
        // this for a real run's envelope; `meta` is the tell.
        return [
            'data' => $data,
            'meta' => ['operation' => 'batchDryRun', 'atomic' => !empty($body['atomic'])],
        ];
    }
}
