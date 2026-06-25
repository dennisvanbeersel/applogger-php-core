<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Cache;

use ApplicationLogger\Sdk\Cache\FilesystemPsr6Pool;
use PHPUnit\Framework\TestCase;

final class FilesystemPsr6PoolTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/applogger_pool_'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    public function testSaveThenGetRoundTrips(): void
    {
        $pool = new FilesystemPsr6Pool($this->dir);
        $item = $pool->getItem('breaker');
        self::assertFalse($item->isHit());

        $item->set(['state' => 'open', 'n' => 5]);
        self::assertTrue($pool->save($item));

        $reloaded = $pool->getItem('breaker');
        self::assertTrue($reloaded->isHit());
        self::assertSame(['state' => 'open', 'n' => 5], $reloaded->get());
    }

    public function testExpiredItemIsMiss(): void
    {
        $pool = new FilesystemPsr6Pool($this->dir);
        $item = $pool->getItem('x')->set('v')->expiresAfter(-1);
        $pool->save($item);

        self::assertFalse($pool->getItem('x')->isHit());
    }
}
