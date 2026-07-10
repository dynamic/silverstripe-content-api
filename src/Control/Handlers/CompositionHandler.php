<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Composition\CompositionService;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Security\PermissionPolicy;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * `POST compositions/page` — one request, one page's full composition.
 *
 * Atomic: DB writes roll back on any failure (asset store binaries are the
 * documented exception — they are content-addressed and idempotent, so a
 * retried request converges). Requires elemental, CONTENT_API_POPULATE and
 * an allowed environment.
 */
class CompositionHandler
{
    use Injectable;

    private static array $dependencies = [
        'composition' => '%$' . CompositionService::class,
        'policy' => '%$' . PermissionPolicy::class,
        'environmentGate' => '%$' . EnvironmentGate::class,
    ];

    public ?CompositionService $composition = null;

    public ?PermissionPolicy $policy = null;

    public ?EnvironmentGate $environmentGate = null;

    public function composePage(HTTPRequest $request, AuthContext $context): array
    {
        if (!class_exists('DNADesign\\Elemental\\Models\\ElementalArea')) {
            throw new ApiError(
                ErrorCode::FEATURE_UNAVAILABLE,
                'Page compositions require dnadesign/silverstripe-elemental.'
            );
        }

        $this->policy->checkPopulateAccess($context->member);
        $this->environmentGate->checkPopulationAllowed();

        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Request body is not valid JSON.');
        }

        $data = null;

        try {
            DB::get_conn()->withTransaction(function () use ($body, $context, &$data) {
                $data = Versioned::withVersionedMode(function () use ($body, $context) {
                    Versioned::set_stage(Versioned::DRAFT);

                    return $this->composition->compose($body, $context->member);
                });
            }, null, false, true);
        } catch (ApiError $error) {
            throw new ApiError(
                $error->getErrorCode(),
                $error->getMessage() . ' (composition rolled back)',
                $error->getDetails(),
                $error->getStatus()
            );
        }

        return [
            'data' => $data,
            'meta' => ['operation' => 'composed'],
        ];
    }
}
