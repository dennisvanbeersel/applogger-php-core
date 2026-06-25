<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

/**
 * Resolves which of the three delivery modes is in effect. Detection is
 * capability-probed (NOT SAPI-string), because RoadRunner/Swoole report SAPI
 * 'cli'. `auto` fails safe toward inline/bounded (cli) when it cannot positively
 * confirm an FPM SAPI.
 */
final class DeliveryMode
{
    public const WEB = 'web';
    public const CLI = 'cli';
    public const WORKER = 'worker';
    public const AUTO = 'auto';

    public static function resolve(string $configured, bool $isWorker, bool $isFastCgi): string
    {
        if (\in_array($configured, [self::WEB, self::CLI, self::WORKER], true)) {
            return $configured;
        }

        // 'auto' (or anything Options already normalized to 'auto')
        if ($isWorker) {
            return self::WORKER;
        }
        if ($isFastCgi) {
            return self::WEB;
        }

        return self::CLI;
    }

    public static function detectWorker(): bool
    {
        return \function_exists('frankenphp_handle_request')
            || class_exists('\Swoole\Coroutine', false)
            || false !== getenv('RR_MODE');
    }

    public static function detectFastCgi(): bool
    {
        return \function_exists('fastcgi_finish_request');
    }

    public static function flushesOnShutdown(string $mode): bool
    {
        return self::WORKER !== $mode;
    }
}
