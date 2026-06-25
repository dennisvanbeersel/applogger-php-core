<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Diagnostic;

/** The result of a diagnostics run: an ordered list of checks. */
final readonly class DiagnosticReport
{
    /** @param list<DiagnosticCheck> $checks */
    public function __construct(public array $checks)
    {
    }

    /** Healthy when no check failed (WARN/INFO do not make it unhealthy). */
    public function isHealthy(): bool
    {
        foreach ($this->checks as $check) {
            if (DiagnosticCheck::FAIL === $check->status) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    public function toArray(): array
    {
        return array_map(
            static fn (DiagnosticCheck $c): array => ['name' => $c->name, 'status' => $c->status, 'detail' => $c->detail],
            $this->checks,
        );
    }
}
