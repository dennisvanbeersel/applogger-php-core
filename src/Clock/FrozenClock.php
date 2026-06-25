<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Clock;

use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        try {
            $this->now = $this->now->modify($modifier);
        } catch (\DateMalformedStringException $e) {
            throw new \InvalidArgumentException(\sprintf('Invalid clock modifier: "%s".', $modifier), previous: $e);
        }
    }
}
