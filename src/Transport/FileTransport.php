<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Transport;

use ApplicationLogger\Sdk\Breadcrumb;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Stats;

final class FileTransport implements TransportInterface
{
    private Stats $stats;

    public function __construct(private readonly string $path)
    {
        $this->stats = new Stats();
    }

    public function send(Event $event): void
    {
        $row = [
            'type' => $event->type,
            'message' => $event->message,
            'file' => $event->file,
            'line' => $event->line,
            'level' => $event->level->toServerLevel(),
            'environment' => $event->environment,
            'release' => $event->release,
            'timestamp' => $event->timestamp->format(\DATE_ATOM),
            'partial' => $event->partial,
            'tags' => $event->tags,
            'context' => $event->context,
            'stack_trace' => $event->stackTrace,
            'breadcrumbs' => array_map(static fn (Breadcrumb $b): array => $b->toArray(), $event->breadcrumbs),
        ];

        file_put_contents($this->path, json_encode($row, \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
        ++$this->stats->sent;
    }

    public function flush(?float $budgetSeconds = null): bool
    {
        return true;
    }

    public function getStats(): Stats
    {
        return $this->stats;
    }

    /** @return list<array<string, mixed>> */
    public function capturedEvents(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $lines = array_filter(explode("\n", (string) file_get_contents($this->path)), static fn (string $l): bool => '' !== $l);

        return array_map(
            static fn (string $line): array => (array) json_decode($line, true, 512, \JSON_THROW_ON_ERROR),
            array_values($lines),
        );
    }
}
