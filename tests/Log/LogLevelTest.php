<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Log;

use ApplicationLogger\Sdk\Log\LogLevel;
use PHPUnit\Framework\TestCase;

final class LogLevelTest extends TestCase
{
    public function testNormalizeKnownLevelsCaseInsensitive(): void
    {
        self::assertSame('error', LogLevel::normalize('error'));
        self::assertSame('warning', LogLevel::normalize('WARNING'));
        self::assertSame('debug', LogLevel::normalize('Debug'));
        self::assertSame('emergency', LogLevel::normalize('emergency'));
    }

    public function testNormalizeUnknownFallsBackToInfo(): void
    {
        self::assertSame('info', LogLevel::normalize('verbose'));
        self::assertSame('info', LogLevel::normalize(''));
        self::assertSame('info', LogLevel::normalize('fatal')); // not a PSR-3/syslog level
    }

    public function testAllContainsTheEightPsr3Levels(): void
    {
        self::assertSame(
            ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
            LogLevel::ALL,
        );
    }
}
