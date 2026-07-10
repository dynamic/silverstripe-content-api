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
            'integrations' => $this->detectIntegrations(),
            'classes' => $classes,
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

        $fields = [];

        foreach ($schema->fieldSpecs($className) as $name => $spec) {
            if ($name === 'ID' || $name === $this->externalIds->fieldName()) {
                continue;
            }

            // has_one FK columns surface under relations instead.
            if (str_ends_with($name, 'ID') && isset($singleton->hasOne()[substr($name, 0, -2)])) {
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

            $fields[$name] = $field;
        }

        $hasOne = [];

        foreach ((array) $singleton->hasOne() as $name => $relationClass) {
            $hasOne[$name] = [
                'class' => $relationClass,
                'payload' => $this->payloadKind($relationClass),
                'writable' => $this->applicator->isFieldWritable($className, $name . 'ID', $name),
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
