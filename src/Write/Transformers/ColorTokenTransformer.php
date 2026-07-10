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
 * Ported from the fixtures recipe's PopulateColorResolver minus its
 * PopulateFactory-backtrace gating — the API is its own trusted context, so
 * ORM-write color bugs (unresolved literals rendering white-on-white) can't
 * happen here: an unresolvable token FAILS the write with
 * TOKEN_RESOLUTION_FAILED instead of persisting a literal.
 *
 * Registered only when essentials-tools is installed (essentials.yml).
 */
class ColorTokenTransformer implements ValueTransformer
{
    use Configurable;
    use Injectable;

    private const PALETTE_PATTERN = '/^\$palette\((\d+)\)$/';

    private const BUTTON_PATTERN = '/^\$button\((\d+),\s*(.+)\)$/';

    /**
     * String constant so there is no hard dependency on essentials-tools.
     */
    private const COLOR_PROVIDER_CLASS = 'Dynamic\\Essentials\\Service\\ColorConfigurationProvider';

    /**
     * Fields eligible for token resolution.
     */
    private static array $token_fields = [
        'BackgroundColor',
        'ButtonColor',
    ];

    public function supports(DataObject $record, string $fieldName, mixed $value): bool
    {
        return is_string($value)
            && in_array($fieldName, (array) static::config()->get('token_fields'), true)
            && (bool) preg_match('/^\$(palette|button)\(/', $value)
            && class_exists(ColorTokenTransformer::COLOR_PROVIDER_CLASS);
    }

    public function transform(DataObject $record, string $fieldName, mixed $value): mixed
    {
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
        $provider = ColorTokenTransformer::COLOR_PROVIDER_CLASS;
        $colors = array_values((array) $provider::getBackgroundColors());

        if ($colors === []) {
            throw new ApiError(
                ErrorCode::TOKEN_RESOLUTION_FAILED,
                sprintf('Cannot resolve %s — no background_colors configured for this project.', $token)
            );
        }

        if (!isset($colors[$index])) {
            throw new ApiError(
                ErrorCode::TOKEN_RESOLUTION_FAILED,
                sprintf('%s is out of range — the project palette has indexes 0–%d.', $token, count($colors) - 1)
            );
        }

        return $colors[$index];
    }

    protected function resolveButton(int $bgIndex, string $label, string $token): string
    {
        $bgColor = $this->resolvePalette($bgIndex, $token);

        $provider = ColorTokenTransformer::COLOR_PROVIDER_CLASS;
        $combos = (array) $provider::getButtonColorCombinations();
        $bgButtons = (array) ($combos[$bgColor] ?? []);

        if ($bgButtons === []) {
            throw new ApiError(
                ErrorCode::TOKEN_RESOLUTION_FAILED,
                sprintf('Cannot resolve %s — no button colors configured for background %s.', $token, $bgColor)
            );
        }

        // Exact label, else the background's first combo (generic-label
        // payloads adopt each project's own label names).
        $combo = $bgButtons[$label] ?? reset($bgButtons);

        return json_encode($combo, JSON_THROW_ON_ERROR);
    }
}
