<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Publish\OwnedLinks;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Serialize\RecordSerializer;
use Dynamic\ContentApi\Verify\OwnedTreeWalker;
use Dynamic\ContentApi\Write\DbTransaction;
use SilverStripe\CMS\Controllers\RootURLController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ValidationException;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Page-domain actions: `POST pages/$ID/convert`
 * (`{"className": "BlockPage", "publish": "none|single|recursive|subtree|owns"}`),
 * the API version of a Page→BlockPage `newClassInstance()` conversion, and
 * `POST pages/$ID/apply-template` (`{"templateId": n, "publish":
 * "none|recursive"}`). Both population-gated: CONTENT_API_POPULATE +
 * environment.
 *
 * Converting the site's home page is refused without `"force": true` — the
 * home page's area relation differs by type (ElementalHomePage) and a wrong
 * conversion takes the front door down.
 */
class PageHandler
{
    use Configurable;
    use Injectable;

    /**
     * The optional dynamic/silverstripe-elemental-templates classes
     * `apply-template` drives. Config rather than hardcoded locals purely so
     * the module's own test suite can point them at a stub (#174) — that
     * package is `suggest`ed, not required, so `class_exists()` is false in
     * every environment these tests run in and this endpoint's publish
     * behavior had never been exercised by any test before. Not a
     * documented extension point; projects have no reason to override it.
     */
    private static string $template_class = 'Dynamic\\ElementalTemplates\\Models\\Template';

    private static string $template_applicator_class = 'Dynamic\\ElementalTemplates\\Service\\TemplateApplicator';

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'policy' => '%$' . PermissionPolicy::class,
        'serializer' => '%$' . RecordSerializer::class,
        'publisher' => '%$' . PublishOrchestrator::class,
        'environmentGate' => '%$' . EnvironmentGate::class,
        'ownedTreeWalker' => '%$' . OwnedTreeWalker::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?PermissionPolicy $policy = null;

    public ?RecordSerializer $serializer = null;

    public ?PublishOrchestrator $publisher = null;

    public ?EnvironmentGate $environmentGate = null;

    public ?OwnedTreeWalker $ownedTreeWalker = null;

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
        $templateClass = (string) static::config()->get('template_class');
        $applicatorClass = (string) static::config()->get('template_applicator_class');

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

        // #119/#168: publishOwnedTree() below can now throw mid-cascade
        // (a descendant class withholding `action`) — a failure that used
        // to be impossible here, since the old direct publishSingle() calls
        // never authorization-checked anything. Without a transaction, that
        // throw would leave the template's draft write persisted with a
        // bare 403 response and no indication the page changed. Wrapped the
        // same way CompositionHandler wraps compose().
        $data = null;

        try {
            DbTransaction::run(function () use (
                $request,
                $templateClass,
                $applicatorClass,
                $templateId,
                $publishMode,
                $context,
                &$data
            ) {
                $data = Versioned::withVersionedMode(function () use (
                    $request,
                    $templateClass,
                    $applicatorClass,
                    $templateId,
                    $publishMode,
                    $context
                ) {
                    return $this->doApplyTemplate(
                        $request,
                        $templateClass,
                        $applicatorClass,
                        $templateId,
                        $publishMode,
                        $context
                    );
                });
            });
        } catch (ApiError $error) {
            throw new ApiError(
                $error->getErrorCode(),
                $error->getMessage() . ' (apply-template rolled back)',
                $error->getDetails(),
                $error->getStatus()
            );
        }

