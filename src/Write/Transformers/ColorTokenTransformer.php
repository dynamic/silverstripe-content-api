<?php

namespace Dynamic\ContentApi\Write\Transformers;

use Dynamic\ContentApi\Errors\ApiError;
use Dynamic\ContentApi\Errors\ErrorCode;
use Dynamic\ContentApi\Write\ValueTransformer;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Resolves Essentials color tokens in write payloads:
 *
 * - `$palette(N)` → Nth background color hex from ColorConfigurationProvider
 * - `$button(N, Label)` → JSON button-combo blob for that background (falls
 *   back to the background's first combo when the label doesn't exist, so
 *   shared payloads use generic labels)
 *
 * The actual parsing/lookup/fallback algorithm lives in essentials-tools'
 * `Dynamic\Essentials\Service\ColorTokenResolver`, shared with the fixtures
 * recipe's `PopulateColorResolver` (both used to duplicate it independently).
 * This class only decides *when* to resolve (which field/token shape) and
 * *what to do on failure* — the API is its own trusted context, so an
 * unresolvable token FAILS the write with TOKEN_RESOLUTION_FAILED instead of
 * persisting a literal (unlike the fixtures recipe, which logs and leaves the
 * literal in place). `resolvePalette()`/`resolveButton()` below map the
 * resolver's neutral `ColorTokenResult` onto that throw policy, preserving
 * this class's original three error messages verbatim.
 *
 * Registered only when essentials-tools is installed (essentials.yml) — but
 * essentials.yml's gate checks ColorConfigurationProvider only, not
 * ColorTokenResolver (the two have no hard dependency on each other, so a
 * staggered upgrade can have one without the other). supports() below claims
 * a token-shaped write whenever ColorConfigurationProvider exists;
 * transform() then checks ColorTokenResolver itself and throws
 * TOKEN_RESOLUTION_FAILED if it's missing, rather than letting
 * WriteApplicator fall through to persisting the literal token string (#61).
 */
class ColorTokenTransformer implements ValueTransformer
{
    use Configurable;
    use Injectable;

    private const PALETTE_PATTERN = '/^\$palette\((\d+)\)$/';

    private const BUTTON_PATTERN = '/^\$button\((\d+),\s*(.+)\)$/';

    /**
     * String constant so there is no hard dependency on essentials-tools —
     * only ever referenced once `supports()`'s `class_exists()` gate (below)
     * has already confirmed the package is installed.
     */
    private const COLOR_PROVIDER_CLASS = 'Dynamic\\Essentials\\Service\\ColorConfigurationProvider';

    /**
     * Configurable (not a const) so a test can override it to a nonexistent
     * class and exercise the "ColorConfigurationProvider is installed but
     * ColorTokenResolver isn't" branch in transform() without needing a real
     * staggered essentials-tools install — see ColorTokenTest's
     * testMissingResolverFailsTheWriteInsteadOfPersistingTheLiteral (#61).
     */
    private static string $color_token_resolver_class = 'Dynamic\\Essentials\\Service\\ColorTokenResolver';

    /**
     * Fields eligible for token resolution.
     */
    private static array $token_fields = [
        'BackgroundColor',
        'ButtonColor',
    ];

    public function supports(DataObject $record, string $fieldName, mixed $value): bool
    {
        // Deliberately does NOT also require ColorTokenResolver to exist.
        // This class is only registered at all when ColorConfigurationProvider
        // is present (see essentials.yml's Only: classexists gate) — an
        // essentials-tools install that has ColorConfigurationProvider but
        // predates ColorTokenResolver (staggered upgrades — the two classes
        // have no hard dependency on each other) must still CLAIM a
        // `$palette(...)`/`$button(...)` write here, so transform() can fail
        // it loudly below. If supports() degraded to false in that case (as
        // it used to), WriteApplicator::transformValue() falls through to
        // `return $value` — silently persisting the literal token string
        // with a 200 response, since nothing else in the transformer chain
        // recognizes this shape either. See the module's incident notes for
        // where that was found.
        return is_string($value)
            && in_array($fieldName, (array) static::config()->get('token_fields'), true)
            && (bool) preg_match('/^\$(palette|button)\(/', $value)
            && class_exists(ColorTokenTransformer::COLOR_PROVIDER_CLASS);
    }

    public function transform(DataObject $record, string $fieldName, mixed $value): mixed
    {
        $resolverClass = static::config()->get('color_token_resolver_class');

        if (!class_exists($resolverClass)) {
            throw new ApiError(
                ErrorCode::TOKEN_RESOLUTION_FAILED,
                sprintf(
                    'Cannot resolve %s — this site\'s dynamic/silverstripe-essentials-tools install '
                        . 'predates %s. Upgrade essentials-tools, or write a literal color value '
                        . 'instead of a token.',
                    $value,
                    $resolverClass
                )
            );
        }

        if (preg_match(ColorTokenTransformer::PALETTE_PATTERN, $value, $matches)) {
            return $this->resolvePalette((int) $matches[1], $value);
        }

        if (preg_match(ColorTokenTransformer::BUTTON_PATTERN, $value, $matches)) {
            return $this->resolveButton((int) $matches[1], trim($matches[2]), $value);
        }

        throw new ApiError(
            ErrorCode::TOKEN_RESOLUTION_FAILED,
            sprintf('Malformed color token "%s" — use $palette(N) or $button(N, Label).', $value)
        );
    }

    protected function resolvePalette(int $index, string $token): string
    {
        $resolver = static::config()->get('color_token_resolver_class');
        $result = $resolver::resolvePalette($index);

        if ($result->success) {
            return $result->value;
        }

        throw new ApiError(ErrorCode::TOKEN_RESOLUTION_FAILED, $this->paletteFailureMessage($result, $token));
    }

    protected function resolveButton(int $bgIndex, string $label, string $token): string
    {
        $resolver = static::config()->get('color_token_resolver_class');
        $result = $resolver::resolveButton($bgIndex, $label);

        if ($result->success) {
            return $result->value;
        }

        // ColorTokenResolver reports failureReason as a plain string (see
        // Dynamic\Essentials\Service\ColorTokenResult) so this class never
        // needs to import it. A palette-origin failure (no colors configured
        // / index out of range / stale-class method missing) reuses the
        // palette wording below; the resolver's "no combos configured"
        // button-side failure reasons all collapse onto this class's one
        // original button message, unchanged; a JSON-encode failure gets its
        // own distinct message so a genuinely-configured-but-unserializable
        // combo isn't misreported as unconfigured.
        if (
            in_array(
                $result->failureReason,
                ['no_background_colors', 'palette_out_of_range', 'background_method_missing'],
                true
            )
        ) {
            throw new ApiError(ErrorCode::TOKEN_RESOLUTION_FAILED, $this->paletteFailureMessage($result, $token));
        }

        if ($result->failureReason === 'json_encode_failed') {
            throw new ApiError(
                ErrorCode::TOKEN_RESOLUTION_FAILED,
                sprintf('Cannot resolve %s — the resolved button color combo could not be encoded.', $token)
            );
        }

        throw new ApiError(
            ErrorCode::TOKEN_RESOLUTION_FAILED,
            sprintf(
                'Cannot resolve %s — no button colors configured for background %s.',
                $token,
                $result->backgroundColor
            )
        );
    }

    /**
     * @param object $result a Dynamic\Essentials\Service\ColorTokenResult
     */
    private function paletteFailureMessage(object $result, string $token): string
    {
        if ($result->failureReason === 'palette_out_of_range') {
            return sprintf('%s is out of range — the project palette has indexes 0–%d.', $token, $result->maxIndex);
        }

        if ($result->failureReason === 'background_method_missing') {
            return sprintf(
                'Cannot resolve %s — ColorConfigurationProvider is missing getBackgroundColors() '
                    . '(a stale class definition, e.g. from a cached opcache/manifest — try a fresh flush).',
                $token
            );
        }

        return sprintf('Cannot resolve %s — no background_colors configured for this project.', $token);
    }
}
