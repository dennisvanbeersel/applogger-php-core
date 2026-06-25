<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Diagnostic;

/** One diagnostic check result. */
final readonly class DiagnosticCheck
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const INFO = 'info';

    public function __construct(
        public string $name,
        public string $status,
        public string $detail,
    ) {
    }
}
