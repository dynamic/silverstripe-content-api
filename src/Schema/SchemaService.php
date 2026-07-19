<?php

namespace Dynamic\ContentApi\Schema;

use Dynamic\ContentApi\Identity\ExternalIdResolver;
use Dynamic\ContentApi\Registry\ClassRegistry;
use Dynamic\ContentApi\Security\EnvironmentGate;
use Dynamic\ContentApi\Write\WriteApplicator;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBEnum;
use SilverStripe\Versioned\Versioned;

/**
 * Introspection: what the API exposes and how to write to it. This is what a
 * population skill (or a future MCP server) reads instead of hardcoding the
 * element inventory — each class schema doubles as a payload contract.
 */
class SchemaService
{
    use Configurable;
    use Injectable;

    /**
     * Field-name → token DSL hints surfaced in schemas (populated by the
     * essentials integration: BackgroundColor: palette, ButtonColor: button).
     */
    private static array $field_tokens = [];

    private static array $dependencies = [
        'registry' => '%$' . ClassRegistry::class,
        'externalIds' => '%$' . ExternalIdResolver::class,
        'applicator' => '%$' . WriteApplicator::class,
        'environmentGate' => '%$' . EnvironmentGate::class,
    ];

    public ?ClassRegistry $registry = null;

    public ?ExternalIdResolver $externalIds = null;

    public ?WriteApplicator $applicator = null;

    public ?EnvironmentGate $environmentGate = null;

    /**
     * The site-level schema: exposed classes, detected integrations,
     * environment and population availability.
     */
    public function siteSchema(): array
    {
        $classes = [];

        foreach ($this->registry->allExposed() as $ref => $info) {
            $classes[$ref] = [
                'className' => $info['class'],
                'access' => $info['verbs'],
                'versioned' => DataObject::singleton($info['class'])->hasExtension(Versioned::class),
                'externalId' => $this->externalIds->supports($info['class']),
                'element' => is_a($info['class'], 'DNADesign\\Elemental\\Models\\BaseElement', true),
                'page' => is_a($info['class'], 'SilverStripe\\CMS\\Model\\SiteTree', true),
            ];
        }

        $populationAllowed = true;

        try {
            $this->environmentGate->checkPopulationAllowed();
        } catch (\Dynamic\ContentApi\Errors\ApiError) {
            $populationAllowed = false;
        }

        return [
            'api' => 'silverstripe-content-api/v1',
            'environment' => Director::get_environment_type(),
            'populationEnabled' => $populationAllowed,
            'crud' => $this->crudSurface(),
            'integrations' => $this->detectIntegrations(),
            'classes' => $classes,
        ];
    }

    /**
     * Where generic CRUD lives: the colymba/silverstripe-restfulapi surface.
     */
    protected function crudSurface(): array
    {
        $restfulApiClass = 'Colymba\\RESTfulAPI\\RESTfulAPI';
        $route = 'api';

        foreach ((array) Config::inst()->get(Director::class, 'rules') as $pattern => $handler) {
            if ($handler === $restfulApiClass) {
                $route = $pattern;
                break;
            }
        }

        return [
            'provider' => 'colymba/silverstripe-restfulapi',
            'route' => $route,
            'auth' => "{$route}/auth/login",
            'models' => array_keys((array) Config::inst()->get(
                'Colymba\\RESTfulAPI\\QueryHandlers\\DefaultQueryHandler',
                'models'
            )),
        ];
    }

