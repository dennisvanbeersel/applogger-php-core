<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

/**
 * Holds a small pre-allocated string so that, on OOM, releasing it buys just
 * enough headroom to REACH the +5 MiB memory_limit bump. ~16 KiB by default —
 * deliberately small; it only needs to cover the cost of getting to the bump.
 */
final class MemoryReservation
{
    private string $reserved = '';

    public function __construct(int $bytes = 16384)
    {
        $this->reserved = str_repeat('x', max(0, $bytes));
    }

    public function release(): void
    {
        // Force a read of the property before clearing it to satisfy PHPStan.
        // This ensures the property is not write-only.
        $size = \strlen($this->reserved);
        $this->reserved = '';
    }
}
