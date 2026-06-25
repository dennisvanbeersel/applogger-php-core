<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\ErrorPayloadFactory;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Severity;
use PHPUnit\Framework\TestCase;

final class ErrorPayloadFactoryTest extends TestCase
{
    private function event(): Event
    {
        return Event::fromThrowable(
            new \RuntimeException('boom'),
            Severity::Error,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            'production',
            'app@1.0.0',
        );
    }

    public function testProducesContractSafeRequiredFields(): void
    {
        $payload = (new ErrorPayloadFactory())->fromEvent($this->event());

        self::assertSame(\RuntimeException::class, $payload['type']);
        self::assertSame('boom', $payload['message']);
        self::assertNotSame('', $payload['file']);
        self::assertGreaterThanOrEqual(1, $payload['line']);
        self::assertSame('error', $payload['level']);
        self::assertSame('backend', $payload['source']);
        self::assertSame('production', $payload['environment']);
        self::assertSame('app@1.0.0', $payload['release']);
        self::assertSame('2026-01-01T00:00:00+00:00', $payload['timestamp']);
    }

    public function testCoercesEmptyAndZeroToContractSafeValues(): void
    {
        $event = new Event(
            type: '', message: '', file: '', line: 0,
            level: Severity::Fatal,
            environment: 'production', release: null,
            timestamp: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $payload = (new ErrorPayloadFactory())->fromEvent($event);

        self::assertNotSame('', $payload['type']);
        self::assertNotSame('', $payload['message']);
        self::assertNotSame('', $payload['file']);
        self::assertSame(1, $payload['line']);
        self::assertSame('fatal', $payload['level']);
        self::assertArrayNotHasKey('release', $payload);
    }

    public function testCapsFramesAndBreadcrumbs(): void
    {
        $event = $this->event();
        $event->stackTrace = array_fill(0, 300, ['file' => 'a.php', 'line' => 1]);
        $event->breadcrumbs = array_fill(0, 60, new \ApplicationLogger\Sdk\Breadcrumb('nav', 'x', Severity::Info, [], new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'))));
        $payload = (new ErrorPayloadFactory())->fromEvent($event);

        self::assertLessThanOrEqual(250, \count($payload['stack_trace']));
        self::assertLessThanOrEqual(50, \count($payload['breadcrumbs']));
    }
}
