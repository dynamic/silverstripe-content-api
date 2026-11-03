<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Serialize\RecordSerializer;
use Dynamic\ContentApi\Verify\VersionCache;
use SilverStripe\CMS\Controllers\RootURLController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBString;
use SilverStripe\Versioned\Versioned;

/**
 * Read endpoints: GET records/$ClassRef and GET records/$ClassRef/$ID.
 *
 * `$ID` accepts a numeric ID, `ext:<external-id>` or `path:<url-path>`
 * (the latter `SiteTree` classes only; a nested path must be sent
 * percent-encoded, e.g. `path:about%2Four-team`, because `$ID` matches a
 * single route segment). List filtering uses
 * `Field=value` / `Field__Modifier=value` query params (colymba-style syntax,
 * validated against the class schema and a modifier allowlist). `_stage`
 * selects draft (default) or live reading for Versioned classes — underscored
 * because bare `stage` is SilverStripe's own reserved staging param, consumed
 * by the Versioned middleware before any controller runs.
 */
class RecordsHandler
{
    use Configurable;
    use Injectable;
    use StageAwareTrait;

    private static int $default_limit = 50;

    private static int $max_limit = 500;

    private static array $allowed_filter_modifiers = [
        'ExactMatch',
        'PartialMatch',
        'StartsWith',
        'EndsWith',
        'GreaterThan',
        'GreaterThanOrEqual',
        'LessThan',
        'LessThanOrEqual',
        'not',
        'nocase',
        'case',
    ];

    private static array $reserved_query_params = [
        'sort',
        'limit',
        'offset',
        '_stage',
        'stage',
        'token',
        'url',
        'flush',
        'flushtoken',
        'ajax',
    ];

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'policy' => '%$' . PermissionPolicy::class,
        'serializer' => '%$' . RecordSerializer::class,
        'externalIds' => '%$' . ExternalIdResolver::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?PermissionPolicy $policy = null;

    public ?RecordSerializer $serializer = null;

    public ?ExternalIdResolver $externalIds = null;

    public function readOne(HTTPRequest $request, AuthContext $context): array
    {
        $className = $this->registry->resolve((string) $request->param('ClassRef'));
        $this->policy->checkClassAccess($className, 'read', $context->member);

        $idParam = (string) $request->param('ID');
        $stage = $this->resolveStage($request);

        return $this->withStage($className, $stage, function () use ($className, $idParam, $context, $stage) {
            $record = $this->fetchRecord($className, $idParam);
            // The URL's class gate inherits, and the row loads polymorphically,
            // so a subclass that explicitly denies (or narrows) its ancestor's
            // verbs is checked against its own class too (#236).
            $this->policy->checkClassAccess(get_class($record), 'read', $context->member);
            $this->policy->checkRecordAccess($record, 'read', $context->member);

            return [
                'data' => $this->serializer->serialize($record),
                'meta' => ['stage' => $stage],
            ];
        });
    }

