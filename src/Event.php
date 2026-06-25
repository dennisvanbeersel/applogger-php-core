<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

final class Event
{
    /**
     * @param array<string, scalar|null> $tags
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $stackTrace
     * @param list<Breadcrumb> $breadcrumbs
     */
    public function __construct(
        public string $type,
        public string $message,
        public string $file,
        public int $line,
        public Severity $level,
        public string $environment,
        public ?string $release,
        public \DateTimeImmutable $timestamp,
        public array $tags = [],
        public array $context = [],
        public array $stackTrace = [],
        public array $breadcrumbs = [],
        public bool $partial = false,
    ) {
    }

    public static function fromThrowable(
        \Throwable $e,
        Severity $level,
        \DateTimeImmutable $now,
        string $environment,
        ?string $release,
    ): self {
        return new self(
            type: $e::class,
            message: $e->getMessage(),
            file: $e->getFile(),
            line: $e->getLine(),
            level: $level,
            environment: $environment,
            release: $release,
            timestamp: $now,
        );
    }

    public function fingerprint(): string
    {
        return sha1($this->type.'|'.$this->file.'|'.$this->line);
    }
}
