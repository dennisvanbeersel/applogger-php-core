<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Stats;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    public function testToArrayIncludesAllDroppedReasonsInSnakeCase(): void
    {
        $stats = new Stats();
        $stats->sent = 3;
        $stats->droppedBreaker = 1;
        $stats->droppedBudget = 2;
        $stats->droppedError = 4;

        $array = $stats->toArray();

        self::assertSame(3, $array['sent']);
        self::assertSame(1, $array['dropped_breaker']);
        self::assertSame(2, $array['dropped_budget']);
        self::assertSame(4, $array['dropped_error']);
        // existing keys still present
        self::assertArrayHasKey('dropped_sampled', $array);
        self::assertArrayHasKey('dropped_before_send', $array);
        self::assertArrayHasKey('dropped_disabled', $array);
    }

    public function testRateLimitAndDedupCountersMergeAndSerialise(): void
    {
        $a = new Stats();
        $a->droppedRateLimit = 2;
        $a->droppedDedup = 5;
        $b = new Stats();
        $b->droppedRateLimit = 3;

        $merged = $a->plus($b);
        self::assertSame(5, $merged->droppedRateLimit);
        self::assertSame(5, $merged->droppedDedup);

        $array = $merged->toArray();
        self::assertSame(5, $array['dropped_rate_limit']);
        self::assertSame(5, $array['dropped_dedup']);
    }
}
