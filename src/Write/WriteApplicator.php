<?php

namespace Dynamic\ContentApi\Write;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Registry\ClassRegistry;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\HTMLEditor\HTMLEditorConfig;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorSanitiser;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBEnum;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\ORM\FieldType\DBMultiEnum;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\HTMLValue;

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
 * on the untrusted colymba PUT surface. The global `protected_fields` floor
 * still applies to trusted writes; the allowlist and the class's
 * `api_protected_fields` are bypassed. Nothing derived from request input
 * may be passed as `$internalFields`.
 */
class WriteApplicator
{
    use Configurable;
    use Injectable;

    private static string $policy = 'guarded';

    /**
     * Sanitise HTML-typed field writes like a CMS save does — see
     * sanitiseHtml(). Set false to store API-written HTML raw.
     */
    private static bool $sanitise_html = true;

    /**
     * The HTMLEditorConfig identifier whose element rules sanitising uses.
     * `cms` is the config a CMS edit form saves through; outside the CMS the
     * active config is `default`, which declares no element rules and would
     * make sanitising a no-op.
     */
    private static string $html_editor_config = 'cms';

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
     * allowlist and the class's `api_protected_fields` (the global
     * `protected_fields` floor still applies) and always wins
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

            // A DBEnum field accepts any string SilverStripe's own ORM would
            // — including one outside the declared value list — because
            // DBEnum::setValue() never validates; only the DB column's own
            // ENUM constraint would eventually reject it, and MySQL doesn't
            // reject an out-of-list ENUM value either, it silently coerces
            // it to the empty string. A client sending the wrong case
            // (`"Image"` instead of the schema's own `"image"`) got a 200
            // and a permanently unrendered field with no signal anything
            // was wrong — confirmed live (46 elements, essentials
            // project). `SchemaService` already advertises the real value
            // list via the same `enumValues()` call; this is the write-side
            // half of the same contract. `isEnumValueAcceptable()` is the
            // shared decision this and `WriteGuardExtension` (colymba's
            // generic /api surface, which never routes through here) both
            // need — an out-of-list value must not be accepted on either
            // write surface.
            //
            // This runs against the pre-`transformValue()` value — the
            // apply loop below transforms afterward, once, only for the
            // (validated) plan. A project registering a custom
            // `transformers` entry against an Enum-backed field is
            // responsible for the transformed value's own validity: this
            // check can't safely run after transformation, since
            // transforming twice would double any transformer with a side
            // effect (e.g. `LinkTransformer` writes a DB record) — no
            // shipped transformer targets an Enum column today, so this
            // isn't currently reachable, but it's a real constraint on any
            // future one.
            if (
                $relationName === null
                && $isDbField
                && !$this->isEnumValueAcceptable($record, $columnName, $value)
            ) {
                // Re-checked (not just re-cast) for PHPStan's benefit —
                // isEnumValueAcceptable() returning false already
                // guarantees this is a DBEnum, but that fact isn't visible
                // to static analysis across the method boundary.
                $dbObject = $record->dbObject($columnName);
                $enumValues = $dbObject instanceof DBEnum ? $dbObject->enumValues() : [];
                $problems[] = [
                    'field' => $name,
                    'code' => ErrorCode::INVALID_VALUE->value,
                    'message' => sprintf(
                        'Field "%s" must be one of: %s. Got "%s".',
                        $name,
                        implode(', ', $enumValues),
                        (string) $value
                    ),
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

            if ($relationName === null) {
                $sanitised = $this->sanitiseHtml($record, $columnName, $value);

                // A CMS save strips markup silently; an API caller (usually
                // an agent) is told, so a write that lost content doesn't
                // read as a clean success. Compared against the input after
                // the same HTML parse, so a pure serialisation change
                // (attribute quoting, <br/> vs <br>) isn't reported.
                if (
                    $sanitised !== $value
                    && is_string($value)
                    && $sanitised !== HTMLValue::create($value)->getContent()
                ) {
                    $this->warnings[] = [
                        'code' => ErrorCode::VALUE_SANITISED->value,
                        'message' => sprintf(
                            'Field "%s" contained markup the "%s" editor config does not allow; it was removed.',
                            $name,
                            static::config()->get('html_editor_config')
                        ),
                        'field' => $name,
                    ];
                }

                $value = $sanitised;
            }

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
     * Whether a scalar value is acceptable for `$columnName`, when that
     * column is Enum-backed — the shared decision `applyFields()`'s
     * reject-with-message path above and `WriteGuardExtension`'s
     * revert-on-invalid-value path (colymba's generic /api surface, which
     * never routes through `applyFields()`) both need, so an out-of-list
     * enum write is never silently accepted on either write surface.
     *
     * Returns true (nothing to say) for a column that isn't Enum-backed at
     * all, for null/empty (clearing a field is always allowed), and for a
     * non-scalar value — an array/object payload for a plain column is a
     * type problem, not an enum-value problem; it isn't this check's job to
     * reject it (confirmed: `DataObject::setFieldValue()`'s own
     * `scalarValueOnly()` guard already rejects a non-scalar for any
     * scalar-typed column, Enum or not, independently of this check — a
     * pre-existing gap in this class's own "validate everything before
     * writing anything" contract, since that guard only fires once the
     * apply loop actually calls `setCastedField()`, not during this
     * validation pass; out of scope here since it isn't specific to enum
     * values).
     *
     * `DBMultiEnum` (SilverStripe's `set`-backed multi-select Enum
     * subclass) stores a comma-joined list of independently-valid values
     * rather than one — `enumValues()` still returns the individual
     * options, so validating the whole joined string against it directly
     * would reject every legitimate multi-value write.
     */
    public function isEnumValueAcceptable(DataObject $record, string $columnName, mixed $value): bool
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return true;
        }

        $dbObject = $record->dbObject($columnName);

        if (!$dbObject instanceof DBEnum) {
            return true;
        }

        $enumValues = $dbObject->enumValues();

        if ($dbObject instanceof DBMultiEnum) {
            foreach (explode(',', (string) $value) as $candidate) {
                $candidate = trim($candidate);

                if ($candidate !== '' && !in_array($candidate, $enumValues, true)) {
                    return false;
                }
            }

            return true;
        }

        return in_array((string) $value, $enumValues, true);
    }

    /**
     * Validates every key/spec in a `relations` payload (has_many /
     * many_many only) without applying anything — safe, and intended, to
     * call before the record itself has been written.
     *
     * `RecordWriter::write()` calls this ahead of `$record->write()`
     * specifically so a has_one relation named under `relations` — a
     * natural mistake, since "Parent" reads like a relation to a caller,
     * but this module's `relations` payload is has_many/many_many only; a
     * has_one belongs under `fields` — is rejected with an actionable
     * message instead of being silently dropped while the record's own
     * validation then fails on the FK it never received, for an unrelated-
     * looking reason (confirmed live: a `BlogPost` create with
     * `relations: {"Parent": 74}` 422'd "not allowed on the root level"
     * with no indication `Parent` itself was the problem — #191).
     * `applyRelations()` calls this at its own entry too, so it stays safe
     * for any other caller.
     *
     * @param array<string, mixed> $relations
     * @throws ApiError
     */
    public function assertRelationsValid(DataObject $record, array $relations): void
    {
        $className = get_class($record);
        $hasOne = (array) $record->hasOne();
        $hasMany = (array) $record->hasMany();
        $manyMany = (array) $record->manyMany();
        $writable = (array) Config::inst()->get($className, 'api_writable_relations');

        foreach ($relations as $name => $spec) {
            $this->validateRelationSpec($className, (string) $name, $spec, $hasOne, $hasMany, $manyMany, $writable);
        }
    }

    /**
     * Apply `relations` payload (has_many / many_many) to a saved record.
     *
     * Payload per relation: `{ "mode": "set|add|remove", "items": [ ... ] }`
     * where each item is an int ID, `{"id": n}` or `{"externalId": "..."}`,
     * optionally with `"extraFields"` for many_many.
     *
     * #202: a has_many relation write (`add`, `remove`, or the `removeAll()`
     * half of `set`) unconditionally writes the FAR SIDE of the relation to
     * repoint or clear its foreign key — an ordinary draft write, regardless
     * of that record's own Versioned state. Left unaddressed, an
     * already-published, otherwise-clean record gets silently left
     * `modifiedOnDraft` (attach) or, worse, left LIVE-attached to a page it
     * was just draft-detached from (`remove`/`set`'s removeAll half — the
     * live site keeps rendering it under the old parent while draft says
     * it's gone). `ManyManyList::add()` has no equivalent gap for the
     * related record ITEM: it only calls `write()` on it when it isn't
     * already in the database, so an existing (already write()-once) item
     * is never touched by it at all. (`ManyManyThroughList::add()` can
     * still write the JOIN record when `extraFields` are supplied against
     * an already-existing join row — out of scope here, since that's a
     * write to the join class, not to `$related` itself, and this
     * module's own join stub is a plain, non-Versioned `DataObject`.)
     *
     * Only a record that was published AND NOT already `modifiedOnDraft`
     * going in is collected — `isPublished()` alone is true for a
     * record that's live but also mid-edit with unrelated unpublished
     * draft changes, and republishing that would force an editor's
     * in-progress draft to LIVE as a side effect of an unrelated relation
     * write, not merely restore what this operation itself disturbed.
     *
     * `$authorize`, when given, is called as `$authorize($farSide, $verb)`
     * for every record on the far side of a relation write, before ANY list
     * is mutated (#235): `update` for a has_many item (its foreign key is
     * written) and for every existing child a has_many `set` will clear,
     * `read` for a many_many item (only the join row changes). It throws to
     * refuse. `RecordWriter::write()` passes PermissionPolicy::authorize().
     *
     * @param array<string, mixed> $relations
     * @param (callable(DataObject, string): void)|null $authorize
     * @return DataObject[] Deduplicated (by class + id) — see this method's
     *   own docblock above for exactly which records qualify. The caller
     *   (`RecordWriter::write()`) authorization-checks and republishes each
     *   one to close #202.
     * @throws ApiError
     */
    public function applyRelations(DataObject $record, array $relations, ?callable $authorize = null): array
    {
        $className = get_class($record);
        $hasOne = (array) $record->hasOne();
        $hasMany = (array) $record->hasMany();
        $manyMany = (array) $record->manyMany();
        $writable = (array) Config::inst()->get($className, 'api_writable_relations');
        $dirtiedPublished = [];
        $plan = [];

        // Pass 1: validate and resolve everything, and authorize every far-
        // side record, before any list is touched — so a refusal on the last
        // item can't leave earlier ones already attached.
        foreach ($relations as $name => $spec) {
            $name = (string) $name;
            $relationClass = $this->validateRelationSpec(
                $className,
                $name,
                $spec,
                $hasOne,
                $hasMany,
                $manyMany,
                $writable
            );
            $mode = (string) $spec['mode'];
            $isHasMany = isset($hasMany[$name]);
            $resolved = [];

            foreach ((array) ($spec['items'] ?? []) as $item) {
                $resolved[] = $this->resolveRelationItem($relationClass, $name, $item);
            }

            $existing = $mode === 'set' && $isHasMany ? iterator_to_array($record->{$name}()) : [];

            if ($authorize !== null) {
                foreach ($existing as $child) {
                    $authorize($child, 'update');
                }

                foreach ($resolved as [$related]) {
                    $authorize($related, $isHasMany ? 'update' : 'read');
                }
            }

            $plan[] = [$name, $mode, $isHasMany, $resolved, $existing];
        }

        // Pass 2: apply.
        foreach ($plan as [$name, $mode, $isHasMany, $resolved, $existing]) {
            $list = $record->{$name}();

            if ($mode === 'set') {
                foreach ($existing as $child) {
                    $this->collectIfCleanlyPublished($child, $dirtiedPublished);
                }

                $list->removeAll();
            }

            foreach ($resolved as [$related, $extraFields]) {
                if ($mode === 'remove') {
                    if ($isHasMany) {
                        $this->collectIfCleanlyPublished($related, $dirtiedPublished);
                    }

                    $list->remove($related);
                    continue;
                }

                if ($isHasMany) {
                    // Pass 1 loaded this record before `set`'s removeAll()
                    // nulled foreign keys in the database. A stale copy still
                    // holding the old ParentID would make add()'s write a
                    // no-op (nothing "changed"), silently leaving a child
                    // that was both existing and requested detached. Reload.
                    $related = DataObject::get_by_id(get_class($related), (int) $related->ID) ?: $related;
                    $this->collectIfCleanlyPublished($related, $dirtiedPublished);
                }

                $extraFields === [] ? $list->add($related) : $list->add($related, $extraFields);
            }
        }

        return array_values($dirtiedPublished);
    }

    /**
     * Records into `$dirtiedPublished` (keyed by class+id to dedupe a
     * record reached more than once, e.g. named under both a `remove` and
     * a later `add` in the same request) when `$related` was published AND
     * NOT already `modifiedOnDraft` before this relation write touches it
     * — see `applyRelations()`'s own docblock for why that second
     * condition matters.
     *
     * @param array<string, DataObject> $dirtiedPublished
     */
    private function collectIfCleanlyPublished(DataObject $related, array &$dirtiedPublished): void
    {
        if (
            !$related->hasExtension(Versioned::class)
            || !$related->isPublished()
            || $related->isModifiedOnDraft()
        ) {
            return;
        }

        $dirtiedPublished[get_class($related) . ':' . $related->ID] = $related;
    }

    /**
     * Shared validation for a single `relations` entry — everything
     * `applyRelations()` and `assertRelationsValid()` both need to decide
     * before an item is ever resolved. Returns the relation's target class
     * (dot-notation suffix already stripped) for the caller that goes on to
     * apply it; the validate-only caller just discards it.
     *
     * @throws ApiError
     */
    private function validateRelationSpec(
        string $className,
        string $name,
        mixed $spec,
        array $hasOne,
        array $hasMany,
        array $manyMany,
        array $writable
    ): string {
        // A has_one name (or its own FK-suffixed key) under `relations` is
        // always a client mistake — has_one FKs are written via `fields`,
        // never `relations` — and must be rejected here, before the caller
        // ever reaches `$record->write()`, or the has_one silently stays
        // untouched while an unrelated-looking validation error (or none at
        // all) follows.
        if (isset($hasOne[$name]) || (str_ends_with($name, 'ID') && isset($hasOne[substr($name, 0, -2)]))) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf(
                    '"%s" is a has_one relation on %s — pass it under "fields", not "relations".',
                    $name,
                    $className
                )
            );
        }

        $relationClass = null;

        if (isset($hasMany[$name])) {
            $relationClass = $hasMany[$name];
        } elseif (isset($manyMany[$name])) {
            $classes = $manyMany[$name];

            // A many_many through spec's 'to' is the *name* of a has_one on
            // the join class, not a class name (framework
            // DataObjectSchema::parseManyManyComponent()) — resolve the
            // actual target class via the schema helper rather than reading
            // ['to'] as if it were one.
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

        if (!in_array($mode, ['set', 'add', 'remove'], true)) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Relation mode "%s" must be set, add or remove.', $mode)
            );
        }

        // Strip the relation-class suffix from dot notation (has_many keys
        // may be Class.FKField).
        return strtok((string) $relationClass, '.');
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
     * to bypass the allowlist and the class's `api_protected_fields` for it.
     * The global `protected_fields` denylist is an absolute floor and still
     * applies even when `$trusted` is true.
     */
    public function isFieldWritable(
        string $className,
        string $columnName,
        ?string $relationName = null,
        bool $trusted = false
    ): bool {
        // A class's own api_protected_fields restricts what CLIENTS may write.
        // The trusted channel carries server-derived values (an element's
        // ParentID, and Sort when the client sent none) that a project has no
        // reason to block; a guarded-policy project typically lists
        // ParentID/Parent there to keep the link off the client surfaces
        // (#233). The module's protected_fields floor still applies to
        // trusted writes.
        $protected = (array) static::config()->get('protected_fields');
        if (!$trusted) {
            $protected = array_merge($protected, (array) Config::inst()->get($className, 'api_protected_fields'));
        }

        if (in_array($columnName, $protected, true) || ($relationName && in_array($relationName, $protected, true))) {
            return false;
        }

        // An `ElementalArea`-type has_one FK is never directly
        // client-writable — the framework auto-provisions it
        // (`ElementalAreasExtension::onBeforeWrite()`), and repointing it
        // onto a different, already-populated area would relink that
        // area's whole existing element tree onto this record without any
        // of those elements passing `RecordWriter::
        // assertElementPlacementAllowed()` (#64), which only ever inspects
        // the record actually being written. An absolute floor like
        // `protected_fields` above — not gated on `$trusted`, since no
        // in-process caller needs this either (`CompositionService::
        // resolveArea()` sets this FK via `setField()` directly, bypassing
        // this method entirely). This is `isFieldWritable()`'s own
        // docblock's "single source of truth... colymba /api, batch,
        // compositions" — putting the rule here, not in `applyFields()`
        // alone, is what makes it actually apply to all three, plus
        // `SchemaService`'s advertised writability.
        //
        // The one exemption is a `BaseElement`'s own `Parent` relation to
        // its area — that FK is exactly the placement this module's #64
        // enforcement is built to check, not to block outright. A SECOND
        // `ElementalArea`-typed has_one on an element subclass (e.g. a
        // nested-area pattern) is deliberately NOT exempt — only the one
        // relation `assertElementPlacementAllowed()` actually inspects.
        if (
            $relationName !== null
            && !($relationName === 'Parent' && is_a($className, 'DNADesign\\Elemental\\Models\\BaseElement', true))
            && class_exists('DNADesign\\Elemental\\Models\\ElementalArea')
            && is_a(
                (string) DataObject::getSchema()->hasOneComponent($className, $relationName),
                'DNADesign\\Elemental\\Models\\ElementalArea',
                true
            )
        ) {
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

    /**
     * Run an HTML-typed field's value (`DBHTMLText`/`DBHTMLVarchar`) through
     * the configured HTMLEditorSanitiser, so an API write stores what a CMS
     * save of the same value would (#225): `HTMLEditorField::saveInto()`
     * sanitises against the editor config (`html_editor_config`, `cms` by
     * default — what a CMS edit form uses), and nothing else on a
     * write path does. Without it a token with `update` on a class with an
     * HTML field can put script into a public page, since templates render
     * these fields raw.
     *
     * On by default. Off when either this class's `sanitise_html` or the
     * CMS's own `HTMLEditorField.sanitise_server_side` is false, so a project
     * that disabled CMS sanitising gets the same raw storage here. Public:
     * WriteGuardExtension applies the same step to colymba's `/api` writes.
     */
    public function sanitiseHtml(DataObject $record, string $columnName, mixed $value): mixed
    {
        if (
            !is_string($value)
            || $value === ''
            || !static::config()->get('sanitise_html')
            || !HTMLEditorField::config()->get('sanitise_server_side')
        ) {
            return $value;
        }

        $field = $record->dbObject($columnName);

        if (!$field instanceof DBHTMLText && !$field instanceof DBHTMLVarchar) {
            return $value;
        }

        $sanitiser = $this->htmlSanitiser();
        $html = HTMLValue::create($value);
        $sanitiser->sanitise($html);

        return $html->getContent();
    }

    /**
     * The sanitiser for `html_editor_config`, refused if that config can't
     * do the job. A config with no element rules fails silently in the
     * framework: on SS5 an unknown identifier gets an empty config whose
     * sanitiser strips nothing (script stored raw), and an emptied rule set
     * can unwrap every element (content lost). A probe through the sanitiser
     * catches both, so a misconfiguration refuses the write instead.
     *
     * @throws ApiError SERVER_ERROR
     */
    protected function htmlSanitiser(): HTMLEditorSanitiser
    {
        $identifier = (string) static::config()->get('html_editor_config');
        $sanitiser = HTMLEditorSanitiser::create(HTMLEditorConfig::get($identifier));

        if (!isset($this->sanitiserVerified[$identifier])) {
            $probe = HTMLValue::create('<p>probe</p><script>probe()</script>');
            $sanitiser->sanitise($probe);
            $result = $probe->getContent();

            if (str_contains($result, '<script') || !str_contains($result, '<p>')) {
                throw new ApiError(
                    ErrorCode::SERVER_ERROR,
                    sprintf(
                        'HTML sanitising is on, but editor config "%s" has no usable element rules, so the '
                            . 'write was refused. Set WriteApplicator.html_editor_config to a configured editor '
                            . '(usually "cms"), or turn sanitise_html off.',
                        $identifier
                    )
                );
            }

            $this->sanitiserVerified[$identifier] = true;
        }

        return $sanitiser;
    }

    /**
     * @var array<string, true> editor config identifiers already probed
     */
    private array $sanitiserVerified = [];

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

            $this->assertRelationTargetExists($relationName, $relationClass, (int) $value);

            return ['id' => (int) $value, 'class' => null, 'polymorphic' => false];
        }

        if (is_array($value)) {
            $targetClass = $isPolymorphic
                ? $this->registry->resolvePolymorphicHint($relationName, $value)
                : $relationClass;

            if (isset($value['id'])) {
                $this->assertRelationTargetExists($relationName, $targetClass, (int) $value['id']);

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
     * A has_one integer or `{"id": n}` must name a record that exists, as
     * the `{"externalId"}` shape and has_many/many_many items already must
     * (#235); 0 stays the "clear the relation" value. Looked up on the draft
     * stage, where every write path in this module writes.
     *
     * @throws ApiError NOT_FOUND
     */
    protected function assertRelationTargetExists(string $relationName, string $targetClass, int $id): void
    {
        if ($id === 0) {
            return;
        }

        $found = Versioned::withVersionedMode(function () use ($targetClass, $id) {
            Versioned::set_stage(Versioned::DRAFT);

            return DataObject::get_by_id($targetClass, $id);
        });

        if (!$found) {
            throw new ApiError(
                ErrorCode::NOT_FOUND,
                sprintf('Related %s not found for relation "%s".', $targetClass, $relationName)
            );
        }
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
