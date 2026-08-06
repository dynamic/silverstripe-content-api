<?php

namespace Dynamic\ContentApi\Write;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Registry\ClassRegistry;
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
 *   (relations still gate through `api_writable_relations`). A class also
 *   enters allowlist mode implicitly the moment it declares a non-empty
 *   `api_writable_fields`, even under the `guarded` policy — declaring the
 *   allowlist is itself the opt-in, on every write surface (colymba /api,
 *   batch, compositions). There is no configuration that declares
 *   `api_writable_fields` and has it silently ignored.
 *
 * A small, separate trusted channel (`applyFields()`'s `$internalFields`
 * param) lets in-process population machinery (e.g. `CompositionService`)
 * write server-derived structural fields — like an element's `ParentID` —
 * without adding them to `api_writable_fields`, which would also expose them
 * on the untrusted colymba PUT surface. The `protected_fields` /
 * `api_protected_fields` denylist still applies to trusted writes; only the
 * allowlist check is bypassed. Nothing derived from request input may be
 * passed as `$internalFields`.
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
        'registry' => '%$' . ClassRegistry::class,
    ];

    public ?ExternalIdResolver $externalIds = null;

    public ?ClassRegistry $registry = null;

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
     * `$internalFields` is a separate, trusted channel for server-derived
     * values the population machinery writes on the caller's behalf (e.g. a
     * composition element's `ParentID`) — it bypasses the `api_writable_fields`
     * allowlist (the protected-field denylist still applies) and always wins
     * over a same-named key in `$fields`, so a client can't smuggle in its own
     * value for a structural field under that name. Only in-process module
     * code should ever populate it; request-derived payloads must never flow
     * into this parameter.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $internalFields
     * @throws ApiError when any field fails writability — nothing is applied
     */
    public function applyFields(DataObject $record, array $fields, array $internalFields = []): void
    {
        $this->warnings = [];
        $schema = DataObject::getSchema();
        $className = get_class($record);
        $hasOne = (array) $record->hasOne();
        $problems = [];
        $plan = [];

        $combined = array_merge($fields, $internalFields);

        foreach ($combined as $name => $value) {
            $isHasOne = isset($hasOne[$name]);
            $isHasOneFk = str_ends_with($name, 'ID') && isset($hasOne[substr($name, 0, -2)]);
            $polymorphicClassRelation = $this->polymorphicClassColumnRelation($name, $hasOne);
            $polymorphicMultiRelation = $this->polymorphicRelationColumnRelation($name, $className, $hasOne);
            $isDbField = $schema->fieldSpec($className, $name) !== null;
            $trusted = isset($internalFields[$name]);

            // A polymorphic has_one's companion {Name}Class column is only
            // ever set directly by request-derived input as a side effect
            // of resolving its relation key (see resolveRelation() below),
            // never as a bare payload key — otherwise a client could set an
            // arbitrary raw class name with no ClassRegistry/
            // resolveRelation() validation at all, independently of the
            // FK's own writability (#25). Trusted in-process code (the same
            // channel that already writes ParentID/Sort) may still set it
            // directly, same as any other trusted field.
            if ($polymorphicClassRelation !== null && !$trusted) {
                $problems[] = [
                    'field' => $name,
                    'code' => ErrorCode::READONLY_FIELD->value,
                    'message' => sprintf(
                        'Field "%s" can only be set via its relation ("%s") with an explicit class hint,'
                            . ' not directly.',
                        $name,
                        $polymorphicClassRelation
                    ),
                ];
                continue;
            }

            // A *multirelational* polymorphic has_one's companion
            // {Name}Relation column disambiguates which reciprocal has_many
            // a record belongs to — this module's write path has no
            // mechanism to determine the correct value for it (that would
            // require the client to name a specific has_many, a feature
            // this API doesn't yet expose), so it's never a settable
            // payload key at all, not even for trusted internal code. #34.
            if ($polymorphicMultiRelation !== null) {
                $problems[] = [
                    'field' => $name,
                    'code' => ErrorCode::READONLY_FIELD->value,
                    'message' => sprintf(
                        'Field "%s" is not writable via the content API.',
                        $name
                    ),
                ];
                continue;
            }

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

            // Both the relation-name key ("Owner") and the raw FK-column key
            // ("OwnerID") route through the same has_one handling below — a
            // relation named only by its FK column must not bypass the
            // polymorphic class-hint requirement resolveRelation() enforces.
            $relationName = match (true) {
                $isHasOne => $name,
                $isHasOneFk => substr($name, 0, -2),
                default => null,
            };
            $columnName = $isHasOne ? $name . 'ID' : $name;

            // An `ElementalArea`-type has_one FK is never directly
            // client-writable, on any class other than the `BaseElement`
            // itself whose own "Parent" relation this points at — the
            // framework auto-provisions this FK (`ElementalAreasExtension::
            // onBeforeWrite()`), and a client repointing e.g. a page's own
            // area FK onto a different, already-populated area would relink
            // that area's whole existing element tree without any of those
            // elements individually passing `ElementPlacementPolicy` (#64) —
            // `RecordWriter::assertElementPlacementAllowed()` only ever
            // inspects the record actually being written, not an area's
            // pre-existing contents. Unconditional (not gated on $trusted):
            // no in-process caller needs this either — `CompositionService::
            // resolveArea()` sets this FK via `setField()` directly, bypassing
            // `applyFields()` entirely.
            if (
                $relationName !== null
                && !is_a($record, 'DNADesign\\Elemental\\Models\\BaseElement')
                && class_exists('DNADesign\\Elemental\\Models\\ElementalArea')
                && is_a((string) ($hasOne[$relationName] ?? ''), 'DNADesign\\Elemental\\Models\\ElementalArea', true)
            ) {
                $problems[] = [
                    'field' => $name,
                    'code' => ErrorCode::READONLY_FIELD->value,
                    'message' => sprintf(
                        'Field "%s" is not writable via the content API — an Elemental area is managed'
                            . ' automatically, never repointed directly.',
                        $name
                    ),
                ];
                continue;
            }

            if (!$this->isFieldWritable($className, $columnName, $relationName, $trusted)) {
                $problems[] = [
                    'field' => $name,
                    'code' => ErrorCode::READONLY_FIELD->value,
                    'message' => sprintf('Field "%s" is not writable via the content API.', $name),
                ];
                continue;
            }

            // A polymorphic has_one's companion {Name}Class column is a
            // second column this same payload key writes to (see below) —
            // it must be independently gated too, not silently write
            // alongside the FK for free just because the FK passed (#25).
            // The FK itself was already confirmed writable just above, so
            // only the Class column needs checking here.
            if (
                $relationName !== null
                && ($hasOne[$relationName] ?? null) === DataObject::class
                && !$this->isFieldWritable($className, $relationName . 'Class', $relationName, $trusted)
            ) {
                $problems[] = [
                    'field' => $name,
                    'code' => ErrorCode::READONLY_FIELD->value,
                    'message' => sprintf('Field "%s" is not writable via the content API.', $relationName . 'Class'),
                ];
                continue;
            }

            $plan[] = [$name, $columnName, $relationName, $value];
        }

        if ($problems !== []) {
            throw new ApiError(
                ErrorCode::from($problems[0]['code']),
                sprintf('%d field(s) rejected.', count($problems)),
                $problems
            );
        }

        foreach ($plan as [$name, $columnName, $relationName, $value]) {
            $value = $this->transformValue($record, $name, $value);

            if ($relationName !== null) {
                $resolved = $this->resolveRelation($relationName, $hasOne[$relationName], $value);
                $record->setField($columnName, $resolved['id']);

                // Polymorphic has_ones (declared `'Relation' => DataObject::class`)
                // need the companion `{Name}Class` column written alongside the
                // FK — the ID alone can't be dereferenced on read. Non-polymorphic
                // relations never touch this column.
                if ($resolved['polymorphic']) {
                    $record->setField($relationName . 'Class', $resolved['class']);

                    // A multirelational polymorphic has_one's {Name}Relation
                    // column is never set by this write (see
                    // polymorphicRelationColumnRelation()'s docblock) — the
                    // record won't appear via its reciprocal has_many side
                    // until that column is set some other way. Surface this
                    // the same way a lenient unknown-field write already
                    // does, rather than reporting an unqualified "created"/
                    // "updated" that looks fully functional (#34).
                    if ($schema->hasOneComponentHandlesMultipleRelations($className, $relationName)) {
                        $this->warnings[] = [
                            'code' => ErrorCode::FEATURE_UNAVAILABLE->value,
                            'message' => sprintf(
                                'Field "%s" is set, but this record will not appear via its reciprocal '
                                    . 'has_many relation until "%sRelation" is also set — this API has no way '
                                    . 'to write that column yet.',
                                $name,
                                $relationName
                            ),
                            'field' => $name,
                        ];
                    }
                }
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

                // A many_many through spec's 'to' is the *name* of a
                // has_one on the join class, not a class name (framework
                // DataObjectSchema::parseManyManyComponent()) — resolve the
                // actual target class via the schema helper rather than
                // reading ['to'] as if it were one.
                $relationClass = is_array($classes)
                    ? (DataObject::getSchema()->manyManyComponent($className, $name)['childClass'] ?? null)
                    : $classes;
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
     * The single source of truth for field writability across every write
     * surface (colymba /api, batch, compositions).
     *
     * A class is in allowlist mode when it declares `api_write_policy:
     * allowlist` (or the global `policy` is `allowlist`), OR when it declares
     * a non-empty `api_writable_fields` at all — the allowlist itself is the
     * opt-in, so there is no configuration where `api_writable_fields` is set
     * but silently has no effect. Both paths share the protected-field
     * denylist and the allowlist matching by column name OR relation name.
     *
     * `$trusted` is for server-derived fields the population machinery writes
     * on a caller's behalf (e.g. a composition's `ParentID`) — it is set by
     * in-process module code only, never from request input, so it is safe
     * to bypass the allowlist for it. The protected-field denylist is an
     * absolute floor and still applies even when `$trusted` is true.
     */
    public function isFieldWritable(
        string $className,
        string $columnName,
        ?string $relationName = null,
        bool $trusted = false
    ): bool {
        $protected = array_merge(
            (array) static::config()->get('protected_fields'),
            (array) Config::inst()->get($className, 'api_protected_fields')
        );

        if (in_array($columnName, $protected, true) || ($relationName && in_array($relationName, $protected, true))) {
            return false;
        }

        if ($trusted) {
            return true;
        }

        $policy = Config::inst()->get($className, 'api_write_policy')
            ?: static::config()->get('policy');

        $allowed = (array) Config::inst()->get($className, 'api_writable_fields');
        $allowlistMode = $policy === 'allowlist' || $allowed !== [];

        if (!$allowlistMode) {
            return true;
        }

        return in_array($columnName, $allowed, true)
            || ($relationName && in_array($relationName, $allowed, true));
    }

    /**
     * Whether a polymorphic has_one's FK and companion {Name}Class column
     * are BOTH writable — the pair is gated as a single unit, not two
     * independent columns (#25). Every caller that needs to know whether a
     * polymorphic relation itself can be written (WriteApplicator's own
     * apply loop, SchemaService's advertised writability, WriteGuardExtension's
     * revert guard) shares this one decision rather than each re-deriving
     * the same FK-AND-Class check.
     */
    public function isPolymorphicRelationWritable(string $className, string $relationName, bool $trusted = false): bool
    {
        return $this->isFieldWritable($className, $relationName . 'ID', $relationName, $trusted)
            && $this->isFieldWritable($className, $relationName . 'Class', $relationName, $trusted);
    }

    /**
     * Whether $columnName is the companion {Name}Class column of a
     * polymorphic has_one declared in $hasOne — the naming-convention
     * detection this class, SchemaService and WriteGuardExtension all
     * independently need (#25), in one place.
     *
     * @param array<string, string> $hasOne as returned by DataObject::hasOne()
     * @return ?string the relation name if $columnName is its companion
     *   Class column, null otherwise
     */
    public function polymorphicClassColumnRelation(string $columnName, array $hasOne): ?string
    {
        return $this->polymorphicCompanionColumnRelation($columnName, 'Class', $hasOne);
    }

    /**
     * Whether $columnName is the companion {Name}Relation column a
     * *multirelational* polymorphic has_one gets in addition to {Name}ID/
     * {Name}Class (`DBPolymorphicRelationAwareForeignKey`'s extra
     * `Relation` composite field — the string SilverStripe itself uses to
     * disambiguate which reciprocal has_many a record belongs to when more
     * than one shares the same polymorphic has_one). $hasOne (from
     * `DataObject::hasOne()`) has already been normalized to a bare class
     * string by the framework and no longer carries the `multirelational`
     * flag, so this checks the schema directly. #34.
     */
    public function polymorphicRelationColumnRelation(string $columnName, string $className, array $hasOne): ?string
    {
        return $this->polymorphicCompanionColumnRelation($columnName, 'Relation', $hasOne, $className);
    }

    /**
     * Shared naming-convention detection for both companion-column checks
     * above: strip $suffix from $columnName and confirm the remainder is a
     * polymorphic has_one in $hasOne. $className/multirelational-only is
     * required for the 'Relation' suffix (only a multirelational has_one
     * gets that column); omitted for 'Class' (every polymorphic has_one
     * gets that one).
     *
     * A column whose stripped name is *itself* a distinct, genuinely
     * declared has_one relation (e.g. a class with both a multirelational
     * 'Owner' and an unrelated has_one literally named 'OwnerRelation')
     * never matches — the real relation always wins over the naming-
     * convention guess, which exists only to catch the synthetic companion
     * column, not to shadow a legitimately named one.
     *
     * A plain `$db` field sharing the exact same name (e.g. a class
     * declaring both the 'Owner' multirelational has_one and its own
     * `'OwnerRelation' => 'Varchar'` db field) isn't a case this needs to
     * disambiguate: `DataObjectSchema::databaseFields()` merges has_one-
     * derived composite columns *after* plain `$db` fields, so the
     * synthetic column unconditionally wins in the schema itself before
     * this method ever runs — there's no independently-meaningful "genuine
     * field" left at this layer to protect.
     */
    private function polymorphicCompanionColumnRelation(
        string $columnName,
        string $suffix,
        array $hasOne,
        ?string $className = null
    ): ?string {
        if (!str_ends_with($columnName, $suffix) || isset($hasOne[$columnName])) {
            return null;
        }

        $relationName = substr($columnName, 0, -strlen($suffix));

        if (($hasOne[$relationName] ?? null) !== DataObject::class) {
            return null;
        }

        if ($className === null) {
            return $relationName;
        }

        return DataObject::getSchema()->hasOneComponentHandlesMultipleRelations($className, $relationName)
            ? $relationName
            : null;
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
     * Resolve a has_one payload value to a foreign key ID (and, for a
     * polymorphic has_one, the concrete target class). `$relationClass` is
     * the relation's declared has_one target — the caller already has it
     * (from `$record->hasOne()`) so this doesn't re-derive it via schema.
     *
     * A non-polymorphic has_one (the common case) accepts an integer ID,
     * null, {"id": n} or {"externalId": "..."} — unchanged from before.
     *
     * A polymorphic has_one (declared `'Relation' => DataObject::class`) has
     * no single target class to fall back on, so the class isn't derivable
     * from the schema the way it is for a normal relation — the caller MUST
     * say which class the ID/externalId belongs to via an explicit "class"
     * hint: {"class": "...", "id": n} or {"class": "...", "externalId": "..."}.
     * "class" is a short registry ref (see ClassRegistry::resolve()), the
     * same convention CompositionService already uses for a child's class
     * override. A bare int or a hint-less {"id"/"externalId"} on a
     * polymorphic relation is rejected as ambiguous, rather than reaching
     * DataObject::get_by_id(DataObject::class, ...) downstream and 500ing.
     *
     * Public: also called by WriteGuardExtension to translate a polymorphic
     * has_one's `{"class","id"}` payload shape into raw FK + Class column
     * writes before colymba's own deserializer (which has no concept of
     * either) applies the payload on its native `/api` surface (#23).
     *
     * @return array{id: int, class: ?string, polymorphic: bool}
     */
    public function resolveRelation(string $relationName, string $relationClass, mixed $value): array
    {
        $isPolymorphic = $relationClass === DataObject::class;

        if ($value === null) {
            return ['id' => 0, 'class' => null, 'polymorphic' => $isPolymorphic];
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            if ($isPolymorphic) {
                throw new ApiError(
                    ErrorCode::PAYLOAD_INVALID,
                    sprintf(
                        'Relation "%s" is polymorphic; a bare ID is ambiguous — use '
                        . '{"class": "...", "id": n}.',
                        $relationName
                    )
                );
            }

            return ['id' => (int) $value, 'class' => null, 'polymorphic' => false];
        }

        if (is_array($value)) {
            $targetClass = $isPolymorphic
                ? $this->registry->resolvePolymorphicHint($relationName, $value)
                : $relationClass;

            if (isset($value['id'])) {
                return [
                    'id' => (int) $value['id'],
                    'class' => $isPolymorphic ? $targetClass : null,
                    'polymorphic' => $isPolymorphic,
                ];
            }

            if (isset($value['externalId']) && $targetClass) {
                $found = $this->externalIds->find($targetClass, (string) $value['externalId']);

                return [
                    'id' => (int) $found->ID,
                    'class' => $isPolymorphic ? $targetClass : null,
                    'polymorphic' => $isPolymorphic,
                ];
            }
        }

        throw new ApiError(
            ErrorCode::PAYLOAD_INVALID,
            sprintf(
                'Relation "%s" accepts an integer ID, null, {"id": n} or {"externalId": "..."}%s.',
                $relationName,
                $isPolymorphic ? ', with an explicit "class"' : ''
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
