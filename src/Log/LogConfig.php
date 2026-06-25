<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Log;

/**
 * Configuration for the log pipeline, parsed from the same options array passed to
 * init(). The log endpoint + token are independent of the error DSN. Total.
 */
final readonly class LogConfig
{
    /** @param list<string> $scrubFields */
    public function __construct(
        public ?string $endpoint,
        public ?string $token,
        public ?string $appName,
        public ?string $environment,
        public float $timeout,
        public int $maxBufferedLogs,
        public float $flushBudget,
        public string $cacheDir,
        public array $scrubFields,
    ) {
    }

    public function isEnabled(): bool
    {
        return null !== $this->endpoint && null !== $this->token;
    }

    public function batchUrl(): ?string
    {
        return $this->isEnabled() ? $this->endpoint.'/v1/logs/batch' : null;
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $token = \is_string($input['log_token'] ?? null) && '' !== $input['log_token'] ? $input['log_token'] : null;

        return new self(
            endpoint: self::normalizeEndpoint($input['log_endpoint'] ?? null),
            token: $token,
            appName: \is_string($input['app_name'] ?? null) && '' !== $input['app_name'] ? $input['app_name'] : null,
            environment: \is_string($input['environment'] ?? null) ? $input['environment'] : null,
            timeout: self::clampFloat($input['timeout'] ?? 2.0, 0.5, 5.0, 2.0),
            maxBufferedLogs: max(1, (int) ($input['max_buffered_logs'] ?? 100)),
            flushBudget: self::clampFloat($input['flush_budget'] ?? 2.0, 0.05, 5.0, 2.0),
            cacheDir: \is_string($input['cache_dir'] ?? null) && '' !== $input['cache_dir']
                ? $input['cache_dir']
                : sys_get_temp_dir().'/applogger-sdk',
            scrubFields: \is_array($input['scrub_fields'] ?? null)
                ? array_values(array_filter($input['scrub_fields'], static fn (mixed $v): bool => \is_string($v) && '' !== $v))
                : ['password', 'passwd', 'secret', 'token', 'api_key', 'auth', 'credential'],
        );
    }

    private static function normalizeEndpoint(mixed $raw): ?string
    {
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        $parts = parse_url($raw);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (!\in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }
        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $parts['scheme'].'://'.$authority;
    }

    private static function clampFloat(mixed $value, float $min, float $max, float $default): float
    {
        $f = is_numeric($value) ? (float) $value : $default;

        return max($min, min($max, $f));
    }
}
