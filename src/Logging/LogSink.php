<?php

namespace Dynamic\ContentApi\Logging;

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * The logger the module's operational entries (the request log, the
 * population-gate warning) go to: the project's Psr\Log\LoggerInterface.
 * The framework registers that as a Monolog logger with no handler, which
 * drops every record, so a project that never attached one would get no
 * entries and no sign of why. When the logger is a handler-less Monolog
 * logger this says so once per process through PHP's error_log() (#245).
 */
class LogSink
{
    private static bool $reported = false;

    public static function logger(): LoggerInterface
    {
        $logger = Injector::inst()->get(LoggerInterface::class);

        if (!LogSink::$reported && $logger instanceof Logger && $logger->getHandlers() === []) {
            LogSink::$reported = true;
            error_log(
                'dynamic/silverstripe-content-api: Psr\Log\LoggerInterface has no handler, so the module\'s '
                    . 'log entries are dropped. Attach one: see docs/en/02_configuration.md#logging.'
            );
        }

        return $logger;
    }

    /**
     * Let the next handler-less logger be reported again (tests).
     */
    public static function reset(): void
    {
        LogSink::$reported = false;
    }
}
