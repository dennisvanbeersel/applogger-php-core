<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Transport;

use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Severity;
use ApplicationLogger\Sdk\Transport\FileTransport;
use PHPUnit\Framework\TestCase;

final class FileTransportTest extends TestCase
{
    public function testWritesEventAsJsonLineAndCanReadItBack(): void
    {
        $path = sys_get_temp_dir().'/applogger_test_'.uniqid('', true).'.jsonl';
        $transport = new FileTransport($path);

        try {
            $event = Event::fromThrowable(
                new \RuntimeException('boom'),
                Severity::Error,
                new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                'production',
                null,
            );
            $transport->send($event);

            $captured = $transport->capturedEvents();
            self::assertCount(1, $captured);
            self::assertSame('boom', $captured[0]['message']);
            self::assertSame('error', $captured[0]['level']);
            self::assertTrue($transport->flush());
        } finally {
            unlink($path);
        }
    }
}
