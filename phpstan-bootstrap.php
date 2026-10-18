<?php

/**
 * PHPStan bootstrap: refuse to analyse without cambis/silverstan.
 *
 * silverstan is what teaches PHPStan that a `private static` config property
 * is read through Config and which members SilverStripe extensions add. It is
 * loaded by the phpstan/extension-installer Composer plugin, so an install
 * with `--no-plugins` (or with the plugin disallowed) analyses without it and
 * reports dozens of false errors (#277). Fail once, with the fix, instead.
 *
 * The check reads the GeneratedConfig of whichever install runs PHPStan: the
 * module's own vendor in a standalone checkout, the host project's in a
 * vendor/ checkout.
 */

$extensions = class_exists(\PHPStan\ExtensionInstaller\GeneratedConfig::class)
    ? \PHPStan\ExtensionInstaller\GeneratedConfig::EXTENSIONS
    : [];

if (!isset($extensions['cambis/silverstan'])) {
    throw new \RuntimeException(
        'cambis/silverstan is not loaded, so this analysis would report SilverStripe config properties '
        . 'and extension members as errors. It is loaded by the phpstan/extension-installer Composer '
        . 'plugin: run `composer install` with plugins enabled (not `--no-plugins`), with '
        . '`config.allow-plugins.phpstan/extension-installer` true, then re-run PHPStan.'
    );
}
