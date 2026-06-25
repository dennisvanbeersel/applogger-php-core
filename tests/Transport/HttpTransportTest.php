<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Transport;

use ApplicationLogger\Sdk\Cache\FilesystemPsr6Pool;
use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\Clock\FrozenClock;
use ApplicationLogger\Sdk\Deduplicator;
use ApplicationLogger\Sdk\ErrorPayloadFactory;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\RateLimiter;
use ApplicationLogger\Sdk\Severity;
use ApplicationLogger\Sdk\Stats;
use ApplicationLogger\Sdk\Transport\HttpTransport;
use ApplicationLogger\Sdk\Transport\NoopRequestFinisher;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class HttpTransportTest extends TestCase
{
    /**
     * @return array{HttpTransport, \stdClass}
     *
     * The second element is a stdClass with a `sent` property (list<RequestInterface>)
     * that is mutated by the in-memory client after each sendRequest() call
     */
    private function transport(ResponseInterface $response): array
    {
        $log = new \stdClass();
        $log->sent = [];
        $client = new class($response, $log) implements ClientInterface {
            public function __construct(private ResponseInterface $response, private \stdClass $log)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->log->sent[] = $request;

                return $this->response;
            }
        };
        $psr17 = new Psr17Factory();
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $transport = new HttpTransport(
            $options, $client, $psr17, $psr17,
            new ErrorPayloadFactory(), new NoopRequestFinisher(),
            $this->makeBreaker(), new Stats(),
            $this->makeRateLimiter(), new Deduplicator(), '9.9.9',
        );

        return [$transport, $log];
    }

    private function makeBreaker(): CircuitBreaker
    {
        return new CircuitBreaker(
            new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_cb_'.uniqid('', true)),
            new FrozenClock(new \DateTimeImmutable('2026-01-01')),
        );
    }

    private function makeRateLimiter(float $eventsPerSecond = 1000.0, int $burst = 1000): RateLimiter
    {
        return new RateLimiter(
            new FrozenClock(new \DateTimeImmutable('2026-01-01')),
            new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_rl_'.uniqid('', true)),
            $eventsPerSecond,
            $burst,
        );
    }

    private function event(): Event
    {
        return Event::fromThrowable(new \RuntimeException('boom'), Severity::Error, new \DateTimeImmutable('2026-01-01'), 'production', null);
    }

    public function testFlushPostsBufferedEventTo202AndSetsHeaders(): void
    {
        [$transport, $log] = $this->transport(new Response(202));
        $transport->send($this->event());
        self::assertCount(0, $log->sent); // buffered, not sent yet
        self::assertTrue($transport->flush());

        self::assertCount(1, $log->sent);
        $req = $log->sent[0];
        self::assertSame('POST', $req->getMethod());
        self::assertStringEndsWith('/api/v1/errors', (string) $req->getUri());
        self::assertSame('https://applogger.eu/0xP', $req->getHeaderLine('X-Application-Logger-DSN'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertSame('9.9.9', $req->getHeaderLine('X-Application-Logger-Sdk-Version'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $req->getHeaderLine('X-Idempotency-Key'));
        self::assertStringContainsString('"boom"', (string) $req->getBody());
    }

    public function testNeverThrowsWhenClientThrows(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('network down');
            }
        };
        $psr17 = new Psr17Factory();
        $transport = new HttpTransport(
            Options::fromArray(['dsn' => 'https://applogger.eu/0xP']),
            $client, $psr17, $psr17, new ErrorPayloadFactory(), new NoopRequestFinisher(),
            $this->makeBreaker(), new Stats(), $this->makeRateLimiter(), new Deduplicator(), '1.0.0',
        );
        $transport->send($this->event());
        self::assertFalse($transport->flush()); // failed, but did not throw
    }

    public function test409IsTreatedAsSuccess(): void
    {
        [$transport, $log] = $this->transport(new Response(409));
        $transport->send($this->event());
        self::assertTrue($transport->flush());
        self::assertCount(1, $log->sent);
    }

    public function testDropsNewestBeyondMaxBufferedEvents(): void
    {
        $log = new \stdClass();
        $log->sent = [];
        $client = new class(new Response(202), $log) implements ClientInterface {
            public function __construct(private ResponseInterface $response, private \stdClass $log)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->log->sent[] = $request;

                return $this->response;
            }
        };
        $psr17 = new Psr17Factory();
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'max_buffered_events' => 2]);
        $transport = new HttpTransport(
            $options, $client, $psr17, $psr17,
            new ErrorPayloadFactory(), new NoopRequestFinisher(),
            $this->makeBreaker(), new Stats(), $this->makeRateLimiter(), new Deduplicator(), '1.0.0',
        );

        // Use distinct events (different exception classes) so dedup does not collapse them.
        $e1 = Event::fromThrowable(new \RuntimeException('first'), Severity::Error, new \DateTimeImmutable('2026-01-01'), 'production', null);
        $e2 = Event::fromThrowable(new \LogicException('second'), Severity::Error, new \DateTimeImmutable('2026-01-01'), 'production', null);
        $e3 = Event::fromThrowable(new \OverflowException('third'), Severity::Error, new \DateTimeImmutable('2026-01-01'), 'production', null);
        $transport->send($e1);
        $transport->send($e2);
        $transport->send($e3); // third is dropped (buffer cap)
        $transport->flush();

        self::assertCount(2, $log->sent);
    }

    public function testBudgetExhaustionStopsDrainAndReturnsFalse(): void
    {
        $log = new \stdClass();
        $log->sent = [];
        $client = new class(new Response(202), $log) implements ClientInterface {
            public function __construct(private ResponseInterface $response, private \stdClass $log)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->log->sent[] = $request;

                return $this->response;
            }
        };
        $psr17 = new Psr17Factory();
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $transport = new HttpTransport(
            $options, $client, $psr17, $psr17,
            new ErrorPayloadFactory(), new NoopRequestFinisher(),
            $this->makeBreaker(), new Stats(), $this->makeRateLimiter(), new Deduplicator(), '1.0.0',
        );

        $transport->send($this->event());
        $result = $transport->flush(0.0); // budget of 0 seconds exhausted immediately

        self::assertFalse($result);
        self::assertCount(0, $log->sent);
    }

    public function testOpenBreakerSkipsSendAndCountsDroppedBreaker(): void
    {
        // Build a transport whose breaker is already OPEN; flush must NOT call the client.
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01'));
        $pool = new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_cb_test_'.uniqid('', true));
        $breaker = new CircuitBreaker($pool, $clock, failureThreshold: 1, openSeconds: 60);
        $breaker->recordFailure(); // → OPEN

        $sent = new \stdClass();
        $sent->n = 0;
        $client = new class($sent) implements ClientInterface {
            public function __construct(private \stdClass $sent)
            {
            }

            public function sendRequest(RequestInterface $r): ResponseInterface
            {
                ++$this->sent->n;

                return new Response(202);
            }
        };
        $psr17 = new Psr17Factory();
        $stats = new Stats();
        $transport = new HttpTransport(
            Options::fromArray(['dsn' => 'https://applogger.eu/0xP']),
            $client, $psr17, $psr17, new ErrorPayloadFactory(),
            new NoopRequestFinisher(), $breaker, $stats,
            $this->makeRateLimiter(), new Deduplicator(), '1.0.0',
        );

        $transport->send($this->event());
        self::assertFalse($transport->flush());
        self::assertSame(0, $sent->n);                  // client never called
        self::assertSame(1, $stats->droppedBreaker);
    }

    public function test4xxDoesNotTripBreakerButCountsError(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01'));
        $pool = new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_cb_test_'.uniqid('', true));
        $breaker = new CircuitBreaker($pool, $clock, failureThreshold: 1, openSeconds: 60);
        $psr17 = new Psr17Factory();
        $stats = new Stats();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $r): ResponseInterface
            {
                return new Response(400);
            }
        };
        $transport = new HttpTransport(
            Options::fromArray(['dsn' => 'https://applogger.eu/0xP']),
            $client, $psr17, $psr17, new ErrorPayloadFactory(),
            new NoopRequestFinisher(), $breaker, $stats,
            $this->makeRateLimiter(), new Deduplicator(), '1.0.0',
        );
        $transport->send($this->event());
        self::assertFalse($transport->flush());
        self::assertSame(1, $stats->droppedError);
        self::assertTrue($breaker->allowRequest()); // 4xx did NOT trip
    }

    public function test5xxTripsBreaker(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01'));
        $pool = new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_cb_test_'.uniqid('', true));
        $breaker = new CircuitBreaker($pool, $clock, failureThreshold: 1, openSeconds: 60);
        $psr17 = new Psr17Factory();
        $stats = new Stats();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $r): ResponseInterface
            {
                return new Response(500);
            }
        };
        $transport = new HttpTransport(
            Options::fromArray(['dsn' => 'https://applogger.eu/0xP']),
            $client, $psr17, $psr17, new ErrorPayloadFactory(),
            new NoopRequestFinisher(), $breaker, $stats,
            $this->makeRateLimiter(), new Deduplicator(), '1.0.0',
        );
        $transport->send($this->event());
        $transport->flush();
        self::assertSame(1, $stats->droppedError);
        self::assertFalse($breaker->allowRequest()); // 5xx TRIPPED the breaker (failureThreshold 1)
    }

    public function testFlushDedupesIdenticalEventsAndCountsDropped(): void
    {
        $log = new \stdClass();
        $log->sent = [];
        $client = new class(new Response(202), $log) implements ClientInterface {
            public function __construct(private ResponseInterface $response, private \stdClass $log)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->log->sent[] = $request;

                return $this->response;
            }
        };
        $psr17 = new Psr17Factory();
        $stats = new Stats();
        $transport = new HttpTransport(
            Options::fromArray(['dsn' => 'https://applogger.eu/0xP']),
            $client, $psr17, $psr17, new ErrorPayloadFactory(),
            new NoopRequestFinisher(), $this->makeBreaker(), $stats,
            $this->makeRateLimiter(), new Deduplicator(), '1.0.0',
        );

        // 3 identical events (same exception class/message/trace → same fingerprint) + 1 distinct
        $identical = Event::fromThrowable(new \RuntimeException('dup'), Severity::Error, new \DateTimeImmutable('2026-01-01'), 'production', null);
        $distinct = Event::fromThrowable(new \RuntimeException('unique'), Severity::Error, new \DateTimeImmutable('2026-01-01'), 'production', null);

        $transport->send($identical);
        $transport->send($identical);
        $transport->send($identical);
        $transport->send($distinct);
        $transport->flush();

        // 3 identical → 1 kept + 2 collapsed; 1 distinct kept → 2 POSTs total
        self::assertCount(2, $log->sent);
        self::assertSame(2, $stats->droppedDedup);

        // The deduped event's body must contain "duplicate_count"
        $allBodies = implode('', array_map(static fn (RequestInterface $r) => (string) $r->getBody(), $log->sent));
        self::assertStringContainsString('duplicate_count', $allBodies);
    }

    public function testRateLimitedEventCountsDroppedRateLimitAndSkipsClient(): void
    {
        $sent = new \stdClass();
        $sent->n = 0;
        $client = new class($sent) implements ClientInterface {
            public function __construct(private \stdClass $sent)
            {
            }

            public function sendRequest(RequestInterface $r): ResponseInterface
            {
                ++$this->sent->n;

                return new Response(202);
            }
        };
        $psr17 = new Psr17Factory();
        $stats = new Stats();
        // Exhausted rate limiter: burst=0, eventsPerSecond=0 → allow() will return false immediately
        $rateLimiter = $this->makeRateLimiter(0.0, 0);
        $transport = new HttpTransport(
            Options::fromArray(['dsn' => 'https://applogger.eu/0xP']),
            $client, $psr17, $psr17, new ErrorPayloadFactory(),
            new NoopRequestFinisher(), $this->makeBreaker(), $stats,
            $rateLimiter, new Deduplicator(), '1.0.0',
        );

        $transport->send($this->event());
        self::assertFalse($transport->flush());
        self::assertSame(0, $sent->n);             // client was NOT called
        self::assertSame(1, $stats->droppedRateLimit);
    }

    public function test429RecordsRetryAfterAndCountsRateLimit(): void
    {
        $psr17 = new Psr17Factory();
        $stats = new Stats();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01'));
        $pool = new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_rl_429_'.uniqid('', true));
        $rateLimiter = new RateLimiter($clock, $pool, 1000.0, 1000);

        $breakerPool = new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_cb_429_'.uniqid('', true));
        $breaker = new CircuitBreaker($breakerPool, $clock, failureThreshold: 2, openSeconds: 60);

        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $r): ResponseInterface
            {
                return new Response(429, ['Retry-After' => ['30']]);
            }
        };
        $transport = new HttpTransport(
            Options::fromArray(['dsn' => 'https://applogger.eu/0xP']),
            $client, $psr17, $psr17, new ErrorPayloadFactory(),
            new NoopRequestFinisher(), $breaker, $stats,
            $rateLimiter, new Deduplicator(), '1.0.0',
        );

        $transport->send($this->event());
        self::assertFalse($transport->flush());

        // 429 counted as droppedRateLimit, not droppedError
        self::assertSame(1, $stats->droppedRateLimit);
        self::assertSame(0, $stats->droppedError);

        // 429 must NOT trip the breaker
        self::assertTrue($breaker->allowRequest());

        // recordRetryAfter was called → subsequent allow() on same limiter returns false (suppressed)
        self::assertFalse($rateLimiter->allow());
    }

    public function testBufferCapDropCountsDroppedBuffer(): void
    {
        $psr17 = new Psr17Factory();
        $stats = new Stats();
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'max_buffered_events' => 1]);
        $transport = new HttpTransport(
            $options,
            new class implements ClientInterface {
                public function sendRequest(RequestInterface $r): ResponseInterface
                {
                    return new Response(202);
                }
            },
            $psr17, $psr17, new ErrorPayloadFactory(),
            new NoopRequestFinisher(), $this->makeBreaker(), $stats,
            $this->makeRateLimiter(), new Deduplicator(), '1.0.0',
        );

        $transport->send($this->event()); // buffered (buffer now at cap=1)
        $transport->send($this->event()); // dropped — buffer full

        self::assertSame(1, $stats->droppedBuffer);
    }

    public function testClientDroppedHeaderReportsThenResets(): void
    {
        $log = new \stdClass();
        $log->sent = [];
        $client = new class(new Response(202), $log) implements ClientInterface {
            public function __construct(private ResponseInterface $response, private \stdClass $log)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->log->sent[] = $request;

                return $this->response;
            }

            /**
             * @return list<RequestInterface>
             */
            public function getCapturedRequests(): array
            {
                return $this->log->sent;
            }
        };
        $psr17 = new Psr17Factory();
        $transport = new HttpTransport(
            Options::fromArray(['dsn' => 'https://applogger.eu/0xP']),
            $client, $psr17, $psr17, new ErrorPayloadFactory(),
            new NoopRequestFinisher(), $this->makeBreaker(), new Stats(),
            $this->makeRateLimiter(), new Deduplicator(), '1.0.0',
        );

        // Send 3 identical events → dedup collapses to 1, counts 2 drops
        $identical = Event::fromThrowable(new \RuntimeException('dup'), Severity::Error, new \DateTimeImmutable('2026-01-01'), 'production', null);
        $transport->send($identical);
        $transport->send($identical);
        $transport->send($identical);
        $transport->flush();

        // First flush: exactly 1 request sent (dedup collapsed the 3 identical)
        $capturedRequests = $client->getCapturedRequests();
        self::assertCount(1, $capturedRequests);
        $firstRequest = $capturedRequests[0];
        // Assert the header is present and equals '2' (2 dropped via dedup)
        self::assertSame('2', $firstRequest->getHeaderLine('X-Application-Logger-Client-Dropped'));

        // Send 1 distinct event and flush again
        $distinct = Event::fromThrowable(new \RuntimeException('unique'), Severity::Error, new \DateTimeImmutable('2026-01-01'), 'production', null);
        $transport->send($distinct);
        $transport->flush();

        // Second flush: exactly 1 more request sent (the distinct event)
        $capturedRequests = $client->getCapturedRequests();
        self::assertCount(2, $capturedRequests);
        $secondRequest = $capturedRequests[1];
        // Assert the header is absent (empty string) — droppedSinceLastSend was reset to 0 after the first successful 202
        self::assertSame('', $secondRequest->getHeaderLine('X-Application-Logger-Client-Dropped'));
    }
}
