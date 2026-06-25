<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class OomChildProcessTest extends TestCase
{
    public function testOomFatalIsCapturedAndDeliveredToTransport(): void
    {
        $php = \PHP_BINARY;
        $fixture = __DIR__.'/oom-fixture.php';
        $out = sys_get_temp_dir().'/applogger_oom_'.uniqid('', true).'.ndjson';

        $cmd = [
            $php,
            '-d', 'memory_limit=32M',
            '-d', 'opcache.enable_cli=0',
            $fixture,
            $out,
        ];

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($proc)) {
            self::markTestSkipped('Could not spawn child PHP process.');
        }
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        // Sanity: the child must actually have OOM'd. If not, distinguish between a
        // code regression (non-OOM PHP fatal) and a platform-level SIGKILL with no output.
        if (!str_contains((string) $stderr, 'Allowed memory size')) {
            @unlink($out);
            if (preg_match('/PHP (Fatal error|Parse error)|Uncaught|Error:/i', (string) $stderr)) {
                self::fail('OOM fixture emitted a non-OOM PHP error (exit '.$exitCode.'): '.substr((string) $stderr, 0, 300));
            }
            self::markTestSkipped('Child did not produce a deterministic OOM fatal (exit '.$exitCode.'): '.substr((string) $stderr, 0, 200));
        }

        $rows = is_file($out)
            ? array_values(array_filter(explode("\n", (string) file_get_contents($out)), static fn (string $l): bool => '' !== $l))
            : [];
        @unlink($out);

        self::assertNotEmpty($rows, 'OOM fatal must be captured and written to the transport before process death');
        $event = json_decode($rows[0], true, 512, \JSON_THROW_ON_ERROR);

        // The DISCRIMINATING assertion (not merely "an event exists"): the oom marker.
        self::assertTrue($event['tags']['oom'] ?? false, 'event must carry the oom:true marker');
        self::assertTrue($event['partial'] ?? false);
        self::assertSame('fatal', $event['level']);
        self::assertGreaterThanOrEqual(1, $event['line']);
        self::assertNotSame('', $event['type']);
        self::assertNotSame('', $event['message']);
    }
}
