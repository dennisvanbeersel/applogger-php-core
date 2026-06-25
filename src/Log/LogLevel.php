<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Log;

/**
 * The 8 PSR-3 / syslog severity levels the log collector recognizes as strings.
 * (The SDK's error Severity enum is NOT used for logs — it has `fatal`, which is
 * not a syslog level.).
 */
final class LogLevel
{
    public const INFO = 'info';

    /** @var list<string> */
    public const ALL = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** Lowercase and validate a level; unknown levels fall back to `info` (the collector's default). */
    public static function normalize(string $level): string
    {
        $lower = strtolower($level);

        return \in_array($lower, self::ALL, true) ? $lower : self::INFO;
    }
}
