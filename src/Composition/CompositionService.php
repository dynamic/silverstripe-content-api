<?php

namespace Dynamic\ContentApi\Composition;

use Dynamic\ContentApi\Assets\AssetService;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Publish\PublishOrchestrator;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Serialize\RecordSerializer;
use Dynamic\ContentApi\Write\RecordWriter;
use Dynamic\ContentApi\Write\WriteApplicator;
use SilverStripe\CMS\Controllers\RootURLController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ValidationException;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Executes a full page composition in one atomic request: page match/create/
 * convert, elemental area attach, ordered element upserts (with children and
 * relations), asset-backed `$ref` aliases, opt-in prune, and a single
 * explicit publish pass.
 *
 * This is the API replacement for the Populate-fixtures + attach-task
 * pipeline. Design invariants carried over from that workflow's scar tissue:
 * - the page is only ever sparsely updated (no populate-style field-map copy)
 * - elements upsert globally by external id (FixtureIdentifier semantics)
 * - array order dictates Sort unless a fields.Sort is explicit
 * - prune only ever touches externally-identified ("managed") records unless
 *   scope "all" is explicitly requested
 * - publish is explicit per request and publishes each written record
 *   individually (a page publishRecursive does NOT cascade to elements)
 */
class CompositionService
{
    use Injectable;

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'writer' => '%$' . RecordWriter::class,
        'applicator' => '%$' . WriteApplicator::class,
        'assets' => '%$' . AssetService::class,
        'externalIds' => '%$' . ExternalIdResolver::class,
        'serializer' => '%$' . RecordSerializer::class,
        'policy' => '%$' . PermissionPolicy::class,
        'publisher' => '%$' . PublishOrchestrator::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?RecordWriter $writer = null;

    public ?WriteApplicator $applicator = null;

    public ?AssetService $assets = null;

    public ?ExternalIdResolver $externalIds = null;

    public ?RecordSerializer $serializer = null;

    public ?PermissionPolicy $policy = null;

    public ?PublishOrchestrator $publisher = null;

    /**
     * @var array<string, DataObject> resolved $ref aliases for this run
     */
    protected array $refs = [];

