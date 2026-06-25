<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Log;

use ApplicationLogger\Sdk\Log\LogRecord;
use PHPUnit\Framework\TestCase;

final class LogRecordTest extends TestCase
{
    public function testHoldsValues(): void
    {
        $ts = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $r = new LogRecord('error', 'boom', ['k' => 'v'], $ts, 'myapp', 'production');
        self::assertSame('error', $r->level);
        self::assertSame('boom', $r->message);
        self::assertSame(['k' => 'v'], $r->context);
        self::assertSame($ts, $r->timestamp);
        self::assertSame('myapp', $r->appName);
        self::assertSame('production', $r->environment);
    }
}
