<?php

namespace Dynamic\ContentApi\Serialize;

use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Write\WriteApplicator;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Serializes a DataObject to the API's record shape:
 *
 * ```json
 * {
 *   "id": 42, "classRef": "ElementCard", "className": "...", "externalId": "home-hero",
 *   "fields": { "Title": "Welcome", "Sort": 2 },
 *   "relations": { "Image": 91, "Slides": [3, 4] },
 *   "stage": { "draft": true, "live": true, "modifiedOnDraft": false }
 * }
 * ```
 *
 * Field names stay PascalCase (native SilverStripe names) so GET responses
 * round-trip directly into PATCH/composition payloads. A per-class
 * `api_fields` config whitelists output; entries may be db fields, relation
 * names or getter-backed properties (`getFoo()` — natively supported here,
 * unlike colymba's serializer). Without `api_fields`, all db fields plus
 * relation IDs are emitted, minus the global `hidden_fields` denylist.
 */
class RecordSerializer
{
    use Configurable;
    use Injectable;

    /**
     * Field names never serialized regardless of class config.
     */
    private static array $hidden_fields = [
        'Password',
        'Salt',
        'PasswordEncryption',
        'PasswordExpiry',
        'AutoLoginHash',
        'AutoLoginExpired',
        'TempIDHash',
        'TempIDExpired',
        'SessionData',
        'ShareTokenSalt',
        'ApiToken',
        'ApiTokenExpire',
    ];

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'externalIds' => '%$' . ExternalIdResolver::class,
        'logger' => '%$' . LoggerInterface::class,
        'applicator' => '%$' . WriteApplicator::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?ExternalIdResolver $externalIds = null;

    public ?LoggerInterface $logger = null;

    public ?WriteApplicator $applicator = null;

    /**
     * Dedupes relation-read warnings by shape (relation + class), not by
     * record — a systemically broken relation (a dropped ClassRegistry
     * mapping, a misconfigured has_many) otherwise logs once per affected
     * record on every list read of a page, flooding logs with duplicates of
     * the same underlying problem.
     *
     * @var array<string, true>
     */
    private array $loggedRelationWarnings = [];

    public function serialize(DataObject $record): array
    {
        $out = [
            'id' => (int) $record->ID,
            'classRef' => $this->registry->refFor($record->ClassName),
            'className' => $record->ClassName,
        ];

        $externalIdField = $this->externalIds->fieldName();

        if ($this->externalIds->supports($record->ClassName)) {
            $out['externalId'] = $record->getField($externalIdField) ?: null;
        }

        [$fields, $relations] = $this->collectFields($record, $externalIdField);

        $out['fields'] = $fields;

        if ($relations !== []) {
            $out['relations'] = $relations;
        }

        if ($record->hasExtension(Versioned::class)) {
            $out['stage'] = $this->stageReport($record);
        }

        return $out;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function collectFields(DataObject $record, string $externalIdField): array
    {
        $schema = DataObject::getSchema();
        $className = $record->ClassName;
        $hidden = (array) static::config()->get('hidden_fields');
        $hidden[] = $externalIdField;

        $apiFields = Config::inst()->get($className, 'api_fields');

        $dbFields = $schema->fieldSpecs($className);
        unset($dbFields['ID']);

        $hasOne = (array) $record->hasOne();
        $hasMany = (array) $record->hasMany();
        $manyMany = (array) $record->manyMany();

        $fields = [];
        $relations = [];

        $wanted = is_array($apiFields)
            ? $apiFields
            : array_merge(
                array_keys($dbFields),
                array_keys($hasOne),
                array_keys($hasMany),
                array_keys($manyMany)
            );

        foreach ($wanted as $name) {
            if (in_array($name, $hidden, true)) {
                continue;
            }

            if (isset($hasOne[$name])) {
                $id = (int) $record->getField($name . 'ID') ?: null;

                if ($hasOne[$name] === DataObject::class) {
                    // Polymorphic has_one: the bare FK id isn't dereferenceable
                    // without the companion {Name}Class column — emit
                    // {"id", "class"} instead of a bare int so a GET->PATCH
                    // round-trip preserves the target class. "class" is a
                    // short registry ref, the same shape
                    // WriteApplicator::resolveRelation() requires on write.
                    //
                    // Only look at the Class column when the relation is
                    // actually set: a write path that clears just the FK
                    // (leaving a stale Class value behind) must not trigger
                    // a spurious "unregistered class" warning for a relation
                    // that's genuinely unset from the caller's perspective.
                    if ($id === null) {
                        $relations[$name] = null;
                    } else {
                        $targetClassName = $record->getField($name . 'Class') ?: null;
                        $classRef = $targetClassName ? $this->registry->refFor($targetClassName) : null;

                        if ($classRef === null) {
                            // Either the companion Class column is empty
                            // despite the FK being set (a partial write, a
                            // legacy import, an upstream bug — the relation
                            // is broken, not just unexposed), or it names a
                            // class ClassRegistry never registered (never
                            // exposed, or the mapping was removed after this
                            // record was written). Either way: omit "class"
                            // rather than emit a misleading null (#26) or
                            // leak the internal FQCN of a class the
                            // registry's deny-by-default model deliberately
                            // never exposed — a write attempt without a
                            // class hint gets a clear PAYLOAD_INVALID
                            // instead of silently targeting nothing.
                            $this->warnOnceForRelation(
                                sprintf('poly:%s:%s:%s', $className, $name, $targetClassName ?? ''),
                                $targetClassName !== null
                                    ? sprintf(
                                        'Polymorphic has_one "%s" on %s targets unregistered class "%s".',
                                        $name,
                                        $this->recordLabel($className, $record),
                                        $targetClassName
                                    )
                                    : sprintf(
                                        'Polymorphic has_one "%s" on %s has an id but no companion Class value.',
                                        $name,
                                        $this->recordLabel($className, $record)
                                    )
                            );
                        }

                        $relations[$name] = $classRef !== null
                            ? ['id' => $id, 'class' => $classRef]
                            : ['id' => $id];
                    }
                } else {
                    $relations[$name] = $id;
                }

                continue;
            }

            if (isset($hasMany[$name]) || isset($manyMany[$name])) {
                // Guarded: a misconfigured third-party relation (e.g. a
                // has_many whose target lacks the has_one in this manifest)
                // must not take down the whole record read — but it must
                // still be discoverable, not indistinguishable from a
                // genuinely empty relation (#22).
                try {
                    $relations[$name] = array_map('intval', $record->{$name}()->column('ID'));
                } catch (Throwable $exception) {
                    $this->warnOnceForRelation(
                        sprintf('read:%s:%s', $className, $name),
                        sprintf(
                            'Relation "%s" on %s could not be read: %s',
                            $name,
                            $this->recordLabel($className, $record),
                            $exception->getMessage()
                        ),
                        ['exception' => $exception]
                    );

                    $relations[$name] = null;
                }
                continue;
            }

            // Skip the has_one FK columns; they surface under relations.
            if (str_ends_with($name, 'ID') && isset($hasOne[substr($name, 0, -2)])) {
                continue;
            }

            // A polymorphic has_one's companion {Name}Class column (and,
            // for a multirelational one, {Name}Relation too) is managed as
            // part of the relation — see the "class" key folded into
            // relations[$name] above — never independently exposed as a
            // standalone field. Mirrors SchemaService's own exclusion (#25,
            // #34); leaking the raw FQCN or SilverStripe's internal
            // relation-disambiguator string here would also bypass the
            // ClassRegistry short-ref allowlist the "class" key enforces.
            if (
                $this->applicator->polymorphicClassColumnRelation($name, $hasOne) !== null
                || $this->applicator->polymorphicRelationColumnRelation($name, $className, $hasOne) !== null
            ) {
                continue;
            }

            if (isset($dbFields[$name])) {
                $fields[$name] = $record->getField($name);
                continue;
            }

            // Getter-backed field (api_fields entries only).
            if (is_array($apiFields) && $record->hasMethod("get{$name}")) {
                $fields[$name] = $record->{"get{$name}"}();
            }
        }

        return [$fields, $relations];
    }

    private function recordLabel(string $className, DataObject $record): string
    {
        return sprintf('%s#%d', $className, (int) $record->ID);
    }

    /**
     * Logs a relation-read warning at most once per (relation + shape) key
     * for this serializer instance's lifetime — see $loggedRelationWarnings.
     */
    private function warnOnceForRelation(string $dedupeKey, string $message, array $context = []): void
    {
        if (isset($this->loggedRelationWarnings[$dedupeKey])) {
            return;
        }

        $this->loggedRelationWarnings[$dedupeKey] = true;
        $this->logger->warning($message, $context);
    }

    private function stageReport(DataObject $record): array
    {
        $live = (bool) $record->isPublished();

        return [
            'draft' => true,
            'live' => $live,
            'modifiedOnDraft' => $live ? (bool) $record->stagesDiffer() : true,
        ];
    }
}
