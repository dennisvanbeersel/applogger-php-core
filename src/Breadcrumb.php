<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

final readonly class Breadcrumb
{
    /** @param array<string, scalar|null> $data */
    public function __construct(
        public string $category,
        public string $message,
        public Severity $level,
        public array $data,
        public \DateTimeImmutable $timestamp,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'message' => $this->message,
            'level' => $this->level->toServerLevel(),
            'data' => $this->data,
            'timestamp' => $this->timestamp->format(\DATE_ATOM),
        ];
    }
}
