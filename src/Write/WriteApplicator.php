<?php

namespace Dynamic\ContentApi\Write;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Applies a sparse write payload to a record: only keys present in the
 * payload are touched (the anti-clobber guarantee — populate's whole-field-map
 * copy is exactly what this module exists to avoid).
 *
 * Validation happens before anything is applied; a payload that fails the
 * writability rules changes nothing. This replaces project-feedback's
 * after-the-fact ApiFieldGuardExtension revert dance with a native gate.
 *
 * Write policies (global `policy`, per-class `api_write_policy` override):
 * - `guarded` (default): all db fields + has_one relations are writable
 *   except `protected_fields` + per-class `api_protected_fields`. has_many /
 *   many_many writes require the relation in `api_writable_relations`.
 * - `allowlist`: only per-class `api_writable_fields` entries are writable
 *   (relations still gate through `api_writable_relations`).
 */
class WriteApplicator
{
    use Configurable;
    use Injectable;

    private static string $policy = 'guarded';

    /**
     * Never writable regardless of policy.
     */
    private static array $protected_fields = [
        'ID',
        'ClassName',
        'Created',
        'LastEdited',
        'Version',
        'Password',
        'Salt',
        'PasswordEncryption',
        'AutoLoginHash',
        'TempIDHash',
        'ApiToken',
        'ApiTokenExpire',
    ];

    /**
     * `strict` rejects unknown payload keys; `lenient` reports them as
     * warnings and continues.
     */
    private static string $unknown_fields = 'strict';

    /**
     * ValueTransformer service references, applied first-match-wins.
     *
     * @var array
     */
    private static array $transformers = [];

    private static array $dependencies = [
        'externalIds' => '%$' . ExternalIdResolver::class,
    ];

    public ?ExternalIdResolver $externalIds = null;

    /**
     * Warnings collected during the last apply() call.
     *
     * @var array<int, array<string, string>>
     */
    protected array $warnings = [];

    /**
     * Validate and apply `fields` to the record (unsaved). Relations are
     * applied separately via applyRelations() after the record has an ID.
     *
     * @param array<string, mixed> $fields
     * @throws ApiError when any field fails writability — nothing is applied
     */
    public function applyFields(DataObject $record, array $fields): void
    {
        $this->warnings = [];
        $schema = DataObject::getSchema();
        $className = get_class($record);
        $hasOne = (array) $record->hasOne();
        $problems = [];
        $plan = [];

        foreach ($fields as $name => $value) {
            $isHasOne = isset($hasOne[$name]);
            $isHasOneFk = str_ends_with($name, 'ID') && isset($hasOne[substr($name, 0, -2)]);
            $isDbField = $schema->fieldSpec($className, $name) !== null;

            if (!$isHasOne && !$isDbField && !$isHasOneFk) {
                if ($this->unknownFieldMode($className) === 'strict') {
                    $problems[] = [
                        'field' => $name,
                        'code' => ErrorCode::UNKNOWN_FIELD->value,
                        'message' => sprintf('Unknown field "%s" on %s.', $name, $className),
                    ];
                } else {
                    $this->warnings[] = [
                        'code' => ErrorCode::UNKNOWN_FIELD->value,
                        'message' => sprintf('Ignored unknown field "%s".', $name),
                        'field' => $name,
                    ];
                }
                continue;
            }

            $columnName = $isHasOne ? $name . 'ID' : $name;

            if (!$this->isWritable($className, $columnName, $isHasOne ? $name : null)) {
                $problems[] = [
                    'field' => $name,
                    'code' => ErrorCode::READONLY_FIELD->value,
                    'message' => sprintf('Field "%s" is not writable via the content API.', $name),
                ];
                continue;
            }

            $plan[] = [$name, $columnName, $isHasOne, $value];
        }

        if ($problems !== []) {
            throw new ApiError(
                ErrorCode::from($problems[0]['code']),
                sprintf('%d field(s) rejected.', count($problems)),
                $problems
            );
        }

        foreach ($plan as [$name, $columnName, $isHasOne, $value]) {
            $value = $this->transformValue($record, $name, $value);

            if ($isHasOne) {
                $record->setField($columnName, $this->resolveRelationId($record, $name, $value));
            } else {
                $record->setCastedField($columnName, $value);
            }
        }
    }