    public function readList(HTTPRequest $request, AuthContext $context): array
    {
        $className = $this->registry->resolve((string) $request->param('ClassRef'));
        $this->policy->checkClassAccess($className, 'read', $context->member);

        $stage = $this->resolveStage($request);
        $limit = $this->resolveLimit($request);
        $offset = $this->resolveOffset($request);

        return $this->withStage($className, $stage, function () use (
            $request,
            $className,
            $context,
            $stage,
            $limit,
            $offset
        ) {
            $list = DataObject::get($className);
            $list = $this->applyFilters($list, $className, $request);
            $list = $this->applySort($list, $className, $request);

            // canViewRecord() below runs Versioned::canViewVersioned() per
            // row on a draft read, a version-number query each unless the
            // cache is primed: a few batched queries instead (#244). A live
            // read returns before any lookup, so it needs none.
            if ($stage === 'draft' && DataObject::has_extension($className, Versioned::class)) {
                VersionCache::prime($className, array_map('intval', $list->column('ID')));
            }

            // canView is a per-record DataObject method (no generic SQL
            // pushdown), so visibility must be resolved before total/limit/
            // offset are computed — filtering after paginating both leaks
            // the hidden-record count via meta.total and can return short
            // pages that can never be paged around (#20). This trades a
            // full-list iteration for correctness, consistent with the
            // module's reject-rather-than-silently-degrade posture.
            //
            // Every row still has to be visited and canView-checked to reach
            // an exact total — there's no SQL pushdown for it — but we only
            // ever hold $limit records in memory at once (not the entire
            // visible set): DataList::filterByCallback() would materialize
            // every visible row into an ArrayList before we could slice it,
            // which defeats that bound for a large table.
            $total = 0;
            $data = [];

            $readableClasses = [];

            foreach ($list as $record) {
                // A row whose own class doesn't grant `read` (an explicitly
                // denied subclass under an exposed ancestor ref) is hidden,
                // counted out of meta.total like a canView() refusal (#236).
                $rowClass = get_class($record);
                $readableClasses[$rowClass] ??= in_array('read', $this->registry->accessVerbs($rowClass), true);

                if (!$readableClasses[$rowClass] || !$this->policy->canViewRecord($record, $context->member)) {
                    continue;
                }

                if ($total >= $offset && count($data) < $limit) {
                    $data[] = $this->serializer->serialize($record);
                }

                $total++;
            }

            return [
                'data' => $data,
                'meta' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'stage' => $stage,
                ],
            ];
        });
    }

    /**
     * Fetch by numeric ID, `ext:` external-id reference, or `path:` URL path.
     *
     * @throws ApiError NOT_FOUND PAYLOAD_INVALID
     */
    public function fetchRecord(string $className, string $idParam): DataObject
    {
        if (str_starts_with($idParam, 'ext:')) {
            // The request path is never percent-decoded by the framework, so
            // `ext:home%20page` would otherwise look up the literal "%20" (#240).
            return $this->externalIds->find($className, rawurldecode(substr($idParam, 4)));
        }

        if (str_starts_with($idParam, 'path:')) {
            return $this->fetchByPath($className, substr($idParam, 5));
        }

        if (!ctype_digit($idParam)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf(
                    'Invalid record id "%s" — use a numeric id, "ext:<external-id>", or "path:<url-path>".',
                    $idParam
                )
            );
        }

        $record = DataObject::get_by_id($className, (int) $idParam);

        if (!$record) {
            throw new ApiError(
                ErrorCode::NOT_FOUND,
                sprintf('No %s found with id %d.', $className, (int) $idParam)
            );
        }

        return $record;
    }

    /**
     * Resolve a `SiteTree` record by its URL path (#121), e.g. `about` or
     * `about/our-team`, through `SiteTree::get_by_link()` — the same
     * URL-segment walk the framework's own routing does, so no new resolution
     * logic is introduced. `readOne()` already runs this inside `withStage()`
     * (the write handlers set the draft stage themselves), so the lookup
     * honours `_stage=draft|live` on every route that takes an `$ID`.
     *
     * Scoped to `SiteTree` deliberately: a URL path means nothing for an
     * arbitrary `DataObject`, and `Hierarchy` alone has no routing equivalent.
     *
     * @throws ApiError NOT_FOUND PAYLOAD_INVALID
     */
    private function fetchByPath(string $className, string $link): DataObject
    {
        if (!is_a($className, SiteTree::class, true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('path: addressing is only supported for SiteTree classes, got %s.', $className)
            );
        }

        // Percent-decoded for the same reason as `ext:` above (#240), and it is
        // what turns the route's single `%2F`-encoded segment back into a path.
        $link = $this->normaliseLink($link);

        // `get_by_link('')` resolves to the home page; an explicit but empty
        // path is more likely a caller bug than a request for `/`.
        if ($link === '') {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                'path: addressing needs a non-empty url path.'
            );
        }

        $page = SiteTree::get_by_link($link, false);

        if (!$page instanceof $className) {
            throw new ApiError(
                ErrorCode::NOT_FOUND,
                sprintf('No %s found at path "%s".', $className, $link)
            );
        }

        // `get_by_link()` stops short in two of its fallbacks: with nested_urls
        // on it returns a unique-URLSegment match without walking the rest of
        // the path, and with nested_urls off it ignores the trailing segments
        // entirely. The front end can afford that — the leftovers become a
        // controller action — but an addressing scheme that also feeds PATCH,
        // publish and archive cannot, because a stale or mistyped path would
        // silently act on a different page. So require the resolved page's own
        // full path to be the path that was asked for, which also means a
        // nested page must be addressed by its full path and not by its bare
        // URLSegment.
        if (strcasecmp($this->normaliseLink($this->fullLink($page)), $link) !== 0) {
            throw new ApiError(
                ErrorCode::NOT_FOUND,
                sprintf('No %s found at path "%s".', $className, $link)
            );
        }

        return $page;
    }

    /**
     * The page's full URL path, including the root segment that
     * `RelativeLink()` drops for the home page itself.
     */
    private function fullLink(SiteTree $page): string
    {
        $link = (string) $page->RelativeLink();

        // RelativeLink() hands back the site root for a root-level homepage
        // rather than its own URLSegment: '/' under the current signature, and
        // '' for any extension that short-circuits `updateRelativeLink`. Both
        // mean "restore the segment that was dropped", or the guard in
        // fetchByPath() compares an empty path against the one asked for and
        // 404s the site's own front page.
        return trim($link, '/') === '' ? RootURLController::get_homepage_link() : $link;
    }

    private function normaliseLink(string $link): string
    {
        return trim(Director::makeRelative(rawurldecode($link)) ?? '', '/');
    }

    private function resolveStage(HTTPRequest $request): string
    {
        $stage = strtolower((string) ($request->getVar('_stage') ?: 'draft'));

        if (!in_array($stage, ['draft', 'live'], true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                'The "_stage" parameter must be "draft" or "live".'
            );
        }

        return $stage;
    }

    /**
     * `limit`: a positive integer, clamped to `max_limit`; absent or empty
     * means `default_limit`. Anything else is refused rather than coerced
     * (#244).
     *
     * @throws ApiError PAYLOAD_INVALID
     */
    private function resolveLimit(HTTPRequest $request): int
    {
        $max = (int) static::config()->get('max_limit');
        $limit = $this->intParam($request, 'limit') ?? (int) static::config()->get('default_limit');

        if ($limit < 1) {
            throw new ApiError(ErrorCode::PAYLOAD_INVALID, 'The "limit" parameter must be a positive integer.');
        }

        return min($limit, $max);
    }

    /**
     * `offset`: a non-negative integer; absent or empty means 0.
     *
     * @throws ApiError PAYLOAD_INVALID
     */
    private function resolveOffset(HTTPRequest $request): int
    {
        return $this->intParam($request, 'offset') ?? 0;
    }

    /**
     * @throws ApiError PAYLOAD_INVALID when the value is not a run of digits
     */
    private function intParam(HTTPRequest $request, string $name): ?int
    {
        $value = $request->getVar($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !ctype_digit($value)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('The "%s" parameter must be a non-negative integer.', $name)
            );
        }

        return (int) $value;
    }

    private function applyFilters(DataList $list, string $className, HTTPRequest $request): DataList
    {
        $reserved = (array) static::config()->get('reserved_query_params');
        $modifiers = (array) static::config()->get('allowed_filter_modifiers');

        foreach ($request->getVars() as $key => $value) {
            if (in_array($key, $reserved, true) || $value === null) {
                continue;
            }

            $field = $key;
            $modifier = null;

            if (str_contains($key, '__')) {
                [$field, $modifier] = explode('__', $key, 2);

                if (!in_array($modifier, $modifiers, true)) {
                    throw new ApiError(
                        ErrorCode::PAYLOAD_INVALID,
                        sprintf('Unsupported filter modifier "%s".', $modifier)
                    );
                }
            }

            // Only columns a response can show are filterable: filtering on
            // a withheld column (hidden_fields, or outside api_fields) would
            // leak its value through which rows match (#236).
            if (!in_array($field, $this->serializer->readableColumns($className), true)) {
                throw new ApiError(
                    ErrorCode::UNKNOWN_FIELD,
                    sprintf('Cannot filter on unknown field "%s".', $field)
                );
            }

            $filterKey = $modifier ? "{$field}:{$modifier}" : $field;
            $filterValue = str_contains((string) $value, ',') ? explode(',', (string) $value) : $value;

            // An empty value is a filter for "no value", not "no filter"
            // (#244). A string field stores '' as NULL, so an exact match
            // (or its negation) on '' also matches NULL. Only string fields
            // take one: on a number or boolean MySQL reads '' as 0.
            if ($filterValue === '' || (is_array($filterValue) && in_array('', $filterValue, true))) {
                $this->assertStringField($className, $field);

                if (in_array($modifier, [null, 'ExactMatch', 'not', 'case', 'nocase'], true)) {
                    $filterValue = array_merge((array) $filterValue, [null]);
                }
            }

            $list = $list->filter($filterKey, $filterValue);
        }

        return $list;
    }

    /**
     * @throws ApiError PAYLOAD_INVALID when the field isn't a string column
     */
    private function assertStringField(string $className, string $field): void
    {
        $dbField = DataObject::singleton($className)->dbObject($field);

        if (!$dbField instanceof DBString) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('An empty filter value only applies to a text field; "%s" is not one.', $field)
            );
        }
    }

    private function applySort(DataList $list, string $className, HTTPRequest $request): DataList
    {
        $sortParam = (string) $request->getVar('sort');

        if ($sortParam === '') {
            return $list;
        }

        $sort = [];

        foreach (explode(',', $sortParam) as $clause) {
            $clause = trim($clause);

            if ($clause === '') {
                continue;
            }

            $direction = 'ASC';

            if (str_starts_with($clause, '-')) {
                $direction = 'DESC';
                $clause = substr($clause, 1);
            }

            // Same readable-column rule as filters: a sort leaks a withheld
            // column's ordering (#236).
            if (!in_array($clause, $this->serializer->readableColumns($className), true)) {
                throw new ApiError(
                    ErrorCode::UNKNOWN_FIELD,
                    sprintf('Cannot sort on unknown field "%s".', $clause)
                );
            }

            $sort[$clause] = $direction;
        }

        return $sort === [] ? $list : $list->sort($sort);
    }
}
