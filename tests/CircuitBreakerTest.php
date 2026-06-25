<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Cache\FilesystemPsr6Pool;
use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/applogger_cb_'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    private function breaker(FrozenClock $clock): CircuitBreaker
    {
        return new CircuitBreaker(new FilesystemPsr6Pool($this->dir), $clock, failureThreshold: 3, openSeconds: 60);
    }

    public function testTripsOpenAfterThresholdConsecutiveFailures(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $cb = $this->breaker($clock);

        self::assertTrue($cb->allowRequest());
        $cb->recordFailure();
        $cb->recordFailure();
        self::assertTrue($cb->allowRequest()); // 2 < 3
        $cb->recordFailure();                   // 3rd → OPEN
        self::assertFalse($cb->allowRequest());
    }

    public function testSuccessResetsTheFailureRun(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $cb = $this->breaker($clock);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordSuccess();   // reset
        $cb->recordFailure();
        $cb->recordFailure();
        self::assertTrue($cb->allowRequest()); // only 2 since reset
    }

    public function testHalfOpenAfterTimeoutThenSuccessCloses(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $cb = $this->breaker($clock);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();
        self::assertFalse($cb->allowRequest());     // OPEN

        $clock->advance('+61 seconds');
        self::assertTrue($cb->allowRequest());      // HALF_OPEN admits one probe
        $cb->recordSuccess();                       // recovered → CLOSED
        self::assertTrue($cb->allowRequest());
    }

    public function testNeverThrowsOnDegradedCache(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        // Unwritable directory path → save/get degrade, breaker must not throw.
        $cb = new CircuitBreaker(new FilesystemPsr6Pool('/proc/nonexistent/applogger'), $clock, failureThreshold: 2, openSeconds: 60);
        $cb->recordFailure();
        $cb->recordFailure();
        self::assertTrue($cb->allowRequest());
    }
}
