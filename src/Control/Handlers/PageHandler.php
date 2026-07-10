<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Serialize\RecordSerializer;
use SilverStripe\CMS\Controllers\RootURLController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Page-domain actions. M2 ships `POST pages/$ID/convert`
 * (`{"className": "BlockPage", "publish": "none|single|recursive"}`) — the
 * API version of AttachAmdAreasTask's Page→BlockPage `newClassInstance()`
 * conversion. Population-gated: CONTENT_API_POPULATE + environment.
 *
 * Converting the site's home page is refused without `"force": true` — the
 * home page's area relation differs by type (ElementalHomePage) and a wrong
 * conversion takes the front door down.
 */
class PageHandler
{
    use Injectable;

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'policy' => '%$' . PermissionPolicy::class,
        'serializer' => '%$' . RecordSerializer::class,
        'publisher' => '%$' . PublishOrchestrator::class,
        'environmentGate' => '%$' . EnvironmentGate::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?PermissionPolicy $policy = null;

    public ?RecordSerializer $serializer = null;

    public ?PublishOrchestrator $publisher = null;

    public ?EnvironmentGate $environmentGate = null;

    public function handle(HTTPRequest $request, AuthContext $context): array
    {
        $action = (string) $request->param('PageAction');

        return match ($action) {
            'convert' => $this->convert($request, $context),
            default => throw new ApiError(
                ErrorCode::NOT_FOUND,
                sprintf('Unknown page action "%s".', $action)
            ),
        };
    }

    protected function convert(HTTPRequest $request, AuthContext $context): array
    {
        if (!class_exists(SiteTree::class)) {
            throw new ApiError(ErrorCode::FEATURE_UNAVAILABLE, 'silverstripe/cms is not installed.');
        }

        $this->policy->checkPopulateAccess($context->member);
        $this->environmentGate->checkPopulationAllowed();

        $body = $this->jsonBody($request);
        $targetRef = (string) ($body['className'] ?? '');

        if ($targetRef === '') {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Convert requires "className".');
        }

        $targetClass = $this->registry->resolve($targetRef);

        if (!is_a($targetClass, SiteTree::class, true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('"%s" is not a page type.', $targetRef)
            );
        }

        $publishMode = (string) ($body['publish'] ?? 'none');
        $this->publisher->assertValidMode($publishMode);

        return Versioned::withVersionedMode(function () use ($request, $targetClass, $publishMode, $body, $context) {
            Versioned::set_stage(Versioned::DRAFT);

            $id = (string) $request->param('ID');

            if (!ctype_digit($id)) {
                throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'Page conversion requires a numeric page id.');
            }

            $page = DataObject::get_by_id(SiteTree::class, (int) $id);

            if (!$page) {
                throw new ApiError(ErrorCode::NOT_FOUND, sprintf('No page found with id %d.', (int) $id));
            }

            $this->policy->checkRecordAccess($page, 'update', $context->member);

            $homeSegment = RootURLController::get_homepage_link();

            if ($page->URLSegment === $homeSegment && (int) $page->ParentID === 0 && empty($body['force'])) {
                throw new ApiError(
                    ErrorCode::HOMEPAGE_CONVERSION_FORBIDDEN,
                    'Refusing to convert the site home page — its elemental area relation differs by page '
                    . 'type. Pass "force": true only if you know the target type handles the home page.'
                );
            }

            if ($page->ClassName === $targetClass) {
                return [
                    'data' => $this->serializer->serialize($page),
                    'meta' => ['operation' => 'unchanged'],
                ];
            }

            $converted = $page->newClassInstance($targetClass);

            try {
                $converted->write();
            } catch (ValidationException $exception) {
                throw new ApiError(
                    ErrorCode::VALIDATION_FAILED,
                    'Page conversion failed validation: ' . $exception->getMessage()
                );
            }

            $this->publisher->publish($converted, $publishMode);

            return [
                'data' => $this->serializer->serialize($converted),
                'meta' => ['operation' => 'converted'],
            ];
        });
    }

    protected function jsonBody(HTTPRequest $request): array
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
