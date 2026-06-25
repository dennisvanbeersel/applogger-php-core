<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Options;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OptionsTest extends TestCase
{
    public function testParsesDsnAndDefaults(): void
    {
        $o = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);

        self::assertNotNull($o->dsn);
        self::assertSame('0xP', $o->dsn->projectId);
        self::assertSame('production', $o->environment);
        self::assertTrue($o->enabled);
        self::assertSame(1.0, $o->sampleRate);
    }

    public function testInvalidDsnBecomesNullNeverThrows(): void
    {
        $o = Options::fromArray(['dsn' => 'garbage']);
        self::assertNull($o->dsn);
    }

    public function testSampleRateIsClampedAndBreakerParamsFloored(): void
    {
        $o = Options::fromArray([
            'dsn' => 'https://applogger.eu/0xP',
            'sample_rate' => 5.0,
            'circuit_breaker' => ['timeout' => 2, 'failure_threshold' => 0, 'half_open_attempts' => 0],
        ]);

        self::assertSame(1.0, $o->sampleRate);
        self::assertGreaterThanOrEqual(10, $o->circuitBreakerTimeout);
        self::assertGreaterThanOrEqual(1, $o->failureThreshold);
        self::assertGreaterThanOrEqual(1, $o->halfOpenAttempts);
    }

    public function testUnknownKeysAreIgnoredNotThrown(): void
    {
        $o = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'nonsense_key' => true, 'logger' => new NullLogger()]);
        self::assertNotNull($o->dsn);
    }

    public function testNegativeSampleRateClampedToZero(): void
    {
        $o = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'sample_rate' => -2.0]);
        self::assertSame(0.0, $o->sampleRate);
    }

    public function testNonNumericSampleRateClampedToDefault(): void
    {
        $o = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'sample_rate' => 'not-a-number']);
        self::assertSame(1.0, $o->sampleRate); // non-numeric falls back to the safe default (1.0)
    }

    public function testEnabledStringFalseIsRespected(): void
    {
        self::assertFalse(Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'enabled' => 'false'])->enabled);
        self::assertFalse(Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'enabled' => '0'])->enabled);
    }

    public function testEnabledStringTrueIsRespected(): void
    {
        self::assertTrue(Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'enabled' => 'true'])->enabled);
        self::assertTrue(Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'enabled' => '1'])->enabled);
    }

    public function testBufferAndFlushBudgetDefaults(): void
    {
        $o = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        self::assertSame(100, $o->maxBufferedEvents);
        self::assertSame(2.0, $o->flushBudget);
    }

    public function testNonNumericFlushBudgetFallsBackToDefault(): void
    {
        $o = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'flush_budget' => 'nonsense']);
        self::assertSame(2.0, $o->flushBudget);
    }

    public function testTimeoutDefaultAndClampAndCacheDir(): void
    {
        $o = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        self::assertSame(2.0, $o->timeout);
        self::assertStringContainsString('applogger-sdk', $o->cacheDir);

        $clamped = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'timeout' => 99.0]);
        self::assertSame(5.0, $clamped->timeout);
        $floored = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'timeout' => 0.01]);
        self::assertSame(0.5, $floored->timeout);
    }

    public function testSessionHashSaltDefaultAndOverride(): void
    {
        $o = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        self::assertNotSame('', $o->sessionHashSalt);

        $custom = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'session_hash_salt' => 'my-salt']);
        self::assertSame('my-salt', $custom->sessionHashSalt);
    }

    public function testFlushModeAndDefaultIntegrationsDefaultsAndOverrides(): void
    {
        $o = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        self::assertSame('auto', $o->flushMode);
        self::assertTrue($o->defaultIntegrations);

        $custom = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'flush_mode' => 'worker', 'default_integrations' => false]);
        self::assertSame('worker', $custom->flushMode);
        self::assertFalse($custom->defaultIntegrations);

        // invalid flush_mode falls back to 'auto'
        $bad = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'flush_mode' => 'nonsense']);
        self::assertSame('auto', $bad->flushMode);
    }

    public function testLogKeysDoNotTriggerUnknownOptionWarning(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                if ('warning' === $level) {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        Options::fromArray([
            'log_endpoint' => 'https://app.logs.applogger.eu',
            'log_token' => 'sk_log_x',
            'app_name' => 'svc',
            'logger' => $logger,
        ]);

        $unknownWarnings = array_filter($logger->warnings, static fn (string $w): bool => str_contains($w, 'unknown option'));
        self::assertCount(0, $unknownWarnings, 'log_endpoint/log_token/app_name must not trigger unknown-option warnings');
    }
}
