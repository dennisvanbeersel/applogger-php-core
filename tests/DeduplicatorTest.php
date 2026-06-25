<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Deduplicator;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Severity;
use PHPUnit\Framework\TestCase;

final class DeduplicatorTest extends TestCase
{
    private function event(string $message, string $file, int $line): Event
    {
        return new Event(
            type: 'RuntimeException', message: $message, file: $file, line: $line,
            level: Severity::Error, environment: 'production', release: null,
            timestamp: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    public function testCollapsesIdenticalEventsAndTagsCount(): void
    {
        $events = [
            $this->event('boom', 'a.php', 10),
            $this->event('boom', 'a.php', 10),
            $this->event('boom', 'a.php', 10),
            $this->event('other', 'b.php', 20),
        ];

        $deduped = (new Deduplicator())->dedupe($events);

        self::assertCount(2, $deduped);
        self::assertSame('boom', $deduped[0]->message);
        self::assertSame(3, $deduped[0]->tags['duplicate_count']);
        self::assertSame('other', $deduped[1]->message);
        self::assertArrayNotHasKey('duplicate_count', $deduped[1]->tags);
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        self::assertSame([], (new Deduplicator())->dedupe([]));
    }
}
