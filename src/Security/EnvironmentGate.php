<?php

namespace Dynamic\ContentApi\Security;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

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
        if ($this->isPopulationAllowed()) {
            return;
        }

        $environment = Director::get_environment_type();

        // #126: a `dev`/`test` rehearsal against this same gate never fires
        // it at all (this environment wouldn't reach this branch), so the
        // FIRST time a project ever sees this is typically the first real
        // write against a `live`/`uat`-type target — often the one moment
        // nobody's watching the response body closely. The wire error
        // (below) already says exactly what to do; this log line exists so
        // the same signal also reaches whatever server-side log a
        // deploy/ops process actually monitors. Only reached from THIS
        // throwing method, never from isPopulationAllowed()'s silent probe
        // (SchemaService's `populationEnabled` flag) — every `GET
        // schema/site` call would otherwise log a false "blocked" warning
        // on a `live` target purely from checking the flag, defeating the
        // "distinct signal for the moment that actually matters" point of
        // adding it. Best-effort: a broken logger service must not replace
        // the ENV_FORBIDDEN response below with an unrelated 500.
        try {
            Injector::inst()->get(LoggerInterface::class)->warning(sprintf(
                'Content API population blocked in the "%s" environment (SS_CONTENT_API_ALLOW_POPULATE '
                    . 'not set). A clean dev/test rehearsal never exercises this gate, so passing rehearsals '
                    . 'give no warning before a real write hits it here.',
                $environment
            ));
        } catch (\Throwable) {
            // Logging is secondary to the ApiError this method's real
            // contract is — see the docblock above.
        }

        throw new ApiError(
            ErrorCode::ENV_FORBIDDEN,
            sprintf(
                'Population endpoints are disabled in the "%s" environment. '
                . 'Set SS_CONTENT_API_ALLOW_POPULATE=1 to override deliberately.',
                $environment
            )
        );
    }

    /**
     * Silent probe — no exception, no log line — for a caller that only
     * needs to know the current state (e.g. `SchemaService`'s
     * `populationEnabled` flag on `GET schema/site`). Reading this is not
     * itself an attempted write, so it must never produce the same
     * server-side warning `checkPopulationAllowed()` logs when a real
     * population call is actually blocked.
     */
    public function isPopulationAllowed(): bool
    {
        if (self::overrideEnabled()) {
            return true;
        }

        $allowed = (array) static::config()->get('population_enabled_environments');

        return in_array(Director::get_environment_type(), $allowed, true);
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
