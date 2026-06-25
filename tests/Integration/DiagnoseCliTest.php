<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class DiagnoseCliTest extends TestCase
{
    /**
     * @param list<string> $args
     *
     * @return array{out: string, err: string, code: int}
     */
    private function runCli(array $args): array
    {
        $bin = \dirname(__DIR__, 2).'/bin/applogger';
        $cmd = array_merge([\PHP_BINARY, $bin], $args);
        // Strip all APPLOGGER_* vars so a DSN in the runner environment cannot
        // cause testNoDsnIsUnhealthyExitOne to pass the DSN check and return exit 0.
        $env = array_filter(getenv(), static fn (string $k): bool => !str_starts_with($k, 'APPLOGGER_'), \ARRAY_FILTER_USE_KEY);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!\is_resource($proc)) {
            self::markTestSkipped('Could not spawn the CLI process.');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['out' => $out, 'err' => $err, 'code' => $code];
    }

    public function testHelpExitsZero(): void
    {
        $r = $this->runCli(['diagnose', '--help']);
        self::assertSame(0, $r['code']);
        self::assertStringContainsString('applogger diagnose', $r['out']);
    }

    public function testNoDsnIsUnhealthyExitOne(): void
    {
        // --no-send avoids a real network call; no DSN → DSN check fails → exit 1.
        $r = $this->runCli(['diagnose', '--no-send']);
        self::assertSame(1, $r['code']);
        self::assertStringContainsString('DSN', $r['out']);
        self::assertStringContainsString('unhealthy', $r['out']);
    }

    public function testJsonOutputIsValid(): void
    {
        $r = $this->runCli(['diagnose', '--no-send', '--json']);
        $decoded = json_decode($r['out'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('healthy', $decoded);
        self::assertArrayHasKey('checks', $decoded);
    }
}
