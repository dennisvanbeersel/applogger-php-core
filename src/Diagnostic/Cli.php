<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Diagnostic;

/** Plain-PHP CLI for `applogger diagnose` (no console framework). All static; total. */
final class Cli
{
    private const ENV_MAP = [
        'dsn' => 'APPLOGGER_DSN',
        'log_endpoint' => 'APPLOGGER_LOG_ENDPOINT',
        'log_token' => 'APPLOGGER_LOG_TOKEN',
        'app_name' => 'APPLOGGER_APP_NAME',
        'cache_dir' => 'APPLOGGER_CACHE_DIR',
    ];

    /**
     * @param list<string> $argv
     *
     * @return array{command: string, options: array<string, mixed>, json: bool, sendTestEvent: bool, help: bool}
     */
    public static function parseArgs(array $argv): array
    {
        $command = $argv[1] ?? 'diagnose';
        $options = [];
        $json = false;
        $sendTestEvent = true;
        $help = false;

        foreach (\array_slice($argv, 2) as $arg) {
            if ('--json' === $arg) {
                $json = true;
            } elseif ('--no-send' === $arg) {
                $sendTestEvent = false;
            } elseif ('--help' === $arg || '-h' === $arg) {
                $help = true;
            } elseif (str_starts_with($arg, '--dsn=')) {
                $options['dsn'] = substr($arg, 6);
            } elseif (str_starts_with($arg, '--log-endpoint=')) {
                $options['log_endpoint'] = substr($arg, 15);
            } elseif (str_starts_with($arg, '--log-token=')) {
                $options['log_token'] = substr($arg, 12);
            } elseif (str_starts_with($arg, '--app-name=')) {
                $options['app_name'] = substr($arg, 11);
            } elseif (str_starts_with($arg, '--cache-dir=')) {
                $options['cache_dir'] = substr($arg, 12);
            }
        }

        return ['command' => $command, 'options' => $options, 'json' => $json, 'sendTestEvent' => $sendTestEvent, 'help' => $help];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string|false> $env
     *
     * @return array<string, mixed>
     */
    public static function mergeEnv(array $options, array $env): array
    {
        foreach (self::ENV_MAP as $key => $envVar) {
            if (!isset($options[$key]) && isset($env[$envVar]) && \is_string($env[$envVar]) && '' !== $env[$envVar]) {
                $options[$key] = $env[$envVar];
            }
        }

        return $options;
    }

    public static function render(DiagnosticReport $report, bool $json): string
    {
        if ($json) {
            return json_encode(
                ['healthy' => $report->isHealthy(), 'checks' => $report->toArray()],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            )."\n";
        }

        $lines = ['AppLogger SDK diagnostics', ''];
        foreach ($report->checks as $check) {
            $lines[] = \sprintf('  [%-4s] %-12s %s', strtoupper($check->status), $check->name, $check->detail);
        }
        $lines[] = '';
        $lines[] = 'Result: '.($report->isHealthy() ? 'healthy' : 'unhealthy');

        return implode("\n", $lines)."\n";
    }

    public static function usage(): string
    {
        return <<<TXT
            applogger diagnose — check an AppLogger SDK configuration

            Usage:
              applogger diagnose [options]

            Options:
              --dsn=URL            Error-tracking DSN (scheme://host/project-id)
              --log-endpoint=URL   Log endpoint (https://{slug}.logs.applogger.eu)
              --log-token=TOKEN    Log token (sk_log_...)
              --app-name=NAME      Application name for logs
              --cache-dir=PATH     Cache directory for circuit-breaker/rate-limit state
              --no-send            Do not send a real test event
              --json               Machine-readable JSON output
              -h, --help           Show this help

            Environment fallbacks: APPLOGGER_DSN, APPLOGGER_LOG_ENDPOINT,
            APPLOGGER_LOG_TOKEN, APPLOGGER_APP_NAME, APPLOGGER_CACHE_DIR.

            Exit code: 0 = healthy, 1 = a check failed.

            TXT;
    }

    /**
     * @param list<string> $argv
     * @param array<string, string|false> $env
     */
    public static function main(array $argv, array $env): int
    {
        try {
            $parsed = self::parseArgs($argv);
            if ($parsed['help']) {
                echo self::usage();

                return 0;
            }

            $options = self::mergeEnv($parsed['options'], $env);
            $report = (new Diagnostics())->run($options, $parsed['sendTestEvent']);
            echo self::render($report, $parsed['json']);

            return $report->isHealthy() ? 0 : 1;
        } catch (\Throwable $t) {
            fwrite(\STDERR, 'applogger diagnose: internal error: '.$t::class."\n");

            return 1;
        }
    }
}
