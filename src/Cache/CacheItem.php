<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Cache;

use Psr\Cache\CacheItemInterface;

final class CacheItem implements CacheItemInterface
{
    public ?int $expiresAt = null;

    public function __construct(
        private readonly string $key,
        private mixed $value = null,
        private bool $hit = false,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->hit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hit = true;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->expiresAt = $expiration?->getTimestamp();

        return $this;
    }

    public function expiresAfter(\DateInterval|int|null $time): static
    {
        if (null === $time) {
            $this->expiresAt = null;
        } elseif ($time instanceof \DateInterval) {
            $this->expiresAt = (new \DateTimeImmutable())->add($time)->getTimestamp();
        } else {
            $this->expiresAt = time() + $time;
        }

        return $this;
    }
}
