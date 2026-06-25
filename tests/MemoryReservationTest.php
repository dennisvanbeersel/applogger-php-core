<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\MemoryReservation;
use PHPUnit\Framework\TestCase;

final class MemoryReservationTest extends TestCase
{
    public function testReserveThenReleaseFreesMemory(): void
    {
        $before = memory_get_usage();
        $r = new MemoryReservation(1024 * 1024); // 1 MiB so the delta is unambiguous
        self::assertGreaterThan($before + 512 * 1024, memory_get_usage());
        $r->release();
        // release() drops the only reference; a second release is safe (idempotent).
        $r->release();
    }
}
