<?php

namespace Dynamic\ContentApi\Logging;

use Dynamic\ContentApi\Errors\ErrorCode;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;

/**
 * Opt-in structured server-side request log (#207) — one `info` entry per
 * `content-api/v1` request, covering both success and error responses.
 *
 * Off by default: this is a write-heavy content API on client sites, and
 * logging every request isn't a tax any project asked for. Config-gated
 * per environment, the same shape as {@see \Dynamic\ContentApi\Security\EnvironmentGate}'s
 * `population_enabled_environments` — including its #199 caveat: an array-
 * typed config value can only be widened by a project's own YAML, never
 * narrowed (SilverStripe's additive array-config merge). A project that
 * needs this OFF somewhere `enabled_environments` already lists must use
 * `Config::modify()->set(RequestLogger::class, 'enabled_environments', [...])`
 * in `_config.php`, not a YAML override — see docs/en/02_configuration.md.
 *
 * `SS_CONTENT_API_REQUEST_LOG=1` overrides `enabled_environments` ON for a
 * deliberate one-off (a UAT rehearsal, a support investigation) — the same
 * single-direction shape as `EnvironmentGate::overrideEnabled()`'s
 * `SS_CONTENT_API_ALLOW_POPULATE`, and for the same reason: this can only
 * be a force-ON switch, not a bidirectional one. `Environment::getEnv()`
 * returns PHP `false` both when the var was never set AND when it was
 * explicitly set to a value that parses falsy — there is no way to tell
 * "unset" apart from "explicitly forced off" through that API, so a
 * force-OFF direction would silently do nothing whenever a project
 * actually needs it (the var looks unset either way). Turning logging off
 * where `enabled_environments` already has it on is a config change, not
 * an env var — narrow it with `Config::modify()->set(RequestLogger::class,
 * 'enabled_environments', [...])` in `_config.php` per #199 above.
 *
 * Only covers this module's own `content-api/v1` controller
 * ({@see \Dynamic\ContentApi\Control\ContentApiController::withEnvelope()}).
 * Colymba's generic `/api` CRUD surface is a separate controller this
 * module doesn't own, and a `content-api/v1` URL that matches no
 * `$url_handlers` pattern is rejected by `RequestHandler::handleRequest()`
 * before `withEnvelope()` ever runs — neither is logged. See
 * docs/en/14_architecture.md#request-lifecycle.
 */
class RequestLogger
{
    use Configurable;
    use Injectable;

    private static array $enabled_environments = [];

    /**
     * @param ?string $endpoint The controller action name
     *   ({@see \SilverStripe\Control\Controller::getAction()}) — `null` when `withEnvelope()` is
     *   invoked directly rather than through real routing (e.g. a reflection-based unit test),
     *   since no action was ever set in that case.
     * @param array<string, mixed>|null $result The endpoint callable's raw return value (before
     *   JSON encoding) — only `data.summary.errors` (`POST batch`'s per-op failure count) is
     *   read from it; `null` on any error path.
     */
    public function log(
        HTTPRequest $request,
        HTTPResponse $response,
        ?Member $member,
        ?string $endpoint,
        ?ErrorCode $errorCode,
        float $startTime,
        ?array $result
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        // Best-effort, and deliberately wraps everything below (not just
        // the final log call): this runs unconditionally after
        // ContentApiController::withEnvelope()'s own try/catch/finally has
        // already closed and the response is fully built, so nothing here
        // — a broken project logger service, or a mistake in this method's
        // own field derivation — may ever turn an already-successful
        // response into an uncaught error.
        try {
            $opFailures = $result['data']['summary']['errors'] ?? null;

            Injector::inst()->get(LoggerInterface::class)->info('Content API request', [
                'endpoint' => $endpoint,
                'method' => $request->httpMethod(),
                'classRef' => $request->param('ClassRef'),
                'action' => $request->param('RecordAction') ?? $request->param('PageAction'),
                'status' => $response->getStatusCode(),
                'errorCode' => $errorCode?->value,
                'durationMs' => round((microtime(true) - $startTime) * 1000, 2),
                'responseBytes' => strlen((string) $response->getBody()),
                'opFailures' => is_int($opFailures) ? $opFailures : null,
                // Member ID, never email/name — this log may run on
                // client sites and is off by default specifically so a
                // project can opt in without also opting into PII
                // capture it didn't ask for.
                'memberId' => $member?->ID,
            ]);
        } catch (\Throwable) {
            // Logging is secondary to serving the response.
        }
    }

    public function isEnabled(): bool
    {
        if (self::overrideEnabled()) {
            return true;
        }

        $allowed = (array) static::config()->get('enabled_environments');

        return in_array(Director::get_environment_type(), $allowed, true);
    }

    /**
     * Force-ON only — see the class docblock for why a force-OFF direction
     * isn't possible through `Environment::getEnv()`. Parses the same way
     * `EnvironmentGate::overrideEnabled()` does and for the same reason:
     * SilverStripe's `.env` loader (`M1\Env\Parser`) coerces an unquoted
     * `true`/`false` to a native PHP bool and a bare `1` to an int, so a
     * string-only truthiness check would mis-handle exactly the values
     * this feature's own docs recommend setting.
     */
    private static function overrideEnabled(): bool
    {
        return filter_var(Environment::getEnv('SS_CONTENT_API_REQUEST_LOG'), FILTER_VALIDATE_BOOLEAN);
    }
}
