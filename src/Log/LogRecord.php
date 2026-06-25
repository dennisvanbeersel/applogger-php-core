<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Log;

/** One structured log entry, pre-scrub. Immutable. */
final readonly class LogRecord
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $level,
        public string $message,
        public array $context,
        public \DateTimeImmutable $timestamp,
        public ?string $appName,
        public ?string $environment,
    ) {
    }
}
