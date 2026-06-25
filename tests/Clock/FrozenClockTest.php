<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Clock;

use ApplicationLogger\Sdk\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;

final class FrozenClockTest extends TestCase
{
    public function testNowIsStableUntilAdvanced(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        self::assertSame('2026-01-01T00:00:00+00:00', $clock->now()->format(\DATE_ATOM));

        $clock->advance('+90 seconds');
        self::assertSame('2026-01-01T00:01:30+00:00', $clock->now()->format(\DATE_ATOM));
    }

    public function testAdvanceRejectsInvalidModifier(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->expectException(\InvalidArgumentException::class);
        $clock->advance('not-a-duration');
    }
}
