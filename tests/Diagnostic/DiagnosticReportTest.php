<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Diagnostic;

use ApplicationLogger\Sdk\Diagnostic\DiagnosticCheck;
use ApplicationLogger\Sdk\Diagnostic\DiagnosticReport;
use PHPUnit\Framework\TestCase;

final class DiagnosticReportTest extends TestCase
{
    public function testHealthyWhenNoFailures(): void
    {
        $report = new DiagnosticReport([
            new DiagnosticCheck('DSN', DiagnosticCheck::OK, 'parsed'),
            new DiagnosticCheck('Cache', DiagnosticCheck::WARN, 'not writable'),
            new DiagnosticCheck('Logging', DiagnosticCheck::INFO, 'not configured'),
        ]);
        self::assertTrue($report->isHealthy());
    }

    public function testUnhealthyWhenAnyFailure(): void
    {
        $report = new DiagnosticReport([
            new DiagnosticCheck('DSN', DiagnosticCheck::OK, 'parsed'),
            new DiagnosticCheck('Transport', DiagnosticCheck::FAIL, 'no PSR-18 client'),
        ]);
        self::assertFalse($report->isHealthy());
    }

    public function testToArray(): void
    {
        $report = new DiagnosticReport([new DiagnosticCheck('DSN', DiagnosticCheck::OK, 'parsed')]);
        self::assertSame([['name' => 'DSN', 'status' => 'ok', 'detail' => 'parsed']], $report->toArray());
    }
}