    /**
     * Per-class schema: fields with types/writability/enum values/token
     * hints, plus relations with their payload kinds.
     */
    public function classSchema(string $classRef): array
    {
        $className = $this->registry->resolve($classRef);
        $singleton = DataObject::singleton($className);
        $schema = DataObject::getSchema();
        $tokens = (array) static::config()->get('field_tokens');
        $hasOneSpec = (array) $singleton->hasOne();
        $computedFields = $this->normalizeFieldNotes(
            (array) Config::inst()->get($className, 'api_computed_fields')
        );
        $importOwnedFields = $this->normalizeFieldNotes(
            (array) Config::inst()->get($className, 'api_import_owned_fields')
        );

        $fields = [];

        foreach ($schema->fieldSpecs($className) as $name => $spec) {
            if ($name === 'ID' || $name === $this->externalIds->fieldName()) {
                continue;
            }

            // has_one FK columns surface under relations instead.
            if (str_ends_with($name, 'ID') && isset($hasOneSpec[substr($name, 0, -2)])) {
                continue;
            }

            // A polymorphic has_one's companion {Name}Class column is
            // managed as part of the relation (see hasOne below), never
            // independently writable as a standalone field (#25).
            if ($this->applicator->polymorphicClassColumnRelation($name, $hasOneSpec) !== null) {
                continue;
            }

            // A multirelational polymorphic has_one's companion
            // {Name}Relation column is never settable via this API at all
            // (see WriteApplicator::polymorphicRelationColumnRelation()) —
            // never advertise it as a standalone field either (#34).
            if ($this->applicator->polymorphicRelationColumnRelation($name, $className, $hasOneSpec) !== null) {
                continue;
            }

            $field = [
                'type' => $spec,
                'writable' => $this->applicator->isFieldWritable($className, $name),
            ];

            $dbObject = $singleton->dbObject($name);

            if ($dbObject instanceof DBEnum) {
                $field['values'] = $dbObject->enumValues();
            }

            if (isset($tokens[$name])) {
                $field['tokens'] = $tokens[$name];
            }

            // Honesty flags: advisory only, do not affect `writable` above.
            // A computed field (onBeforeWrite trap) or import-owned field
            // (external feed) can accept a write and then silently clobber
            // it — these flags let a client know before it wastes one. To
            // also reject the write outright, use api_protected_fields. The
            // two are independent (a field can be both), so each is checked
            // on its own rather than as an if/elseif.
            $note = null;

            if (array_key_exists($name, $computedFields)) {
                $field['computed'] = true;
                $note = $computedFields[$name];
            }

            if (array_key_exists($name, $importOwnedFields)) {
                $field['importOwned'] = true;
                $note ??= $importOwnedFields[$name];
            }

            if ($note !== null) {
                $field['note'] = $note;
            }

            $fields[$name] = $field;
        }

        $hasOne = [];

        foreach ($hasOneSpec as $name => $relationClass) {
            // A polymorphic has_one's FK and companion {Name}Class column
            // are written and gated as a pair (#25) — advertising the FK's
            // writability alone would tell a client a relation is writable
            // when a real write could still be rejected over the Class
            // column, or vice versa.
            $writable = $relationClass === DataObject::class
                ? $this->applicator->isPolymorphicRelationWritable($className, $name)
                : $this->applicator->isFieldWritable($className, $name . 'ID', $name);

            $hasOne[$name] = [
                'class' => $relationClass,
                'payload' => $this->payloadKind($relationClass),
                'writable' => $writable,
            ];
        }

        $writableRelations = (array) Config::inst()->get($className, 'api_writable_relations');
        $many = [];

        foreach (['hasMany' => $singleton->hasMany(), 'manyMany' => $singleton->manyMany()] as $kind => $relations) {
            foreach ((array) $relations as $name => $relationClass) {
                if (is_array($relationClass)) {
                    $relationClass = $relationClass['to'] ?? '';
                }

                $many[$kind][$name] = [
                    'class' => strtok((string) $relationClass, '.'),
                    'writable' => in_array($name, $writableRelations, true),
                ];
            }
        }

        return [
            'classRef' => $classRef,
            'className' => $className,
            'access' => $this->registry->accessVerbs($className),
            'versioned' => $singleton->hasExtension(Versioned::class),
            'externalIdField' => $this->externalIds->supports($className)
                ? $this->externalIds->fieldName()
                : null,
            'fields' => $fields,
            'hasOne' => $hasOne,
            'hasMany' => $many['hasMany'] ?? [],
            'manyMany' => $many['manyMany'] ?? [],
        ];
    }

    /**
     * Normalizes an `api_computed_fields`/`api_import_owned_fields` config
     * value into a field-name => ?note map. Accepts either a bare list of
     * field names (`['Title', 'Rank']`) or a name => note map
     * (`['Title' => 'Overwritten from ParentPage on save']`); a list entry
     * carries no note.
     */
    protected function normalizeFieldNotes(array $config): array
    {
        $notes = [];

        foreach ($config as $key => $value) {
            if (is_int($key)) {
                $notes[$value] = null;
            } else {
                $notes[$key] = $value;
            }
        }

        return $notes;
    }

    protected function payloadKind(string $relationClass): string
    {
        if (is_a($relationClass, 'SilverStripe\\LinkField\\Models\\Link', true)) {
            return 'link';
        }

        if (is_a($relationClass, 'SilverStripe\\Assets\\File', true)) {
            return 'assetRef';
        }

        return 'recordRef';
    }

    protected function detectIntegrations(): array
    {
        $integrations = [
            'restfulapi' => class_exists('Colymba\\RESTfulAPI\\RESTfulAPI'),
            'elemental' => class_exists('DNADesign\\Elemental\\Models\\ElementalArea'),
            'linkfield' => class_exists('SilverStripe\\LinkField\\Models\\Link'),
            'elementalTemplates' => class_exists('Dynamic\\ElementalTemplates\\Models\\Template'),
        ];

        $providerClass = 'Dynamic\\Essentials\\Service\\ColorConfigurationProvider';

        if (class_exists($providerClass)) {
            $backgrounds = array_values((array) $providerClass::getBackgroundColors());
            $combos = (array) $providerClass::getButtonColorCombinations();

            $integrations['essentialsColors'] = [
                'backgroundColors' => $backgrounds,
                'buttonLabels' => array_values(array_unique(array_merge(
                    ...array_map('array_keys', array_values($combos) ?: [[]])
                ))),
                'tokens' => ['$palette(N)', '$button(N, Label)'],
            ];
        } else {
            $integrations['essentialsColors'] = false;
        }

        return $integrations;
    }
}
