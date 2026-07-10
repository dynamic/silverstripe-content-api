<?php

namespace Dynamic\ContentApi\Control\Handlers;

use Dynamic\ContentApi\Auth\AuthContext;
use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\PermissionPolicy;
use Dynamic\ContentApi\Serialize\RecordSerializer;
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
 * validated against the class schema and a modifier allowlist). `stage`
 * selects draft (default) or live reading for Versioned classes.
 */
class RecordsHandler
{
    use Configurable;
    use Injectable;

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
        $offset = max(0, (int) $request->getVar('offset'));

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

            $total = $list->count();
            $page = $list->limit($limit, $offset);

            $data = [];

            foreach ($page as $record) {
                if (!$this->policy->canViewRecord($record, $context->member)) {
                    continue;
                }

                $data[] = $this->serializer->serialize($record);
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
            return $this->externalIds->find($className, substr($idParam, 4));
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
        $stage = strtolower((string) ($request->getVar('stage') ?: 'draft'));

        if (!in_array($stage, ['draft', 'live'], true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                'The "stage" parameter must be "draft" or "live".'
            );
        }

        return $stage;
    }

    private function resolveLimit(HTTPRequest $request): int
    {
        $default = (int) static::config()->get('default_limit');
        $max = (int) static::config()->get('max_limit');

        $limit = (int) ($request->getVar('limit') ?: $default);

        return max(1, min($limit, $max));
    }

    /**
     * Run a callable in the requested Versioned stage; no-op wrapper for
     * unversioned classes.
     */
    private function withStage(string $className, string $stage, callable $callback): mixed
    {
        if (!DataObject::singleton($className)->hasExtension(Versioned::class)) {
            return $callback();
        }

        return Versioned::withVersionedMode(function () use ($stage, $callback) {
            Versioned::set_stage($stage === 'live' ? Versioned::LIVE : Versioned::DRAFT);

            return $callback();
        });
    }

    private function applyFilters(DataList $list, string $className, HTTPRequest $request): DataList
    {
        $reserved = (array) static::config()->get('reserved_query_params');
        $modifiers = (array) static::config()->get('allowed_filter_modifiers');
        $schema = DataObject::getSchema();

        foreach ($request->getVars() as $key => $value) {
            if (in_array($key, $reserved, true) || $value === null || $value === '') {
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

            if ($field !== 'ID' && !$schema->fieldSpec($className, $field)) {
                throw new ApiError(
                    ErrorCode::UNKNOWN_FIELD,
                    sprintf('Cannot filter on unknown field "%s".', $field)
                );
            }

            $filterKey = $modifier ? "{$field}:{$modifier}" : $field;
            $filterValue = str_contains((string) $value, ',') ? explode(',', (string) $value) : $value;

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

        $schema = DataObject::getSchema();
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

            if ($clause !== 'ID' && !$schema->fieldSpec($className, $clause)) {
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
