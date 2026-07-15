<?php

namespace Dynamic\ContentApi\Registry;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Maps short class references used in URLs/payloads to FQCNs and resolves each
 * exposed class's allowed API verbs.
 *
 * One map drives both surfaces: colymba's `DefaultQueryHandler.models` is the
 * base (define your models there so `/api` and `/content-api/v1` expose the
 * same refs); this class's own `models` config overlays it (ours wins per
 * key) for refs that should exist only on the content-api surface.
 *
 * Exposure is deny-by-default: a class must be in the merged map AND carry an
 * `api_access` (or `content_api_access`) config value before any endpoint will
 * touch it.
 *
 * Verbs: read, create, update, delete, action (publish/unpublish/archive).
 */
class ClassRegistry
{
    use Configurable;
    use Injectable;

    public const VERBS = ['read', 'create', 'update', 'delete', 'action'];

    /**
     * Content-api-only refs (or overrides of colymba refs):
     * `ElementalArea: DNADesign\Elemental\Models\ElementalArea`.
     */
    private static array $models = [];

    /**
     * The unified map: colymba's models base + our overlay.
     */
    protected function mergedModels(): array
    {
        $base = (array) Config::inst()->get(
            'Colymba\\RESTfulAPI\\QueryHandlers\\DefaultQueryHandler',
            'models'
        );

        return array_merge($base, (array) static::config()->get('models'));
    }

    /**
     * Maps HTTP-method style access tokens to verbs for config compatibility
     * with colymba-style `api_access: 'GET,POST'` values.
     */
    private const METHOD_VERB_MAP = [
        'GET' => 'read',
        'POST' => 'create',
        'PUT' => 'update',
        'PATCH' => 'update',
        'DELETE' => 'delete',
    ];

    /**
     * Resolves a polymorphic has_one's target class from a payload value's
     * "class" hint — shared by WriteApplicator::resolveRelation() and
     * PermissionPolicy::buildCreateContext(), which both independently
     * needed the same "polymorphic has_one payload value -> concrete FQCN"
     * resolution (#24).
     *
     * @throws ApiError PAYLOAD_INVALID when $value isn't an array carrying
     *   "class"; UNKNOWN_CLASS via resolve() when the hint doesn't map
     */
    public function resolvePolymorphicHint(string $relationName, mixed $value): string
    {
        if (!is_array($value) || !isset($value['class'])) {
            throw new ApiError(
                ErrorCode::PAYLOAD_INVALID,
                sprintf('Relation "%s" is polymorphic and requires an explicit "class" hint.', $relationName)
            );
        }

        return $this->resolve((string) $value['class']);
    }

    /**
     * Resolve a short class reference to its FQCN.
     *
     * @throws ApiError UNKNOWN_CLASS when unmapped, missing or not exposed
     */
    public function resolve(string $classRef): string
    {
        $models = $this->mergedModels();
        $className = $models[$classRef] ?? null;

        if (!$className || !class_exists($className)) {
            throw new ApiError(
                ErrorCode::UNKNOWN_CLASS,
                sprintf('Unknown class reference "%s".', $classRef)
            );
        }

        return $className;
    }

    /**
     * Reverse lookup: FQCN (or subclass instance's class) to its short ref.
     * Walks up the ancestry so subclasses serialize under their nearest
     * registered ancestor when not directly mapped.
     */
    public function refFor(string $className): ?string
    {
        $byClass = array_flip($this->mergedModels());

        $candidate = $className;
        while ($candidate) {
            if (isset($byClass[$candidate])) {
                return $byClass[$candidate];
            }
            $candidate = get_parent_class($candidate) ?: null;
        }

        return null;
    }

    /**
     * The verbs a class is exposed for. `content_api_access` wins over
     * `api_access` when both are set (colymba coexistence).
     *
     * @return string[] subset of ClassRegistry::VERBS, empty = not exposed
     */
    public function accessVerbs(string $className): array
    {
        $access = Config::inst()->get($className, 'content_api_access');

        if ($access === null) {
            $access = Config::inst()->get($className, 'api_access');
        }

        if ($access === true) {
            return ClassRegistry::VERBS;
        }

        if (!is_string($access) || trim($access) === '') {
            return [];
        }

        $verbs = [];

        foreach (explode(',', $access) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $verb = ClassRegistry::METHOD_VERB_MAP[strtoupper($token)] ?? strtolower($token);

            if (in_array($verb, ClassRegistry::VERBS, true) && !in_array($verb, $verbs, true)) {
                $verbs[] = $verb;
            }
        }

        return $verbs;
    }

    /**
     * All exposed classes: ref => ['class' => FQCN, 'verbs' => [...]].
     * Classes mapped in `models` but carrying no api_access are omitted.
     */
    public function allExposed(): array
    {
        $exposed = [];

        foreach ($this->mergedModels() as $ref => $className) {
            if (!class_exists($className)) {
                continue;
            }

            $verbs = $this->accessVerbs($className);

            if ($verbs === []) {
                continue;
            }

            $exposed[$ref] = [
                'class' => $className,
                'verbs' => $verbs,
            ];
        }

        return $exposed;
    }
}
