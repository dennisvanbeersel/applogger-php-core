<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Severity;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testFromThrowableCapturesCoreFields(): void
    {
        $e = new \RuntimeException('boom');
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $event = Event::fromThrowable($e, Severity::Error, $now, 'production', 'app@1.0.0');

        self::assertSame(\RuntimeException::class, $event->type);
        self::assertSame('boom', $event->message);
        self::assertSame($e->getFile(), $event->file);
        self::assertSame($e->getLine(), $event->line);
        self::assertSame(Severity::Error, $event->level);
        self::assertSame('production', $event->environment);
        self::assertSame('app@1.0.0', $event->release);
        self::assertFalse($event->partial);
    }

    public function testFingerprintIsStableForSameTypeFileLine(): void
    {
        $e = new \RuntimeException('boom');
        $now = new \DateTimeImmutable('2026-01-01');
        $a = Event::fromThrowable($e, Severity::Error, $now, 'production', null);
        $b = Event::fromThrowable($e, Severity::Error, $now, 'production', null);

        self::assertSame($a->fingerprint(), $b->fingerprint());
    }
}
