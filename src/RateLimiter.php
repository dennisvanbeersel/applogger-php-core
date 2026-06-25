<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Per-process token-bucket rate limiter with a PSR-6-shared 429 suppression window.
 *
 * The bucket bounds THIS process's outbound event rate (the self-amplification
 * damper). A server 429/Retry-After is persisted to the shared pool so sibling
 * workers also back off, not just the one that received it. Total (never throws):
 * degrades to ALLOW on any internal fault.
 */
final class RateLimiter
{
    private const SUPPRESSION_STALENESS = 1; // seconds

    private float $tokens;
    private float $lastRefill;
    private ?int $suppressedUntilMemo = null;
    private int $suppressionCheckedAt = 0;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly CacheItemPoolInterface $cache,
        private readonly float $eventsPerSecond = 10.0,
        private readonly int $burst = 20,
        private readonly string $cacheKey = 'applogger.ratelimit',
    ) {
        $this->tokens = (float) $burst;
        $this->lastRefill = $this->micro();
    }

    public function allow(): bool
    {
        try {
            if ($this->isSuppressed()) {
                return false;
            }
            $this->refill();
            if ($this->tokens < 1.0) {
                return false;
            }
            $this->tokens -= 1.0;

            return true;
        } catch (\Throwable) {
            return true; // never block the host's telemetry on a limiter fault
        }
    }

    public function recordRetryAfter(int $seconds): void
    {
        try {
            if ($seconds <= 0) {
                return;
            }
            $item = $this->cache->getItem($this->cacheKey);
            $item->set(['suppressedUntil' => $this->now() + $seconds])->expiresAfter($seconds + 1);
            $this->cache->save($item);
            // Update memo immediately so THIS process respects its own 429 without
            // waiting for the staleness window to expire.
            $this->suppressedUntilMemo = $this->now() + $seconds;
            $this->suppressionCheckedAt = $this->now();
        } catch (\Throwable) {
        }
    }

    private function isSuppressed(): bool
    {
        $now = $this->now();
        if ($now - $this->suppressionCheckedAt >= self::SUPPRESSION_STALENESS) {
            try {
                $item = $this->cache->getItem($this->cacheKey);
                $data = $item->isHit() ? $item->get() : null;
                $this->suppressedUntilMemo = (\is_array($data) && isset($data['suppressedUntil']))
                    ? (int) $data['suppressedUntil']
                    : null;
            } catch (\Throwable) {
                // Degrade to allow: leave suppressedUntilMemo as-is (null on first fault).
            }
            $this->suppressionCheckedAt = $now;
        }

        return null !== $this->suppressedUntilMemo && $now < $this->suppressedUntilMemo;
    }

    private function refill(): void
    {
        $now = $this->micro();
        $elapsed = max(0.0, $now - $this->lastRefill);
        $this->lastRefill = $now;
        $this->tokens = min((float) $this->burst, $this->tokens + $elapsed * $this->eventsPerSecond);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function micro(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
