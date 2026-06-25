<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Transport;

use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\Deduplicator;
use ApplicationLogger\Sdk\ErrorPayloadFactory;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\RateLimiter;
use ApplicationLogger\Sdk\Stats;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class HttpTransport implements TransportInterface
{
    /** @var list<Event> */
    private array $buffer = [];

    private int $droppedSinceLastSend = 0;

    public function __construct(
        private readonly Options $options,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ErrorPayloadFactory $factory,
        private readonly RequestFinisher $finisher,
        private readonly CircuitBreaker $breaker,
        private readonly Stats $stats,
        private readonly RateLimiter $rateLimiter,
        private readonly Deduplicator $deduplicator,
        private readonly string $sdkVersion = '1.0.0',
    ) {
    }

    public function getStats(): Stats
    {
        return $this->stats;
    }

    public function send(Event $event): void
    {
        if (\count($this->buffer) >= $this->options->maxBufferedEvents) {
            ++$this->stats->droppedBuffer;
            ++$this->droppedSinceLastSend;

            return;
        }
        $this->buffer[] = $event;
    }

    public function flush(?float $budgetSeconds = null): bool
    {
        if ([] === $this->buffer) {
            return true;
        }

        try {
            // Hand the response back to the client first on FPM; inline otherwise.
            if ($this->finisher->isAvailable()) {
                $this->finisher->finish();
            }

            $budget = $budgetSeconds ?? $this->options->flushBudget;
            $deadline = microtime(true) + $budget;

            // Best-effort, non-retrying: the buffer is cleared up front, so any event skipped by the
            // deadline or rejected by post() is dropped by design (no spool/retry queue in this phase).
            $events = $this->buffer;
            $this->buffer = [];

            $beforeDedup = \count($events);
            $events = $this->deduplicator->dedupe($events);
            $deduped = $beforeDedup - \count($events);
            if ($deduped > 0) {
                $this->stats->droppedDedup += $deduped;
                $this->droppedSinceLastSend += $deduped;
            }

            $allOk = true;

            foreach ($events as $event) {
                if (microtime(true) >= $deadline) {
                    ++$this->stats->droppedBudget;
                    ++$this->droppedSinceLastSend;
                    $allOk = false;
                    continue;
                }
                if (!$this->breaker->allowRequest()) {
                    ++$this->stats->droppedBreaker;
                    ++$this->droppedSinceLastSend;
                    $allOk = false;
                    continue;
                }
                if (!$this->rateLimiter->allow()) {
                    ++$this->stats->droppedRateLimit;
                    ++$this->droppedSinceLastSend;
                    $allOk = false;
                    continue;
                }
                if (!$this->post($event)) {
                    $allOk = false;
                }
            }

            return $allOk;
        } catch (\Throwable $t) {
            $this->options->logger->warning('AppLogger: flush failed', ['exception' => $t::class]);

            return false;
        }
    }

    private function post(Event $event): bool
    {
        try {
            $dsn = $this->options->dsn;
            if (null === $dsn) {
                return false;
            }

            $body = json_encode($this->factory->fromEvent($event), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

            $request = $this->requestFactory
                ->createRequest('POST', $dsn->ingestUrl('/api/v1/errors'))
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Application-Logger-DSN', $dsn->raw)
                ->withHeader('X-Application-Logger-Sdk-Version', $this->sdkVersion)
                ->withHeader('X-Idempotency-Key', $this->idempotencyKey())
                ->withBody($this->streamFactory->createStream($body));

            if (null !== $this->options->apiKey) {
                $request = $request->withHeader('X-Application-Logger-Api-Key', $this->options->apiKey);
            }

            if ($this->droppedSinceLastSend > 0) {
                $request = $request->withHeader('X-Application-Logger-Client-Dropped', (string) $this->droppedSinceLastSend);
            }

            $response = $this->client->sendRequest($request);
            $status = $response->getStatusCode();

            if (202 === $status || 409 === $status) {
                $this->breaker->recordSuccess();
                ++$this->stats->sent;
                $this->droppedSinceLastSend = 0;

                return true;
            }
            if (429 === $status) {
                $retryAfter = (int) $response->getHeaderLine('Retry-After');
                $this->rateLimiter->recordRetryAfter($retryAfter > 0 ? $retryAfter : 1);
                ++$this->stats->droppedRateLimit;
                ++$this->droppedSinceLastSend;

                return false; // 429 is not a 5xx — do NOT trip the breaker
            }
            if ($status >= 400 && $status < 500) {
                // Contract/validation error — surfaced + dropped, but does NOT trip the breaker.
                $this->options->logger->warning('AppLogger: ingest rejected payload', ['status' => $status]);
                ++$this->stats->droppedError;
                ++$this->droppedSinceLastSend;

                return false;
            }
            // 5xx / unexpected — counts toward tripping.
            $this->breaker->recordFailure();
            ++$this->stats->droppedError;
            ++$this->droppedSinceLastSend;

            return false;
        } catch (\Throwable $t) {
            // Transport error / timeout — counts toward tripping.
            $this->breaker->recordFailure();
            ++$this->stats->droppedError;
            ++$this->droppedSinceLastSend;
            $this->options->logger->warning('AppLogger: send failed', ['exception' => $t::class]);

            return false;
        }
    }

    private function idempotencyKey(): string
    {
        // RFC-4122 v4, hex-with-dashes — satisfies the server's ^[A-Za-z0-9_-]{16,128}$.
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