    public function compose(array $payload, Member $member): array
    {
        $this->refs = [];

        $pageSpec = (array) ($payload['page'] ?? []);
        $publishMode = (string) ($payload['publish'] ?? 'none');

        if (!in_array($publishMode, PublishOrchestrator::COMPOSITION_MODES, true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf(
                    'Composition publish mode "%s" must be one of: %s. "single" applies per-record '
                        . '(batch ops), not to a whole composition.',
                    $publishMode,
                    implode(', ', PublishOrchestrator::COMPOSITION_MODES)
                )
            );
        }

        // 1. Page
        [$page, $pageOperation] = $this->resolvePage($pageSpec, $member, $publishMode);

        // #114: publishAll() below calls $page->publishRecursive() directly
        // — it doesn't go through PublishOrchestrator (which itself never
        // authorizes the root; see its own docblock) or RecordWriter::write()
        // (the page's own field write below never carries a "publish" key,
        // so that class's #114 check never fires for it either). Without
        // this, a class granting update/create but withholding action could
        // still have its page published via a composition, the same gap
        // #114 closed for a payload write's own "publish" key and for
        // convertPage(). get_class($page) is deliberately read AFTER
        // resolvePage() (which already ran convertPage()'s own equivalent
        // check on the target class, if convertTo was used) — checking the
        // page's final class here is idempotent with that check, not a
        // conflicting second decision.
        if ($publishMode === 'recursive') {
            $this->policy->checkClassAccess(get_class($page), 'action', $member);
        }

        $pageWarnings = [];

        if (!empty($pageSpec['fields'])) {
            $result = $this->writer->update($page, ['fields' => $pageSpec['fields']], $member);
            $pageWarnings = $result['warnings'];

            if ($pageOperation === 'matched') {
                $pageOperation = 'updated';
            }
        } else {
            $this->policy->checkRecordAccess($page, 'update', $member);
        }

        // 2. Area
        //
        // The MCP tool schema documents `areaRelation` as a top-level
        // request key (sibling to `page`/`elements`/`publish`), but this
        // service only ever read it from inside `page` — a request built
        // against the documented (top-level) shape silently composed
        // against the DEFAULT area instead of the one actually requested,
        // with nothing in the response to say the two differed. Confirmed
        // live on a HomePage-style class with both a generic `ElementalArea`
        // and its real `ElementalHomePage` relation: the write landed in
        // the unused area, `prune` then happily pruned it, and the page's
        // real content never changed (#192). Both locations are accepted
        // now; `page.areaRelation` wins if both are given (it's the shape
        // the backend has always actually read), and a mismatch is
        // reported as a warning rather than resolved silently.
        $topLevelAreaRelation = isset($payload['areaRelation']) ? (string) $payload['areaRelation'] : null;
        $nestedAreaRelation = isset($pageSpec['areaRelation']) ? (string) $pageSpec['areaRelation'] : null;
        $areaRelation = $nestedAreaRelation ?? $topLevelAreaRelation ?? 'ElementalArea';

        if (
            $topLevelAreaRelation !== null
            && $nestedAreaRelation !== null
            && $topLevelAreaRelation !== $nestedAreaRelation
        ) {
            $pageWarnings[] = [
                'code' => ErrorCode::PAYLOAD_INVALID->value,
                'message' => sprintf(
                    'Both top-level "areaRelation" ("%s") and "page.areaRelation" ("%s") were given — '
                        . '"page.areaRelation" was used.',
                    $topLevelAreaRelation,
                    $nestedAreaRelation
                ),
                'field' => 'areaRelation',
            ];
        }

        [$area, $areaCreated] = $this->resolveArea($page, $areaRelation);
        $elementsRelation = (string) ($pageSpec['elementsRelation'] ?? 'Elements');

        // 3. Assets
        $assetResults = [];

        foreach (array_values((array) ($payload['assets'] ?? [])) as $index => $entry) {
            $assetResults[] = $this->processAsset((array) $entry, $index);
        }

        // 4. Elements (deferred-retry queue so $refs to later entries resolve)
        $elementEntries = array_values((array) ($payload['elements'] ?? []));
        $elementResults = $this->processElements($elementEntries, $area, $member);

        // 5. Prune
        $pruneSpec = (array) ($payload['prune'] ?? []);
        $pruned = !empty($pruneSpec['enabled'])
            ? $this->prune($area, $elementEntries, (string) ($pruneSpec['scope'] ?? 'managed'), $elementsRelation)
            : [];

        // 6. Publish
        if ($publishMode === 'recursive') {
            $this->publishAll($page, $area, $elementResults, $member);
        }

        $response = [
            'page' => $this->serializer->serialize($page) + ['operation' => $pageOperation],
            'area' => [
                'id' => (int) $area->ID,
                'created' => $areaCreated,
            ],
            'elements' => array_map(function (array $result) {
                $result['children'] = array_map(
                    fn (array $child) => array_diff_key($child, ['record' => null]),
                    $result['children']
                );

                return array_diff_key($result, ['record' => null]);
            }, $elementResults),
        ];

        if ($pageWarnings !== []) {
            $response['page']['warnings'] = $pageWarnings;
        }

        if ($assetResults !== []) {
            $response['assets'] = $assetResults;
        }

        if (!empty($pruneSpec['enabled'])) {
            $response['pruned'] = $pruned;
        }

        return $response;
    }