    /**
     * Apply `relations` payload (has_many / many_many) to a saved record.
     *
     * Payload per relation: `{ "mode": "set|add|remove", "items": [ ... ] }`
     * where each item is an int ID, `{"id": n}` or `{"externalId": "..."}`,
     * optionally with `"extraFields"` for many_many.
     *
     * @param array<string, mixed> $relations
     * @throws ApiError
     */
    public function applyRelations(DataObject $record, array $relations): void
    {
        $className = get_class($record);
        $hasMany = (array) $record->hasMany();
        $manyMany = (array) $record->manyMany();
        $writable = (array) Config::inst()->get($className, 'api_writable_relations');

        foreach ($relations as $name => $spec) {
            $relationClass = null;

            if (isset($hasMany[$name])) {
                $relationClass = $hasMany[$name];
            } elseif (isset($manyMany[$name])) {
                $classes = $manyMany[$name];
                $relationClass = is_array($classes) ? ($classes['to'] ?? null) : $classes;
            }

            if (!$relationClass) {
                throw new ApiError(
                    ErrorCode::UNKNOWN_RELATION,
                    sprintf('Unknown relation "%s" on %s.', $name, $className)
                );
            }

            if (!in_array($name, $writable, true)) {
                throw new ApiError(
                    ErrorCode::READONLY_FIELD,
                    sprintf(
                        'Relation "%s" is not writable — add it to %s.api_writable_relations.',
                        $name,
                        $className
                    )
                );
            }

            if (!is_array($spec) || !isset($spec['mode'])) {
                throw new ApiError(
                    ErrorCode::PAYLOAD_INVALID,
                    sprintf('Relation "%s" requires {"mode": "set|add|remove", "items": [...]}.', $name)
                );
            }

            $mode = (string) $spec['mode'];
            $items = (array) ($spec['items'] ?? []);

            if (!in_array($mode, ['set', 'add', 'remove'], true)) {
                throw new ApiError(
                    ErrorCode::PAYLOAD_INVALID,
                    sprintf('Relation mode "%s" must be set, add or remove.', $mode)
                );
            }

            // Strip the relation-class suffix from dot notation (has_many keys
            // may be Class.FKField).
            $relationClass = strtok($relationClass, '.');

            $list = $record->{$name}();

            if ($mode === 'set') {
                $list->removeAll();
            }

            foreach ($items as $item) {
                [$related, $extraFields] = $this->resolveRelationItem($relationClass, $name, $item);

                if ($mode === 'remove') {
                    $list->remove($related);
                } else {
                    $extraFields === [] ? $list->add($related) : $list->add($related, $extraFields);
                }
            }
        }
    }

    /**
     * Warnings collected by the last applyFields() run (lenient mode).
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Public writability check (used by schema introspection).
     */
    public function isFieldWritable(string $className, string $columnName, ?string $relationName = null): bool
    {
        return $this->isWritable($className, $columnName, $relationName);
    }

    protected function isWritable(string $className, string $columnName, ?string $relationName): bool
    {
        $protected = array_merge(
            (array) static::config()->get('protected_fields'),
            (array) Config::inst()->get($className, 'api_protected_fields')
        );

        if (in_array($columnName, $protected, true) || ($relationName && in_array($relationName, $protected, true))) {
            return false;
        }

        $policy = Config::inst()->get($className, 'api_write_policy')
            ?: static::config()->get('policy');

        if ($policy !== 'allowlist') {
            return true;
        }

        $allowed = (array) Config::inst()->get($className, 'api_writable_fields');

        return in_array($columnName, $allowed, true)
            || ($relationName && in_array($relationName, $allowed, true));
    }

    protected function unknownFieldMode(string $className): string
    {
        return Config::inst()->get($className, 'api_unknown_fields')
            ?: (string) static::config()->get('unknown_fields');
    }

    protected function transformValue(DataObject $record, string $fieldName, mixed $value): mixed
    {
        foreach ((array) static::config()->get('transformers') as $transformer) {
            $service = is_string($transformer)
                ? \SilverStripe\Core\Injector\Injector::inst()->get(ltrim($transformer, '%$'))
                : $transformer;

            if ($service instanceof ValueTransformer && $service->supports($record, $fieldName, $value)) {
                return $service->transform($record, $fieldName, $value);
            }
        }

        return $value;
    }

    /**
     * Resolve a has_one payload value to a foreign key ID.
     * Accepts int, null, {"id": n} or {"externalId": "..."}.
     */
    protected function resolveRelationId(DataObject $record, string $relationName, mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        if (is_array($value)) {
            $relationClass = DataObject::getSchema()->hasOneComponent(get_class($record), $relationName);

            if (isset($value['id'])) {
                return (int) $value['id'];
            }

            if (isset($value['externalId']) && $relationClass) {
                return (int) $this->externalIds->find($relationClass, (string) $value['externalId'])->ID;
            }
        }

        throw new ApiError(
            ErrorCode::PAYLOAD_INVALID,
            sprintf(
                'Relation "%s" accepts an integer ID, null, {"id": n} or {"externalId": "..."}.',
                $relationName
            )
        );
    }

    /**
     * @return array{0: DataObject, 1: array<string, mixed>}
     */
    protected function resolveRelationItem(string $relationClass, string $relationName, mixed $item): array
    {
        $extraFields = [];

        if (is_array($item)) {
            $extraFields = (array) ($item['extraFields'] ?? []);
        }

        if (is_int($item) || (is_string($item) && ctype_digit($item))) {
            $related = DataObject::get_by_id($relationClass, (int) $item);
        } elseif (is_array($item) && isset($item['id'])) {
            $related = DataObject::get_by_id($relationClass, (int) $item['id']);
        } elseif (is_array($item) && isset($item['externalId'])) {
            $related = $this->externalIds->find($relationClass, (string) $item['externalId']);
        } else {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf(
                    'Relation "%s" items must be an integer ID, {"id": n} or {"externalId": "..."}.',
                    $relationName
                )
            );
        }

        if (!$related) {
            throw new ApiError(
                ErrorCode::NOT_FOUND,
                sprintf('Related %s not found for relation "%s".', $relationClass, $relationName)
            );
        }

        return [$related, $extraFields];
    }
}
