<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

final readonly class Dsn
{
    private function __construct(
        public string $raw,
        public string $scheme,
        public string $host,
        public ?int $port,
        public string $projectId,
    ) {
    }

    public static function tryParse(string $dsn): ?self
    {
        $trimmed = trim($dsn);
        if ('' === $trimmed) {
            return null;
        }

        $parts = parse_url($trimmed);
        if (false === $parts || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return null;
        }

        if (!\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $projectId = ltrim($parts['path'], '/');
        if ('' === $projectId || str_contains($projectId, '/')) {
            return null;
        }

        return new self(
            $trimmed,
            $parts['scheme'],
            $parts['host'],
            $parts['port'] ?? null,
            $projectId,
        );
    }

    public function ingestUrl(string $path): string
    {
        $authority = null !== $this->port ? $this->host.':'.$this->port : $this->host;
        $path = '/'.ltrim($path, '/');

        return $this->scheme.'://'.$authority.$path;
    }

    public function __toString(): string
    {
        return '[dsn '.$this->host.'/'.$this->projectId.']';
    }
}
