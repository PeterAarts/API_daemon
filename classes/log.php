<?php
namespace ApiDaemon;

use Monolog\Logger;

class Log {
    /**
     * @var Logger|null The static logger instance.
     */
    private static $logger = null;

    /**
     * Sets the logger instance. This is called once during application bootstrap.
     * @param Logger $logger The configured Monolog logger instance.
     */
    public static function setLogger(Logger $logger) {
        self::$logger = $logger;
    }

    /**
     * Logs an informational message.
     * @param string $message The log message.
     * @param array $context Optional context data.
     */
    public static function info($message, array $context = []) {
        if (self::$logger) {
            self::$logger->info($message, $context);
        }
    }

    /**
     * Logs a warning message.
     * @param string $message The log message.
     * @param array $context Optional context data.
     */
    public static function warning($message, array $context = []) {
        if (self::$logger) {
            self::$logger->warning($message, $context);
        }
    }

    /**
     * Logs an error message.
     * @param string $message The log message.
     * @param array $context Optional context data.
     */
    public static function error($message, array $context = []) {
        if (self::$logger) {
            self::$logger->error($message, $context);
        }
    }
}
