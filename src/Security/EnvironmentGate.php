<?php

namespace Dynamic\ContentApi\Security;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Environment gating for population-domain endpoints (batch, compositions,
 * page actions, asset writes) — the API analogue of populate's
 * `allow_build_on_live` guard.
 *
 * `SS_CONTENT_API_ALLOW_POPULATE=1` overrides for deliberate UAT/staging
 * population runs.
 */
class EnvironmentGate
{
    use Configurable;
    use Injectable;

    private static array $population_enabled_environments = ['dev', 'test'];

    /**
     * @throws ApiError ENV_FORBIDDEN
     */
    public function checkPopulationAllowed(): void
    {
        if (self::overrideEnabled()) {
            return;
        }

        $environment = Director::get_environment_type();
        $allowed = (array) static::config()->get('population_enabled_environments');

        if (!in_array($environment, $allowed, true)) {
            throw new ApiError(
                ErrorCode::ENV_FORBIDDEN,
                sprintf(
                    'Population endpoints are disabled in the "%s" environment. '
                    . 'Set SS_CONTENT_API_ALLOW_POPULATE=1 to override deliberately.',
                    $environment
                )
            );
        }
    }

    /**
     * Strictly parses `SS_CONTENT_API_ALLOW_POPULATE` rather than relying on PHP
     * truthiness: a bare `if ($value)` would treat the literal string "false"
     * (or "0", "off", "no") as truthy and silently disable the gate. `.env`
     * files aren't parsed as plain strings either — SilverStripe's loader
     * (`M1\Env\Parser`) coerces an unquoted `true`/`false` to a native PHP
     * bool and a bare `1` to an int, so a string-only check would themselves
     * reject the exact value this class's own error message recommends
     * setting. `FILTER_VALIDATE_BOOLEAN` handles bool/int/string uniformly —
     * the same function `AssetHandler::upload()` already uses for the same
     * purpose — and defaults unrecognized/absent values to `false`.
     */
    private static function overrideEnabled(): bool
    {
        return filter_var(Environment::getEnv('SS_CONTENT_API_ALLOW_POPULATE'), FILTER_VALIDATE_BOOLEAN);
    }
}