    /**
     * @return array{0: SiteTree, 1: string} page + operation performed
     */
    protected function resolvePage(array $pageSpec, Member $member, string $publishMode = 'none'): array
    {
        $match = (array) ($pageSpec['match'] ?? []);

        if ($match === []) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                'Composition requires page.match ({"id"}, {"urlSegment"} or {"externalId"}).'
            );
        }

        $page = $this->findPage($match);
        $operation = 'matched';

        if (!$page) {
            $create = (array) ($pageSpec['createIfMissing'] ?? []);

            if ($create === []) {
                throw new ApiError(
                    ErrorCode::NOT_FOUND,
                    'No page matched — pass page.createIfMissing to create one.'
                );
            }

            $page = $this->createPage($match, $create, $member);
            $operation = 'created';
        }

        if (!empty($pageSpec['convertTo'])) {
            $converted = $this->convertPage(
                $page,
                (string) $pageSpec['convertTo'],
                !empty($pageSpec['force']),
                $member,
                $publishMode
            );

            if ($converted) {
                $page = $converted;
                $operation = $operation === 'created' ? 'created' : 'converted';
            }
        }

        return [$page, $operation];
    }

    protected function findPage(array $match): ?SiteTree
    {
        if (isset($match['id'])) {
            return SiteTree::get()->byID((int) $match['id']);
        }

        if (isset($match['urlSegment'])) {
            $pages = SiteTree::get()->filter('URLSegment', (string) $match['urlSegment'])->limit(2)->toArray();

            if (count($pages) > 1) {
                throw new ApiError(
                    ErrorCode::MULTIPLE_MATCHES,
                    sprintf(
                        'URLSegment "%s" matches more than one page — match by id instead.',
                        $match['urlSegment']
                    )
                );
            }

            return $pages[0] ?? null;
        }

        if (isset($match['externalId'])) {
            $this->externalIds->assertSupported(SiteTree::class);

            /** @var SiteTree|null $page */
            $page = $this->externalIds->tryFind(SiteTree::class, (string) $match['externalId']);

            return $page;
        }

        throw new ApiError(
            ErrorCode::PAYLOAD_INVALID,
            'page.match supports "id", "urlSegment" or "externalId".'
        );
    }

    protected function createPage(array $match, array $create, Member $member): SiteTree
    {
        $className = SiteTree::class;

        if (!empty($create['className'])) {
            $className = $this->registry->resolve((string) $create['className']);

            if (!is_a($className, SiteTree::class, true)) {
                throw new ApiError(
                    ErrorCode::PAYLOAD_INVALID,
                    sprintf('createIfMissing.className "%s" is not a page type.', $create['className'])
                );
            }
        }

        $this->policy->checkCreateAccess($className, $member, $create);

        /** @var SiteTree $page */
        $page = Injector::inst()->create($className);
        $page->Title = (string) ($create['title'] ?? 'Untitled');
        $page->ParentID = (int) ($create['parentId'] ?? 0);

        if (isset($match['urlSegment'])) {
            $page->URLSegment = (string) $match['urlSegment'];
        }

        if (isset($match['externalId'])) {
            $this->externalIds->assertSupported($className);
            $page->setField($this->externalIds->fieldName(), (string) $match['externalId']);
        }

        try {
            $page->write();
        } catch (ValidationException $exception) {
            throw ApiError::fromValidation($exception);
        }

        return $page;
    }

    protected function convertPage(
        SiteTree $page,
        string $targetRef,
        bool $force,
        Member $member,
        string $publishMode = 'none'
    ): ?SiteTree {
        $targetClass = $this->registry->resolve($targetRef);

        if (!is_a($targetClass, SiteTree::class, true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('convertTo "%s" is not a page type.', $targetRef)
            );
        }

        if ($page->ClassName === $targetClass) {
            return null;
        }

        $homeSegment = RootURLController::get_homepage_link();

        if ($page->URLSegment === $homeSegment && (int) $page->ParentID === 0 && !$force) {
            throw new ApiError(
                ErrorCode::HOMEPAGE_CONVERSION_FORBIDDEN,
                'Refusing to convert the site home page without "force": true.'
            );
        }

        $this->policy->checkRecordAccess($page, 'update', $member);

        // #114: same gap as PageHandler::convert() — only the
        // *pre-conversion* record's 'update' verb was ever checked, never
        // the *target* class's own verbs, before the converted instance is
        // written and (with publishMode "recursive") published as root via
        // publishAll() below.
        $this->policy->checkClassAccess($targetClass, 'update', $member);

        if ($publishMode !== 'none') {
            $this->policy->checkClassAccess($targetClass, 'action', $member);
        }

        /** @var SiteTree $converted */
        $converted = $page->newClassInstance($targetClass);
        $converted->write();

        return $converted;
    }

    /**
     * @return array{0: DataObject, 1: bool} area + whether it was created
     */
    protected function resolveArea(SiteTree $page, string $relationName): array
    {
        $areaClass = DataObject::getSchema()->hasOneComponent(get_class($page), $relationName);

        if (!$areaClass) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf(
                    'Page type %s has no "%s" relation — is elemental configured for it? '
                    . '(HomePage typically uses "ElementalHomePage".)',
                    get_class($page),
                    $relationName
                )
            );
        }

        $areaId = (int) $page->getField($relationName . 'ID');

        if ($areaId > 0) {
            $area = DataObject::get_by_id($areaClass, $areaId);

            if ($area) {
                return [$area, false];
            }
        }

        // Sidesteps the known ElementalAreaController 400 on pages with
        // ElementalAreaID=0: the API simply creates and attaches the area.
        /** @var DataObject $area */
        $area = Injector::inst()->create($areaClass);
        $area->setField('OwnerClassName', get_class($page));
        $area->write();

        $page->setField($relationName . 'ID', $area->ID);
        $page->write();

        return [$area, true];
    }

    protected function processAsset(array $entry, int $index): array
    {
        $ref = isset($entry['ref']) ? (string) $entry['ref'] : null;

        if (empty($entry['base64'])) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Composition asset %d requires "base64" content.', $index),
                [['index' => $index]]
            );
        }

        $binary = base64_decode((string) $entry['base64'], true);

        if ($binary === false) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Composition asset %d "base64" is not valid base64.', $index)
            );
        }

        unset($entry['base64'], $entry['ref']);

        $result = $this->assets->ingest($binary, $entry);

        if ($ref !== null) {
            $this->refs[$ref] = $result['record'];
        }

        return [
            'index' => $index,
            'ref' => $ref,
            'id' => (int) $result['record']->ID,
            'externalId' => $entry['externalId'] ?? null,
            'filename' => $result['record']->getFilename(),
            'status' => $result['existed'] ? 'updated' : 'created',
        ];
    }

    /**
     * Ordered element upserts with a deferred-retry queue: an element whose
     * `$ref` targets aren't resolved yet is retried after the others; refs
     * that never resolve are UNRESOLVED_REF (or CIRCULAR_REF when deferred
     * elements only reference each other).
     */
    protected function processElements(array $entries, DataObject $area, Member $member): array
    {
        $queue = [];

        foreach (array_values($entries) as $index => $entry) {
            $queue[] = ['index' => $index, 'entry' => (array) $entry];
        }

        $results = [];
        $remaining = $queue;

        while ($remaining !== []) {
            $progressed = false;
            $deferred = [];

            foreach ($remaining as $item) {
                if ($this->hasUnresolvedRefs($item['entry'])) {
                    $deferred[] = $item;
                    continue;
                }

                $results[$item['index']] = $this->processElement($item['entry'], $item['index'], $area, $member);
                $progressed = true;
            }

            if (!$progressed && $deferred !== []) {
                $refs = array_unique(array_merge(
                    ...array_map(fn ($item) => $this->collectRefs($item['entry']), $deferred)
                ));
                $unresolvable = array_values(array_diff($refs, array_keys($this->refs)));

                throw new ApiError(
                    count($deferred) > 1 ? ErrorCode::CIRCULAR_REF : ErrorCode::UNRESOLVED_REF,
                    sprintf('Unresolvable $ref(s): %s.', implode(', ', $unresolvable))
                );
            }

            $remaining = $deferred;
        }

        ksort($results);

        return array_values($results);
    }

    protected function processElement(array $entry, int $index, DataObject $area, Member $member): array
    {
        $externalId = isset($entry['externalId']) ? (string) $entry['externalId'] : '';

        if ($externalId === '') {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Composition element %d requires "externalId" (the idempotency/prune key).', $index)
            );
        }

        $elementClass = $this->registry->resolve((string) ($entry['class'] ?? ''));

        if (!is_a($elementClass, 'DNADesign\\Elemental\\Models\\BaseElement', true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Composition element %d: "%s" is not an element class.', $index, $entry['class'] ?? '')
            );
        }

        $fields = $this->resolveRefs((array) ($entry['fields'] ?? []));
        $relations = $this->resolveRefs((array) ($entry['relations'] ?? []));

        // ParentID (the ElementalArea FK) is always server-derived — it never
        // needs to appear in a class's api_writable_fields, which would also
        // expose it on the untrusted colymba PUT surface (module #27). Sort
        // is server-derived only as a default; a user-supplied Sort stays in
        // $fields and is still subject to the normal allowlist, like any
        // other content field.
        $internalFields = ['ParentID' => (int) $area->ID];

        if (!isset($fields['Sort'])) {
            $internalFields['Sort'] = $index + 1;
        }

        $result = $this->writer->upsert($elementClass, [
            'externalId' => $externalId,
            'fields' => $fields,
            'relations' => $relations,
            'publish' => 'none',
        ], $member, 'upsert', $internalFields);

        $element = $result['record'];

        if (isset($entry['ref'])) {
            $this->refs[(string) $entry['ref']] = $element;
        }

        $children = [];

        foreach ((array) ($entry['children'] ?? []) as $relationName => $items) {
            foreach (array_values((array) $items) as $childIndex => $childEntry) {
                $children[] = $this->processChild(
                    $element,
                    (string) $relationName,
                    (array) $childEntry,
                    $childIndex,
                    $member
                );
            }
        }

        return [
            'index' => $index,
            'ref' => $entry['ref'] ?? null,
            'externalId' => $externalId,
            'id' => (int) $element->ID,
            'status' => $result['operation'],
            'warnings' => $result['warnings'],
            'children' => $children,
            'record' => $element,
        ];
    }

    /**
     * Children are owned records written under the element's aggregate,
     * upsert by externalId, attached via the has_many list (which sets the
     * FK). They still enforce the same class-level ACL and canCreate()/
     * canEdit() gate as every other write surface (module #19) — a
     * `CONTENT_API_POPULATE` grant is not itself sufficient to write an
     * arbitrary has_many-target class. The externalId lookup is scoped to
     * this element's own relation list, so a composition can't adopt or
     * re-parent a child that currently belongs to a different element.
     */
    protected function processChild(
        DataObject $element,
        string $relationName,
        array $entry,
        int $index,
        Member $member
    ): array {
        $childClass = DataObject::getSchema()->hasManyComponent(get_class($element), $relationName);

        if (!$childClass) {
            throw new ApiError(
                ErrorCode::UNKNOWN_RELATION,
                sprintf('Element %s has no has_many "%s".', get_class($element), $relationName)
            );
        }

        $childClass = strtok($childClass, '.');

        if (!empty($entry['class'])) {
            $override = $this->registry->resolve((string) $entry['class']);

            if (!is_a($override, $childClass, true)) {
                throw new ApiError(
                    ErrorCode::PAYLOAD_INVALID,
                    sprintf('"%s" is not a %s subclass.', $entry['class'], $childClass)
                );
            }

            $childClass = $override;
        }

        $externalId = isset($entry['externalId']) ? (string) $entry['externalId'] : '';

        if ($externalId === '') {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Child %d of relation "%s" requires "externalId".', $index, $relationName)
            );
        }

        $this->externalIds->assertSupported($childClass);
        $child = $this->findChildScopedToElement($element, $relationName, $childClass, $externalId);
        $fields = $this->resolveRefs((array) ($entry['fields'] ?? []));

        if ($child) {
            $this->policy->checkClassAccess($childClass, 'update', $member);
            $this->policy->checkRecordAccess($child, 'update', $member);
            $operation = 'updated';
        } else {
            $this->policy->checkClassAccess($childClass, 'create', $member);
            $this->policy->checkCreateAccess($childClass, $member, $fields);

            /** @var DataObject $child */
            $child = Injector::inst()->create($childClass);
            $child->setField($this->externalIds->fieldName(), $externalId);
            $operation = 'created';
        }

        $this->applicator->applyFields($child, $fields);

        try {
            $child->write();
        } catch (ValidationException $exception) {
            throw ApiError::fromValidation($exception, sprintf('Child "%s"', $externalId));
        }

        $element->{$relationName}()->add($child);

        return [
            'relation' => $relationName,
            'externalId' => $externalId,
            'id' => (int) $child->ID,
            'status' => $operation,
            'record' => $child,
        ];
    }

    /**
     * Find an existing child by external id, scoped to this element's own
     * has_many list AND narrowed to $childClass (its full subclass tree, to
     * mirror `DataObject::get()`'s own class filtering) before counting
     * matches — a same-relation sibling of a different subclass sharing the
     * external id must never trip a false MULTIPLE_MATCHES, and must never
     * be returned as a match. `ExternalIdResolver::tryFind()` is a global
     * lookup by design (it has no notion of an owning element); using it
     * directly here would let a composition silently adopt/re-parent a
     * child that currently belongs to a *different* element (module #19). A
     * global external-id collision under a different element is therefore
     * treated as not-found — a new child is created instead, and any
     * resulting duplicate-identifier ambiguity surfaces the same way it
     * would for any other externally-identified record.
     *
     * @throws ApiError MULTIPLE_MATCHES when more than one of this element's
     *   own children of this class under this relation share the external id
     */
    protected function findChildScopedToElement(
        DataObject $element,
        string $relationName,
        string $childClass,
        string $externalId
    ): ?DataObject {
        $list = $element->{$relationName}()->filter('ClassName', ClassInfo::subclassesFor($childClass));

        return $this->externalIds->tryFindScoped(
            $list,
            $externalId,
            sprintf('%s record under this element', $childClass)
        );
    }

    /**
     * Archive elements in the area that the payload no longer describes.
     * `managed` (default): only elements carrying an external id are
     * candidates — hand-authored CMS content is invisible to prune.
     */
    protected function prune(
        DataObject $area,
        array $entries,
        string $scope,
        string $elementsRelation = 'Elements'
    ): array {
        if (!in_array($scope, ['managed', 'all'], true)) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'prune.scope must be "managed" or "all".');
        }

        $keep = array_values(array_filter(array_map(
            fn ($entry) => $entry['externalId'] ?? null,
            $entries
        )));

        $field = $this->externalIds->fieldName();
        $pruned = [];

        foreach ($area->getComponents($elementsRelation) as $element) {
            $externalId = $element->hasField($field) ? $element->getField($field) : null;

            if ($scope === 'managed' && ($externalId === null || $externalId === '')) {
                continue;
            }

            if ($externalId !== null && in_array($externalId, $keep, true)) {
                continue;
            }

            $pruned[] = [
                'id' => (int) $element->ID,
                'externalId' => $externalId ?: null,
                'className' => $element->ClassName,
            ];

            $element->doArchive();
        }

        return $pruned;
    }

    /**
     * Publish the page plus every written area/element/child — page
     * publishRecursive does not cascade into elements, so this routes
     * through {@see PublishOrchestrator::publishOwnedTree()} (#119/#168)
     * instead: the area and every element/child are passed as `$additional`
     * targets (this method already knows exactly which ones it just wrote,
     * so it doesn't rely on `$owns` reaching them — `BaseElement` itself
     * declares no `$owns`, so element children aren't walk-reachable
     * without a project opting in), and every one of them is now
     * authorization-checked (class `action` verb + `canEdit()`) before
     * anything is written. This closes the gap #168 tracked: previously
     * `publish($record, 'single', $member)` performed no authorization at
     * all for the area/element/child classes, only the page itself was
     * checked (see `compose()`, above).
     *
     * Not every has_many child model is Versioned (e.g. Essentials'
     * StatCounter is a plain DataObject) — `publishOwnedTree()` silently
     * drops anything unversioned from `$additional` rather than erroring,
     * so no duck-typed `hasMethod('publishSingle')` check is needed here.
     */
    protected function publishAll(SiteTree $page, DataObject $area, array $elementResults, Member $member): void
    {
        $additional = [$area];

        foreach ($elementResults as $result) {
            $additional[] = $result['record'];

            foreach ($result['children'] as $childResult) {
                $additional[] = $childResult['record'];
            }
        }

        $this->publisher->publishOwnedTree($page, $member, $additional);
    }

    /**
     * Deep-replace `{"$ref": "name"}` values with `{"id": <resolved>}`.
     */
    protected function resolveRefs(array $data): array
    {
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (array_keys($value) === ['$ref']) {
                $name = (string) $value['$ref'];

                if (!isset($this->refs[$name])) {
                    throw new ApiError(
                        ErrorCode::UNRESOLVED_REF,
                        sprintf('Unresolved $ref "%s".', $name)
                    );
                }

                $data[$key] = ['id' => (int) $this->refs[$name]->ID];
                continue;
            }

            $data[$key] = $this->resolveRefs($value);
        }

        return $data;
    }

    protected function hasUnresolvedRefs(array $entry): bool
    {
        foreach ($this->collectRefs($entry) as $name) {
            if (!isset($this->refs[$name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[] every $ref name mentioned in the entry
     */
    protected function collectRefs(array $data): array
    {
        $names = [];

        array_walk_recursive($data, function ($value, $key) use (&$names) {
            if ($key === '$ref') {
                $names[] = (string) $value;
            }
        });

        return $names;
    }
}
