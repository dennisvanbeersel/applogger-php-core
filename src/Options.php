<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class Options
{
    private const KNOWN_KEYS = [
        'dsn', 'api_key', 'environment', 'release', 'enabled', 'sample_rate',
        'max_breadcrumbs', 'logger', 'before_send', 'circuit_breaker',
        'max_buffered_events', 'flush_budget', 'timeout', 'cache_dir', 'session_hash_salt',
        'scrub_fields', 'flush_mode', 'default_integrations',
        'log_endpoint', 'log_token', 'app_name',
    ];

    public function __construct(
        public ?Dsn $dsn,
        public ?string $apiKey,
        public string $environment,
        public ?string $release,
        public bool $enabled,
        public float $sampleRate,
        public int $maxBreadcrumbs,
        public LoggerInterface $logger,
        public ?\Closure $beforeSend,
        public int $circuitBreakerTimeout,
        public int $failureThreshold,
        public int $halfOpenAttempts,
        public int $maxBufferedEvents,
        public float $flushBudget,
        public float $timeout,
        public string $cacheDir,
        public string $sessionHashSalt,
        /** @var list<string> */
        public array $scrubFields,
        public string $flushMode,
        public bool $defaultIntegrations,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $logger = ($input['logger'] ?? null) instanceof LoggerInterface ? $input['logger'] : new NullLogger();

        foreach (array_keys($input) as $key) {
            if (!\in_array($key, self::KNOWN_KEYS, true)) {
                $logger->warning('AppLogger: ignoring unknown option', ['key' => $key]);
            }
        }

        $breaker = \is_array($input['circuit_breaker'] ?? null) ? $input['circuit_breaker'] : [];

        $beforeSend = ($input['before_send'] ?? null) instanceof \Closure ? $input['before_send'] : null;

        if (\array_key_exists('sample_rate', $input) && !is_numeric($input['sample_rate'])) {
            $logger->warning('AppLogger: non-numeric sample_rate ignored, using default', ['given' => get_debug_type($input['sample_rate'])]);
        }

        $dsn = \is_string($input['dsn'] ?? null) ? Dsn::tryParse($input['dsn']) : null;

        return new self(
            dsn: $dsn,
            apiKey: \is_string($input['api_key'] ?? null) && '' !== $input['api_key'] ? $input['api_key'] : null,
            environment: \is_string($input['environment'] ?? null) ? $input['environment'] : 'production',
            release: \is_string($input['release'] ?? null) ? $input['release'] : null,
            enabled: self::toBool($input['enabled'] ?? true),
            sampleRate: self::clampFloat($input['sample_rate'] ?? 1.0, 0.0, 1.0, 1.0),
            maxBreadcrumbs: max(1, (int) ($input['max_breadcrumbs'] ?? 50)),
            logger: $logger,
            beforeSend: $beforeSend,
            circuitBreakerTimeout: max(10, (int) ($breaker['timeout'] ?? 60)),
            failureThreshold: max(1, (int) ($breaker['failure_threshold'] ?? 5)),
            halfOpenAttempts: max(1, (int) ($breaker['half_open_attempts'] ?? 1)),
            maxBufferedEvents: max(1, (int) ($input['max_buffered_events'] ?? 100)),
            flushBudget: self::clampFloat($input['flush_budget'] ?? 2.0, 0.05, 5.0, 2.0),
            timeout: self::clampFloat($input['timeout'] ?? 2.0, 0.5, 5.0, 2.0),
            cacheDir: \is_string($input['cache_dir'] ?? null) && '' !== $input['cache_dir']
                ? $input['cache_dir']
                : sys_get_temp_dir().'/applogger-sdk',
            sessionHashSalt: \is_string($input['session_hash_salt'] ?? null) && '' !== $input['session_hash_salt']
                ? $input['session_hash_salt']
                : hash('xxh128', null !== $dsn ? $dsn->raw : 'applogger'),
            scrubFields: \is_array($input['scrub_fields'] ?? null)
                ? array_values(array_filter($input['scrub_fields'], static fn (mixed $v): bool => \is_string($v) && '' !== $v))
                : ['password', 'passwd', 'secret', 'token', 'api_key', 'auth', 'credential'],
            flushMode: \in_array($input['flush_mode'] ?? null, [DeliveryMode::WEB, DeliveryMode::CLI, DeliveryMode::WORKER, DeliveryMode::AUTO], true)
                ? $input['flush_mode']
                : DeliveryMode::AUTO,
            defaultIntegrations: self::toBool($input['default_integrations'] ?? true),
        );
    }

    private static function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? true;
    }

    private static function clampFloat(mixed $value, float $min, float $max, float $default): float
    {
        $f = is_numeric($value) ? (float) $value : $default;

        return max($min, min($max, $f));
    }
}
