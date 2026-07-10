<?php

namespace Dynamic\ContentApi\Serialize;

use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Registry\ClassRegistry;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

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
        'ContentApiTokenHash',
        'ContentApiTokenExpire',
    ];

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'externalIds' => '%$' . ExternalIdResolver::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?ExternalIdResolver $externalIds = null;

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
                $relations[$name] = (int) $record->getField($name . 'ID') ?: null;
                continue;
            }

            if (isset($hasMany[$name]) || isset($manyMany[$name])) {
                $relations[$name] = array_map('intval', $record->{$name}()->column('ID'));
                continue;
            }

            // Skip the has_one FK columns; they surface under relations.
            if (str_ends_with($name, 'ID') && isset($hasOne[substr($name, 0, -2)])) {
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
