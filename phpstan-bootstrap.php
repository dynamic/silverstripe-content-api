<?php

/**
 * PHPStan bootstrap: refuse to analyse without cambis/silverstan.
 *
 * silverstan is what teaches PHPStan that a `private static` config property
 * is read through Config and which members SilverStripe extensions add. It is
 * usually loaded by the phpstan/extension-installer Composer plugin, so an
 * install with `--no-plugins` (or with the plugin disallowed) analyses
 * without it and reports dozens of false errors (#277). Fail once, with the
 * fix, instead.
 *
 * Checked through the container PHPStan passes to bootstrap files, so a
 * project that includes silverstan's extension.neon by hand passes too.
 */

use PHPStan\DependencyInjection\Container;

$loaded = isset($container)
    && $container instanceof Container
    && $container->findServiceNamesByType('Cambis\\Silverstan\\Autoloader\\Autoloader') !== [];

if (!$loaded) {
    throw new \RuntimeException(
        'cambis/silverstan is not loaded, so this analysis would report SilverStripe config properties '
        . 'and extension members as errors. It is loaded by the phpstan/extension-installer Composer '
        . 'plugin: run `composer install` with plugins enabled (not `--no-plugins`), with '
        . '`config.allow-plugins.phpstan/extension-installer` true, then re-run PHPStan.'
    );
}
