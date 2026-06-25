<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Cache\FilesystemPsr6Pool;
use ApplicationLogger\Sdk\Clock\FrozenClock;
use ApplicationLogger\Sdk\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/applogger_rl_'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    public function testBurstThenRefill(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $rl = new RateLimiter($clock, new FilesystemPsr6Pool($this->dir), eventsPerSecond: 1.0, burst: 3);

        // 3 tokens available initially (burst).
        self::assertTrue($rl->allow());
        self::assertTrue($rl->allow());
        self::assertTrue($rl->allow());
        self::assertFalse($rl->allow()); // bucket empty

        $clock->advance('+2 seconds'); // +2 tokens at 1/sec
        self::assertTrue($rl->allow());
        self::assertTrue($rl->allow());
        self::assertFalse($rl->allow());
    }

    public function testRetryAfterSuppressesUntilElapsed(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $pool = new FilesystemPsr6Pool($this->dir);
        $rl = new RateLimiter($clock, $pool, eventsPerSecond: 100.0, burst: 100);

        $rl->recordRetryAfter(30);
        self::assertFalse($rl->allow()); // suppressed despite full bucket

        $clock->advance('+31 seconds');
        self::assertTrue($rl->allow()); // suppression elapsed
    }

    public function testNeverThrowsOnDegradedCache(): void
    {
        // FilesystemPsr6Pool with an unwritable path silently swallows errors (no throw).
        // recordRetryAfter() still updates the in-memory memo, so the limiter correctly
        // suppresses subsequent allow() calls — the key invariant is that nothing throws.
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $rl = new RateLimiter($clock, new FilesystemPsr6Pool('/proc/nonexistent/applogger'), eventsPerSecond: 1.0, burst: 1);
        // Must not throw — FilesystemPsr6Pool degrades silently.
        $rl->recordRetryAfter(10);
        // In-memory memo is set immediately; suppression is active.
        self::assertFalse($rl->allow());
        // Advance past the suppression window — allow() must recover.
        $clock->advance('+11 seconds');
        self::assertTrue($rl->allow());
    }
}
