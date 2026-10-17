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
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Read endpoints: GET records/$ClassRef and GET records/$ClassRef/$ID.
 *
 * `$ID` accepts a numeric ID or `ext:<external-id>`. List filtering uses
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
            // row, a version-number query each unless the cache is primed:
            // two queries for the whole list instead (#244).
            if (DataObject::has_extension($className, Versioned::class)) {
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
     * Fetch by numeric ID or `ext:` external-id reference.
     *
     * @throws ApiError NOT_FOUND
     */
    public function fetchRecord(string $className, string $idParam): DataObject
    {
        if (str_starts_with($idParam, 'ext:')) {
            // The request path is never percent-decoded by the framework, so
            // `ext:home%20page` would otherwise look up the literal "%20" (#240).
            return $this->externalIds->find($className, rawurldecode(substr($idParam, 4)));
        }

        if (!ctype_digit($idParam)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Invalid record id "%s" — use a numeric id or "ext:<external-id>".', $idParam)
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
            // An empty value is a filter for "no value", not "no filter"
            // (#244): `?Title=` matches records whose Title is empty or
            // NULL (a string field stores '' as NULL).
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
            $filterValue = match (true) {
                $value === '' && $modifier === null => ['', null],
                str_contains((string) $value, ',') => explode(',', (string) $value),
                default => $value,
            };

            $list = $list->filter($filterKey, $filterValue);
        }

        return $list;
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
