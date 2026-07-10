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
 * Exposure is deny-by-default: a class must be in the `models` map AND carry an
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
     * Short reference => FQCN, e.g. `BlockPage: Dynamic\Base\Page\BlockPage`.
     * Projects define this in YAML.
     */
    private static array $models = [];

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
     * Resolve a short class reference to its FQCN.
     *
     * @throws ApiError UNKNOWN_CLASS when unmapped, missing or not exposed
     */
    public function resolve(string $classRef): string
    {
        $models = (array) static::config()->get('models');
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
        $models = (array) static::config()->get('models');
        $byClass = array_flip($models);

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
     * @return string[] subset of self::VERBS, empty = not exposed
     */
    public function accessVerbs(string $className): array
    {
        $access = Config::inst()->get($className, 'content_api_access');

        if ($access === null) {
            $access = Config::inst()->get($className, 'api_access');
        }

        if ($access === true) {
            return self::VERBS;
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

            $verb = self::METHOD_VERB_MAP[strtoupper($token)] ?? strtolower($token);

            if (in_array($verb, self::VERBS, true) && !in_array($verb, $verbs, true)) {
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

        foreach ((array) static::config()->get('models') as $ref => $className) {
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
