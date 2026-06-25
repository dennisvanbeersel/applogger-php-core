<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Diagnostic;

use ApplicationLogger\Sdk\Diagnostic\Cli;
use ApplicationLogger\Sdk\Diagnostic\DiagnosticCheck;
use ApplicationLogger\Sdk\Diagnostic\DiagnosticReport;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    public function testParseArgsFlagsAndValues(): void
    {
        $parsed = Cli::parseArgs(['applogger', 'diagnose', '--dsn=https://applogger.eu/0xP', '--json', '--no-send']);
        self::assertSame('diagnose', $parsed['command']);
        self::assertSame('https://applogger.eu/0xP', $parsed['options']['dsn']);
        self::assertTrue($parsed['json']);
        self::assertFalse($parsed['sendTestEvent']);
        self::assertFalse($parsed['help']);
    }

    public function testParseArgsCacheDirFlag(): void
    {
        $parsed = Cli::parseArgs(['applogger', 'diagnose', '--cache-dir=/tmp/x']);
        self::assertSame('/tmp/x', $parsed['options']['cache_dir']);
    }

    public function testMergeEnvFillsUnsetOptions(): void
    {
        $merged = Cli::mergeEnv(['dsn' => 'https://from-flag/0xP'], [
            'APPLOGGER_DSN' => 'https://from-env/0xP',
            'APPLOGGER_LOG_TOKEN' => 'sk_log_env',
        ]);
        self::assertSame('https://from-flag/0xP', $merged['dsn']); // flag wins
        self::assertSame('sk_log_env', $merged['log_token']);      // env fills the gap
    }

    public function testMergeEnvFillsCacheDirFromEnv(): void
    {
        $merged = Cli::mergeEnv([], ['APPLOGGER_CACHE_DIR' => '/var/cache/applogger']);
        self::assertSame('/var/cache/applogger', $merged['cache_dir']);
    }

    public function testMergeEnvCacheDirFlagWinsOverEnv(): void
    {
        $merged = Cli::mergeEnv(['cache_dir' => '/tmp/flag'], ['APPLOGGER_CACHE_DIR' => '/tmp/env']);
        self::assertSame('/tmp/flag', $merged['cache_dir']); // explicit flag wins
    }

    public function testRenderTextContainsStatusesAndResult(): void
    {
        $report = new DiagnosticReport([
            new DiagnosticCheck('DSN', DiagnosticCheck::OK, 'host=x'),
            new DiagnosticCheck('Transport', DiagnosticCheck::FAIL, 'no client'),
        ]);
        $text = Cli::render($report, false);
        self::assertStringContainsString('DSN', $text);
        self::assertStringContainsString('no client', $text);
        self::assertStringContainsString('unhealthy', $text);
    }

    public function testRenderJsonIsValid(): void
    {
        $report = new DiagnosticReport([new DiagnosticCheck('DSN', DiagnosticCheck::OK, 'host=x')]);
        $json = Cli::render($report, true);
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['healthy']);
        self::assertSame('DSN', $decoded['checks'][0]['name']);
    }

    public function testMainReturnsNonZeroWhenUnhealthyAndZeroForHelp(): void
    {
        // --help short-circuits with exit 0
        ob_start();
        $code = Cli::main(['applogger', 'diagnose', '--help'], []);
        ob_end_clean();
        self::assertSame(0, $code);

        // No DSN → DSN check fails → unhealthy → exit 1. --no-send avoids a real network call.
        ob_start();
        $code = Cli::main(['applogger', 'diagnose', '--no-send'], []);
        $out = (string) ob_get_clean();
        self::assertSame(1, $code);
        self::assertStringContainsString('DSN', $out);
    }
}
