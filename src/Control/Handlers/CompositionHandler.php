<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Composition\CompositionService;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Write\DbTransaction;
use SilverStripe\Assets\File;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Versioned\Versioned;

/**
 * `POST compositions/page` — one request, one page's full composition.
 *
 * Atomic: DB writes roll back on any failure. Assets are written draft-only
 * inside the transaction and published after it commits, so a failure never
 * deletes an overwritten file's published bytes (#238); new bytes a failed
 * run wrote stay in the asset store unreferenced. A post-commit asset publish
 * that fails is reported per asset rather than failing the committed
 * composition. Requires elemental, CONTENT_API_POPULATE and an allowed
 * environment.
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

        // #130: dryRun is a `POST batch` feature only. Reject rather than
        // silently ignore — a caller who set "dryRun": true reasonably
        // believes nothing will be written; silently proceeding with a
        // real composition would violate that belief. Same convention as
        // #102's dryRun/liveOnly rejection on non-subtree publish modes.
        if (!empty($body['dryRun'])) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, '"dryRun" is only supported on "POST batch".');
        }

        $data = null;

        try {
            DbTransaction::run(function () use ($body, $context, &$data) {
                $data = Versioned::withVersionedMode(function () use ($body, $context) {
                    Versioned::set_stage(Versioned::DRAFT);

                    return $this->composition->compose($body, $context->member);
                });
            });
        } catch (ApiError $error) {
            throw new ApiError(
                $error->getErrorCode(),
                $error->getMessage() . ' (composition rolled back)',
                $error->getDetails(),
                $error->getStatus()
            );
        }

        // Only once everything above has committed (#238). A failure here
        // can't undo the committed composition, so it is reported on the
        // asset it concerns instead of turning the response into a 500.
        $failed = Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::DRAFT);

            return $this->composition->publishDeferredAssets();
        });

        if ($failed !== []) {
            foreach ($data['assets'] ?? [] as $index => $asset) {
                if (isset($failed[$asset['id']])) {
                    $data['assets'][$index]['warnings'][] = [
                        'code' => ErrorCode::SERVER_ERROR->value,
                        'message' => 'The asset was saved but could not be published: ' . $failed[$asset['id']],
                    ];
                }
            }

            if (isset($data['published'])) {
                $data['published'] = array_values(array_filter(
                    $data['published'],
                    fn (array $entry) => !isset($failed[$entry['id']]) || !is_a($entry['className'], File::class, true)
                ));
            }
        }

        return [
            'data' => $data,
            'meta' => ['operation' => 'composed'],
        ];
    }
}
