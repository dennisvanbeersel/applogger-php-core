<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\DeliveryMode;
use PHPUnit\Framework\TestCase;

final class DeliveryModeTest extends TestCase
{
    public function testExplicitModeWins(): void
    {
        self::assertSame('worker', DeliveryMode::resolve('worker', false, true));
        self::assertSame('web', DeliveryMode::resolve('web', true, false));
        self::assertSame('cli', DeliveryMode::resolve('cli', true, true));
    }

    public function testAutoPrefersWorkerThenFastCgiThenCliFailSafe(): void
    {
        self::assertSame('worker', DeliveryMode::resolve('auto', true, true));
        self::assertSame('web', DeliveryMode::resolve('auto', false, true));
        self::assertSame('cli', DeliveryMode::resolve('auto', false, false));
    }

    public function testFlushesOnShutdownDisabledForWorker(): void
    {
        self::assertFalse(DeliveryMode::flushesOnShutdown('worker'));
        self::assertTrue(DeliveryMode::flushesOnShutdown('web'));
        self::assertTrue(DeliveryMode::flushesOnShutdown('cli'));
    }
}
