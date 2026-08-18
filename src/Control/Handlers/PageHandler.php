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
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ValidationException;
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
            'apply-template' => $this->applyTemplate($request, $context),
            default => throw new ApiError(
                ErrorCode::NOT_FOUND,
                sprintf('Unknown page action "%s".', $action)
            ),
        };
    }

    /**
     * `POST pages/$ID/apply-template` `{"templateId": 3, "publish": "none|recursive"}`
     * — duplicates a Dynamic\ElementalTemplates template's elements onto the
     * page via TemplateApplicator. Optional integration.
     */
    protected function applyTemplate(HTTPRequest $request, AuthContext $context): array
    {
        $templateClass = 'Dynamic\\ElementalTemplates\\Models\\Template';
        $applicatorClass = 'Dynamic\\ElementalTemplates\\Service\\TemplateApplicator';

        if (!class_exists($templateClass) || !class_exists($applicatorClass)) {
            throw new ApiError(
                ErrorCode::FEATURE_UNAVAILABLE,
                'Applying templates requires dynamic/silverstripe-elemental-templates.'
            );
        }

        $this->policy->checkPopulateAccess($context->member);
        $this->environmentGate->checkPopulationAllowed();

        $body = $this->jsonBody($request);
        $templateId = (int) ($body['templateId'] ?? 0);

        if ($templateId < 1) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'apply-template requires "templateId".');
        }

        $publishMode = (string) ($body['publish'] ?? 'none');

        if (!in_array($publishMode, ['none', 'recursive'], true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                'apply-template publish mode must be "none" or "recursive".'
            );
        }

        return Versioned::withVersionedMode(function () use (
            $request,
            $templateClass,
            $applicatorClass,
            $templateId,
            $publishMode,
            $context
        ) {
            Versioned::set_stage(Versioned::DRAFT);

            $id = (string) $request->param('ID');

            if (!ctype_digit($id)) {
                throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'apply-template requires a numeric page id.');
            }

            $page = DataObject::get_by_id(SiteTree::class, (int) $id);

            if (!$page) {
                throw new ApiError(ErrorCode::NOT_FOUND, sprintf('No page found with id %d.', (int) $id));
            }

            $this->policy->checkRecordAccess($page, 'update', $context->member);

            // #114: same gap as convert() above, applied to this action —
            // 'update' was checked, but 'action' never was before the page
            // itself gets published below.
            if ($publishMode === 'recursive') {
                $this->policy->checkClassAccess(get_class($page), 'action', $context->member);
            }

            $template = DataObject::get_by_id($templateClass, $templateId);

            if (!$template) {
                throw new ApiError(
                    ErrorCode::NOT_FOUND,
                    sprintf('No template found with id %d.', $templateId)
                );
            }

            $result = Injector::inst()->get($applicatorClass)->applyTemplateToRecord($page, $template);

            if (empty($result['success'])) {
                throw new ApiError(
                    ErrorCode::VALIDATION_FAILED,
                    'Template application failed: ' . ($result['message'] ?? 'unknown error')
                );
            }

            if ($publishMode === 'recursive') {
                $area = $page->hasMethod('ElementalArea') ? $page->ElementalArea() : null;

                // #119/#168: routed through publishOwnedTree() rather than
                // the area's/elements' own publishSingle() calls this used
                // to make directly — those performed no authorization at
                // all. The area and its elements are passed as $additional
                // (known written targets), the same reasoning as
                // CompositionService::publishAll() — BaseElement declares
                // no $owns, so element children aren't walk-reachable on
                // their own.
                $additional = ($area && $area->exists())
                    ? array_merge([$area], iterator_to_array($area->Elements()))
                    : [];

                $this->publisher->publishOwnedTree($page, $context->member, $additional);
            }

            return [
                'data' => [
                    'page' => $this->serializer->serialize($page),
                    'templateId' => $templateId,
                    'message' => $result['message'] ?? 'Template applied.',
                ],
                'meta' => ['operation' => 'template-applied'],
            ];
        });
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

        // #114: only the *pre-conversion* record's 'update' verb was ever
        // checked (below, inside the closure) — the *target* class's own
        // verbs were never checked at all before publishing the converted
        // instance as root. A populate-scoped member could convert a page
        // into a class whose api_access denies everything, then (with a
        // publish mode other than "none") subtree-publish under it as
        // root. Checked here, class-level, mirroring RecordWriter::write()'s
        // equivalent gate for the payload-driven write path.
        $this->policy->checkClassAccess($targetClass, 'update', $context->member);

        if ($publishMode !== 'none') {
            $this->policy->checkClassAccess($targetClass, 'action', $context->member);
        }

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
                throw ApiError::fromValidation($exception, 'Page conversion');
            }

            $this->publisher->publish($converted, $publishMode, $context->member);

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

        // #130: dryRun is a `POST batch` feature only — both page actions
        // funnel through here, so checked once. Reject rather than
        // silently ignore, same convention as #102's dryRun/liveOnly
        // rejection on non-subtree publish modes: a caller who set
        // "dryRun": true reasonably believes nothing will be written.
        if (!empty($decoded['dryRun'])) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, '"dryRun" is only supported on "POST batch".');
        }

        return $decoded;
    }
}