        return $data;
    }

    /**
     * @throws ApiError
     */
    protected function doApplyTemplate(
        HTTPRequest $request,
        string $templateClass,
        string $applicatorClass,
        int $templateId,
        string $publishMode,
        AuthContext $context
    ): array {
        Versioned::set_stage(Versioned::DRAFT);

        $id = (string) $request->param('ID');

        if (!ctype_digit($id)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'apply-template requires a numeric page id.');
        }

        $page = DataObject::get_by_id(SiteTree::class, (int) $id);

        if (!$page) {
            throw new ApiError(ErrorCode::NOT_FOUND, sprintf('No page found with id %d.', (int) $id));
        }

        // The template writes onto the page, so the page class needs
        // `update` as well as canEdit(), as convert() checks (#235).
        $this->policy->authorize($page, 'update', $context->member);

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

        $this->policy->checkRecordAccess($template, 'read', $context->member);
        $this->assertTemplateCreatable($page, $template, $context);

        $result = Injector::inst()->get($applicatorClass)->applyTemplateToRecord($page, $template);

        if (empty($result['success'])) {
            throw new ApiError(
                ErrorCode::VALIDATION_FAILED,
                'Template application failed: ' . ($result['message'] ?? 'unknown error')
            );
        }

        if ($publishMode === 'recursive') {
            $area = $page->hasMethod('ElementalArea') ? $page->ElementalArea() : null;

            // #119/#168: routed through publishOwnedTree() rather than the
            // area's/elements' own publishSingle() calls this used to make
            // directly — those performed no authorization at all.
            //
            // Unlike CompositionService::publishAll(), this $additional is
            // NOT tracking "known written targets" — TemplateApplicator
            // isn't asked what it wrote, so the area/elements are simply
            // re-read from the page after the fact. The area and its
            // top-level elements are redundant with the walk itself
            // (ElementalPageExtension declares $owns = ['ElementalArea'],
            // ElementalArea declares $owns = ['Elements']) and are kept for
            // symmetry with the composition path; the per-element walk below
            // is not redundant, and is the whole point of this block.
            //
            // (publishAll() has it easier: it passes the exact records it
            // just wrote, one flat level deep, because it did the writing.)
            //
            // #174: an element's own children are only $owns-reachable if
            // that element declares $owns itself — BaseElement doesn't, and
            // an element is free to declare $cascade_duplicates without a
            // matching $owns entry. TemplateApplicator creates exactly those
            // records: TemplateElementDuplicator::duplicateElements()
            // duplicates each template element with a bare
            // $element->duplicate(). Anything duplicate() creates but $owns
            // doesn't name used to stay on draft after a "recursive" apply —
            // unpublished and, because nothing reached it, unauthorized —
            // with no error and no signal.
            //
            // walkDuplicates() rather than a generic has_many sweep, and
            // rather than a bare $cascade_duplicates read: it reproduces what
            // duplicate() actually creates, including the $owns fallback the
            // framework substitutes for a Versioned record with an empty
            // $cascade_duplicates, and excluding many_many (link-copied, not
            // cloned). See that method — both corrections are load-bearing,
            // in opposite directions.
            //
            // No stock Dynamic element hits this today: the ones with
            // versioned children list them in $owns as well, and the one
            // cascade-only case (ElementStatCounters => Stats) has an
            // unversioned child. The gap is structural, not hypothetical —
            // nothing stops an element declaring one without the other.
            //
            // Deliberately unchanged: $area->Elements() is every element on
            // the page, not only the ones this template just added, so this
            // reaches pre-existing elements' children too. That breadth is
            // this endpoint's pre-existing behavior and narrowing it would be
            // its own change.
            $additional = [];

            if ($area && $area->exists()) {
                $additional[] = $area;

                foreach ($area->Elements() as $element) {
                    $additional[] = $element;

                    foreach ($this->ownedTreeWalker->walkDuplicates($element) as $entry) {
                        $additional[] = $entry['record'];
                    }
                }
            }

            // No dedupe needed here: publishOwnedTree() already dedupes
            // $additional against the walked $owns set, against itself, and
            // against $root, all on ClassName:ID.
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
    }

    /**
     * Applying a template creates a copy of each template element and of
     * every child `duplicate()` cascades to, so each of those classes needs
     * the `create` verb and canCreate() (#235), the same gate
     * RecordWriter::upsert() applies to any other element creation. Checked
     * once per distinct class, before anything is written.
     *
     * @throws ApiError FORBIDDEN_CLASS | FORBIDDEN_RECORD
     */
    protected function assertTemplateCreatable(DataObject $page, DataObject $template, AuthContext $context): void
    {
        if (!$template->hasMethod('Elements')) {
            return;
        }

        $sourceArea = $template->Elements();

        if (!$sourceArea || !$sourceArea->exists() || !$sourceArea->hasMethod('Elements')) {
            return;
        }

        $targetArea = $page->hasMethod('ElementalArea') ? $page->ElementalArea() : null;
        $elementContext = $targetArea && $targetArea->exists() ? ['ParentID' => (int) $targetArea->ID] : [];
        $checked = [];

        foreach ($sourceArea->Elements() as $element) {
            $records = [[$element, $elementContext]];

            foreach ($this->ownedTreeWalker->walkAllDuplicates($element) as $entry) {
                $records[] = [$entry['record'], []];
            }

            foreach ($records as [$record, $internalFields]) {
                $class = get_class($record);

                if (isset($checked[$class])) {
                    continue;
                }

                $this->policy->checkClassAccess($class, 'create', $context->member);
                $this->policy->checkCreateAccess($class, $context->member, [], $internalFields);
                $checked[$class] = true;
            }
        }
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

        // #238: the class change and the publish land or fail together, the
        // same way applyTemplate() wraps its write — otherwise a publish
        // refused on a descendant leaves the draft converted behind a 403,
        // and a retry answers "unchanged" without publishing.
        $data = null;

        try {
            DbTransaction::run(function () use ($request, $targetClass, $publishMode, $body, $context, &$data) {
                $data = $this->doConvert($request, $targetClass, $publishMode, $body, $context);
            });
        } catch (ApiError $error) {
            throw new ApiError(
                $error->getErrorCode(),
                $error->getMessage() . ' (convert rolled back)',
                $error->getDetails(),
                $error->getStatus()
            );
        }

        return $data;
    }

    /**
     * @throws ApiError
     */
    protected function doConvert(
        HTTPRequest $request,
        string $targetClass,
        string $publishMode,
        array $body,
        AuthContext $context
    ): array {
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

            // #228: changing a page's class is a structural change of its
            // own, not an ordinary field update, so both the current class and
            // the target must declare the bare `convert` verb. Checked once a
            // conversion will actually happen, so a same-class no-op and the
            // home-page refusal answer as before.
            $this->policy->checkClassAccess(get_class($page), 'convert', $context->member);
            $this->policy->checkClassAccess($targetClass, 'convert', $context->member);

            $converted = $page->newClassInstance($targetClass);

            try {
                $converted->write();
            } catch (ValidationException $exception) {
                throw ApiError::fromValidation($exception, 'Page conversion');
            }

            OwnedLinks::singleton()->restampOwnerClass($converted);
            $published = $this->publisher->publish($converted, $publishMode, $context->member);
            $meta = ['operation' => 'converted'];

            if ($published !== null) {
                $meta['published'] = $published;
            }

            return [
                'data' => $this->serializer->serialize($converted),
                'meta' => $meta,
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
