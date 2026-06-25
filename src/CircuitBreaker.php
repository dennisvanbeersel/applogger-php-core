<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Consecutive-failure circuit breaker.
 *
 * Trips on N consecutive failures since the last success — a sustained-failure
 * signal — so it tolerates lost updates on a non-CAS shared store: a dropped
 * increment only delays tripping by one signal under a real (sustained) outage.
 * Time is injected for deterministic testing. All methods are total (never throw).
 * HALF_OPEN single-probe admission is best-effort: with a non-CAS shared store (e.g. a filesystem pool across FrankenPHP workers) a brief race can admit more than one probe — safe, since extra probes just re-test a recovering collector.
 */
final class CircuitBreaker
{
    private const CLOSED = 'closed';
    private const OPEN = 'open';
    private const HALF_OPEN = 'half_open';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ClockInterface $clock,
        private readonly int $failureThreshold = 5,
        private readonly int $openSeconds = 60,
        private readonly string $cacheKey = 'applogger.breaker',
        private readonly int $jitterSeconds = 0,
    ) {
    }

    public function allowRequest(): bool
    {
        try {
            $state = $this->load();
            if (self::OPEN !== $state['state']) {
                return true;
            }
            $elapsed = $this->now() - (int) $state['openedAt'];
            if ($elapsed >= $this->openSeconds + $this->jitter()) {
                $state['state'] = self::HALF_OPEN;
                $this->store($state);

                return true; // admit one probe
            }

            return false;
        } catch (\Throwable) {
            return true; // degrade open (never block the host's telemetry on breaker errors)
        }
    }

    public function recordSuccess(): void
    {
        try {
            $this->store(['state' => self::CLOSED, 'failures' => 0, 'openedAt' => 0]);
        } catch (\Throwable) {
        }
    }

    public function recordFailure(): void
    {
        try {
            $state = $this->load();
            if (self::HALF_OPEN === $state['state']) {
                $this->store(['state' => self::OPEN, 'failures' => (int) $state['failures'], 'openedAt' => $this->now()]);

                return;
            }
            $failures = (int) $state['failures'] + 1;
            if ($failures >= $this->failureThreshold) {
                $this->store(['state' => self::OPEN, 'failures' => $failures, 'openedAt' => $this->now()]);

                return;
            }
            $this->store(['state' => self::CLOSED, 'failures' => $failures, 'openedAt' => 0]);
        } catch (\Throwable) {
        }
    }

    /** @return array{state: string, failures: int, openedAt: int} */
    private function load(): array
    {
        $item = $this->cache->getItem($this->cacheKey);
        $data = $item->isHit() ? $item->get() : null;
        if (!\is_array($data) || !isset($data['state'], $data['failures'], $data['openedAt'])) {
            return ['state' => self::CLOSED, 'failures' => 0, 'openedAt' => 0];
        }

        return ['state' => (string) $data['state'], 'failures' => (int) $data['failures'], 'openedAt' => (int) $data['openedAt']];
    }

    /** @param array{state: string, failures: int, openedAt: int} $state */
    private function store(array $state): void
    {
        $item = $this->cache->getItem($this->cacheKey);
        $item->set($state)->expiresAfter(max(3600, $this->openSeconds * 4));
        $this->cache->save($item);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function jitter(): int
    {
        return $this->jitterSeconds > 0 ? random_int(0, $this->jitterSeconds) : 0;
    }
}
