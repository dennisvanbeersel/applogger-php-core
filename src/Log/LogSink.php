<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Log;

/** The minimal log-ingest surface a PSR-3 adapter needs. Implemented by {@see LogClient}. */
interface LogSink
{
    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void;
}
