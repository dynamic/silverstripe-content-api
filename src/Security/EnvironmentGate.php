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
        if (Environment::getEnv('SS_CONTENT_API_ALLOW_POPULATE')) {
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
}
