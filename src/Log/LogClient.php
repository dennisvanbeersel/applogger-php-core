<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Log;

use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\RateLimiter;
use ApplicationLogger\Sdk\Stats;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Ships structured log entries to the AppLogger log collector
 * (`{endpoint}/v1/logs/batch`, auth `X-Api-Key: sk_log_…`). Buffers on log(),
 * delivers as batches on flush(). Reuses the error pipeline's breaker / rate
 * limiter / scrubber / stats. Total — never throws into the host (Rule #1).
 */
final class LogClient implements LogSink
{
    private const BATCH_MAX = 1000;

    /** @var list<LogRecord> */
    private array $buffer = [];

    public function __construct(
        private readonly LogConfig $config,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LogPayloadFactory $payloadFactory,
        private readonly DataScrubber $scrubber,
        private readonly CircuitBreaker $breaker,
        private readonly RateLimiter $rateLimiter,
        private readonly ClockInterface $clock,
        private readonly Stats $stats,
        private readonly string $sdkVersion = '1.0.0',
    ) {
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        try {
            if (\count($this->buffer) >= $this->config->maxBufferedLogs) {
                ++$this->stats->droppedBuffer;

                return;
            }

            /** @var array<string, mixed> $scrubbed */
            $scrubbed = $this->scrubber->scrub($context);

            $this->buffer[] = new LogRecord(
                LogLevel::normalize($level),
                $this->scrubber->scrubText($message),
                $scrubbed,
                $this->clock->now(),
                $this->config->appName,
                $this->config->environment,
            );
        } catch (\Throwable) {
            // Rule #1: a logging call must never break the host.
        }
    }

    public function flush(?float $budget = null): bool
    {
        try {
            if ([] === $this->buffer) {
                return true;
            }
            if (!$this->config->isEnabled()) {
                $this->stats->droppedDisabled += \count($this->buffer);
                $this->buffer = [];

                return true;
            }

            $records = $this->buffer;
            $this->buffer = [];
            $ok = true;

            $budgetSeconds = $budget ?? $this->config->flushBudget;
            $deadline = microtime(true) + $budgetSeconds;

            $chunks = array_chunk($records, self::BATCH_MAX);
            foreach ($chunks as $i => $chunk) {
                if (microtime(true) >= $deadline) {
                    // Budget exhausted — count remaining unsent records and stop.
                    $remaining = \array_slice($chunks, $i);
                    foreach ($remaining as $unsent) {
                        $this->stats->droppedBudget += \count($unsent);
                    }

                    return false;
                }
                $ok = $this->sendBatch($chunk) && $ok;
            }

            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, int> */
    public function getStats(): array
    {
        return $this->stats->toArray();
    }

    /** @param list<LogRecord> $records */
    private function sendBatch(array $records): bool
    {
        $count = \count($records);

        if (!$this->breaker->allowRequest()) {
            $this->stats->droppedBreaker += $count;

            return false;
        }
        if (!$this->rateLimiter->allow()) {
            $this->stats->droppedRateLimit += $count;

            return false;
        }

        try {
            $logs = array_map(fn (LogRecord $r): array => $this->payloadFactory->toWire($r), $records);
            $body = json_encode(['logs' => $logs], \JSON_THROW_ON_ERROR);

            $request = $this->requestFactory
                ->createRequest('POST', (string) $this->config->batchUrl())
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Api-Key', (string) $this->config->token)
                ->withHeader('X-Application-Logger-Sdk-Version', $this->sdkVersion)
                ->withHeader('X-Idempotency-Key', $this->idempotencyKey())
                ->withBody($this->streamFactory->createStream($body));

            $response = $this->http->sendRequest($request);
            $status = $response->getStatusCode();

            if (202 === $status || 409 === $status) {
                $this->breaker->recordSuccess();
                $this->stats->sent += $count;

                return true;
            }
            if (429 === $status) {
                $retryAfter = (int) $response->getHeaderLine('Retry-After');
                $this->rateLimiter->recordRetryAfter($retryAfter > 0 ? $retryAfter : 1);
                $this->stats->droppedRateLimit += $count;

                return false;
            }
            if ($status >= 400 && $status < 500) {
                $this->stats->droppedError += $count;

                return false;
            }

            $this->breaker->recordFailure();
            $this->stats->droppedError += $count;

            return false;
        } catch (\Throwable) {
            $this->breaker->recordFailure();
            $this->stats->droppedError += $count;

            return false;
        }
    }

    private function idempotencyKey(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr(\ord($data[6]) & 0x0F | 0x40);
        $data[8] = \chr(\ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
