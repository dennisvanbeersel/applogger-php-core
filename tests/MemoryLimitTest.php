<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\MemoryLimit;
use PHPUnit\Framework\TestCase;

final class MemoryLimitTest extends TestCase
{
    public function testParseSuffixesAndSpecials(): void
    {
        self::assertNull(MemoryLimit::parse('-1'));
        self::assertNull(MemoryLimit::parse(''));
        self::assertNull(MemoryLimit::parse('garbage'));
        self::assertSame(1048576, MemoryLimit::parse('1M'));
        self::assertSame(1048576, MemoryLimit::parse('1m'));
        self::assertSame(262144, MemoryLimit::parse('256K'));
        self::assertSame(1073741824, MemoryLimit::parse('1G'));
        self::assertSame(1048576, MemoryLimit::parse('1048576'));
        self::assertSame(2097152, MemoryLimit::parse(' 2M '));
    }

    public function testBumpIncreasesFiniteLimitAndIsNoopWhenUnlimited(): void
    {
        $original = \ini_get('memory_limit');
        try {
            ini_set('memory_limit', '128M');
            MemoryLimit::bump(5 * 1024 * 1024);
            self::assertSame(128 * 1024 * 1024 + 5 * 1024 * 1024, MemoryLimit::parse((string) \ini_get('memory_limit')));

            ini_set('memory_limit', '-1');
            MemoryLimit::bump(5 * 1024 * 1024); // no-op
            self::assertNull(MemoryLimit::parse((string) \ini_get('memory_limit')));
        } finally {
            ini_set('memory_limit', (string) $original);
        }
    }
}
