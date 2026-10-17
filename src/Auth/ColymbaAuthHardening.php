<?php

namespace Dynamic\ContentApi\Auth;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;

/**
 * Narrows colymba's `api/auth/*` actions (#237). colymba's
 * `TokenAuthenticator.allowed_actions` is an int-keyed list, so project YAML
 * can only append to it, never remove an entry (the same array-merge trap as
 * #199). This class owns a string-keyed map instead — string keys overwrite
 * on merge, so a project can flip any action either way — and the module's
 * `_config.php` applies it as a hard set.
 *
 * - `login` stays on: minting a token from an email/password is a
 *   documented contract of the `/api` surface.
 * - `logout` is off: colymba lets any anonymous caller expire the token of
 *   whichever member the `email` request var names.
 * - `lostPassword` is off: it sends a password-reset email to any address,
 *   unauthenticated.
 */
class ColymbaAuthHardening
{
    use Configurable;

    /**
     * @var array<string, bool> colymba auth action => allowed
     */
    private static array $auth_actions = [
        'login' => true,
        'logout' => false,
        'lostPassword' => false,
    ];

    /**
     * Set `TokenAuthenticator.allowed_actions` to the enabled actions. A
     * no-op without colymba installed.
     */
    public static function apply(): void
    {
        $authenticator = 'Colymba\\RESTfulAPI\\Authenticators\\TokenAuthenticator';

        if (!class_exists($authenticator)) {
            return;
        }

        $actions = (array) Config::inst()->get(static::class, 'auth_actions');

        Config::modify()->set(
            $authenticator,
            'allowed_actions',
            array_keys(array_filter($actions, fn ($enabled) => (bool) $enabled))
        );
    }
}
