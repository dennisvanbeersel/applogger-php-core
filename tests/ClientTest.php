<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Breadcrumb;
use ApplicationLogger\Sdk\Cache\FilesystemPsr6Pool;
use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\Client;
use ApplicationLogger\Sdk\Clock\FrozenClock;
use ApplicationLogger\Sdk\Context\GlobalsContextCollector;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\Deduplicator;
use ApplicationLogger\Sdk\ErrorPayloadFactory;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\RateLimiter;
use ApplicationLogger\Sdk\Scope;
use ApplicationLogger\Sdk\Severity;
use ApplicationLogger\Sdk\StackTraceParser;
use ApplicationLogger\Sdk\Stats;
use ApplicationLogger\Sdk\Transport\FileTransport;
use ApplicationLogger\Sdk\Transport\HttpTransport;
use ApplicationLogger\Sdk\Transport\NoopRequestFinisher;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ClientTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/applogger_client_'.uniqid('', true).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * Build the three Phase-3A collaborators with empty/default config so existing
     * assertions are unaffected.
     *
     * @return array{DataScrubber, StackTraceParser, GlobalsContextCollector}
     */
    private function defaultCollaborators(): array
    {
        $scrubber = new DataScrubber([]);
        $parser = new StackTraceParser();
        $collector = new GlobalsContextCollector($scrubber, 'test');

        return [$scrubber, $parser, $collector];
    }

    /** @param array<string, mixed> $opts
     * @return array<int, Client|FileTransport>
     */
    private function client(array $opts = [], ?\Closure $sampler = null): array
    {
        $options = Options::fromArray(array_merge(['dsn' => 'https://applogger.eu/0xP'], $opts));
        $transport = new FileTransport($this->path);
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        [$scrubber, $parser, $collector] = $this->defaultCollaborators();
        $client = new Client($options, $transport, $clock, $scrubber, $parser, $collector, $sampler);

        return [$client, $transport];
    }

    public function testCaptureExceptionSendsEventAndCountsSent(): void
    {
        [$client, $transport] = $this->client();
        $client->captureException(new \RuntimeException('boom'), new Scope());

        self::assertCount(1, $transport->capturedEvents());
        self::assertSame(1, $client->getStats()['sent']);
    }

    public function testDisabledOrNoDsnDropsAsDisabled(): void
    {
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP', 'enabled' => false]);
        $transport = new FileTransport($this->path);
        [$scrubber, $parser, $collector] = $this->defaultCollaborators();
        $client = new Client($options, $transport, new FrozenClock(new \DateTimeImmutable('2026-01-01')), $scrubber, $parser, $collector);

        $client->captureException(new \RuntimeException('boom'), new Scope());

        self::assertCount(0, $transport->capturedEvents());
        self::assertSame(1, $client->getStats()['dropped_disabled']);
    }

    public function testSamplingDropsWhenSamplerBelowRate(): void
    {
        // sample_rate 0.0 → always drop; sampler returns 0.5 (>= 0.0 boundary means drop)
        [$client, $transport] = $this->client(['sample_rate' => 0.0], static fn (): float => 0.5);
        $client->captureException(new \RuntimeException('boom'), new Scope());

        self::assertCount(0, $transport->capturedEvents());
        self::assertSame(1, $client->getStats()['dropped_sampled']);
    }

    public function testBeforeSendReturningNullDrops(): void
    {
        [$client, $transport] = $this->client(['before_send' => static fn (Event $e): ?Event => null]);
        $client->captureException(new \RuntimeException('boom'), new Scope());

        self::assertCount(0, $transport->capturedEvents());
        self::assertSame(1, $client->getStats()['dropped_before_send']);
    }

    public function testNeverThrowsEvenIfBeforeSendThrows(): void
    {
        [$client, $transport] = $this->client(['before_send' => static function (Event $e): Event {
            throw new \LogicException('bad hook');
        }]);
        $client->captureException(new \RuntimeException('boom'), new Scope());

        // Must not propagate; event is dropped, host is unharmed.
        self::assertCount(0, $transport->capturedEvents());
    }

    public function testCaptureScrubsContextAndPopulatesStackBeforeTransport(): void
    {
        // Build a Client with a DataScrubber(['password']), a StackTraceParser, and a
        // GlobalsContextCollector over a fake $server. The collector scrubs the URL query
        // string itself via scrubUrl(); the Client's DataScrubber then scrubs context keys.
        // We use a path that puts 'token' (a separate scrub field) in the query so we can
        // verify the URL key-level scrub without the raw REQUEST_URI leaking the value.
        $scrubber = new DataScrubber(['token']);
        $parser = new StackTraceParser();
        // The collector's own scrubber also scrubs 'token' from the URL query string.
        $collector = new GlobalsContextCollector(
            new DataScrubber(['token']),
            'test-salt',
            [
                'REQUEST_URI' => '/checkout?token=abc123&step=2',
                'REQUEST_METHOD' => 'POST',
                'SERVER_NAME' => 'example.com',
            ],
        );

        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $transport = new FileTransport($this->path);
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $client = new Client($options, $transport, $clock, $scrubber, $parser, $collector);

        $client->captureException(new \RuntimeException('boom'), new Scope());
        $client->flush();

        $events = $transport->capturedEvents();
        self::assertCount(1, $events);

        $payload = $events[0];

        // context['url'] must have token value redacted by the collector's scrubUrl()
        $contextUrl = (string) ($payload['context']['url'] ?? '');
        self::assertStringContainsString('token=[REDACTED]', $contextUrl);
        self::assertStringNotContainsString('abc123', $contextUrl);

        // context['runtime'] must be set (populated by the collector)
        self::assertStringStartsWith('PHP ', (string) ($payload['context']['runtime'] ?? ''));

        // Verify stack trace wiring end-to-end: Client must have populated $event->stackTrace
        // and FileTransport now serialises it, so the captured row carries the frames.
        $captured = $transport->capturedEvents();
        self::assertNotEmpty($captured[0]['stack_trace']);
    }

    public function testBeforeSendReceivesScrubbedData(): void
    {
        $receivedContext = null;

        $scrubber = new DataScrubber(['password']);
        $parser = new StackTraceParser();

        // Use a collector that injects a raw 'password' key — the Client's scrubber must
        // redact it BEFORE before_send is called.
        $collector = new class implements \ApplicationLogger\Sdk\Context\ContextCollectorInterface {
            /** @return array<string, mixed> */
            public function collect(): array
            {
                return ['password' => 'hunter2', 'runtime' => 'PHP test'];
            }
        };

        $options = Options::fromArray([
            'dsn' => 'https://applogger.eu/0xP',
            'before_send' => static function (Event $e) use (&$receivedContext): Event {
                $receivedContext = $e->context;

                return $e;
            },
        ]);
        $transport = new FileTransport($this->path);
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $client = new Client($options, $transport, $clock, $scrubber, $parser, $collector);

        $client->captureException(new \RuntimeException('boom'), new Scope());

        // before_send must receive already-scrubbed context — raw 'hunter2' must not appear
        self::assertNotNull($receivedContext);
        self::assertSame('[REDACTED]', $receivedContext['password'] ?? null);
        $json = json_encode($receivedContext, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('hunter2', $json);
    }

    public function testBeforeSendReintroducedSecretsAreReScrubbedBeforeTransport(): void
    {
        $scrubber = new DataScrubber(['password']);
        $parser = new StackTraceParser();
        $collector = new class implements \ApplicationLogger\Sdk\Context\ContextCollectorInterface {
            /** @return array<string, mixed> */
            public function collect(): array
            {
                return ['runtime' => 'PHP test'];
            }
        };

        // before_send maliciously/accidentally injects a raw sensitive value back into
        // the event AFTER the first scrub pass. The Client must re-scrub before transport.
        $options = Options::fromArray([
            'dsn' => 'https://applogger.eu/0xP',
            'before_send' => static function (Event $e): Event {
                $e->context['password'] = 'hunter2';

                return $e;
            },
        ]);
        $transport = new FileTransport($this->path);
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $client = new Client($options, $transport, $clock, $scrubber, $parser, $collector);

        $client->captureException(new \RuntimeException('boom'), new Scope());
        $client->flush();

        $events = $transport->capturedEvents();
        self::assertCount(1, $events);
        // The re-scrub pass (after before_send) must have redacted the reintroduced value.
        self::assertSame('[REDACTED]', $events[0]['context']['password'] ?? null);
        self::assertStringNotContainsString('hunter2', json_encode($events[0], \JSON_THROW_ON_ERROR));
    }

    public function testGetStatsMergesTransportDroppedBreakerIntoPublicStats(): void
    {
        // Build an HttpTransport whose CircuitBreaker is already OPEN.
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01'));
        $pool = new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_cb_clienttest_'.uniqid('', true));
        $breaker = new CircuitBreaker($pool, $clock, failureThreshold: 1, openSeconds: 60);
        $breaker->recordFailure(); // → OPEN immediately (failureThreshold 1)

        $httpClientCalled = new \stdClass();
        $httpClientCalled->n = 0;
        $psrClient = new class($httpClientCalled) implements ClientInterface {
            public function __construct(private \stdClass $log)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->log->n;

                return new Response(202);
            }
        };

        $psr17 = new Psr17Factory();
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $transport = new HttpTransport(
            $options, $psrClient, $psr17, $psr17,
            new ErrorPayloadFactory(), new NoopRequestFinisher(),
            $breaker, new Stats(),
            new RateLimiter(new FrozenClock(new \DateTimeImmutable('2026-01-01')), new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_rl_'.uniqid('', true)), 1000.0, 1000),
            new Deduplicator(), '1.0.0',
        );

        [$scrubber, $parser, $collector] = $this->defaultCollaborators();
        $client = new Client($options, $transport, $clock, $scrubber, $parser, $collector);
        $client->captureException(new \RuntimeException('boom'), new Scope());
        $client->flush();

        $stats = $client->getStats();

        // The PSR-18 client must never have been called (breaker was OPEN).
        self::assertSame(0, $httpClientCalled->n);
        // dropped_breaker must be visible through the public Client::getStats() seam.
        self::assertGreaterThanOrEqual(1, $stats['dropped_breaker']);
        // sent must be 0 — nothing was delivered.
        self::assertSame(0, $stats['sent']);
    }

    public function testScopeUserAndBreadcrumbDataAreScrubbedBeforeTransport(): void
    {
        $scrubber = new DataScrubber(['password', 'token']);
        $parser = new StackTraceParser();
        $collector = new GlobalsContextCollector($scrubber, 'test');

        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $transport = new FileTransport($this->path);
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $client = new Client($options, $transport, $clock, $scrubber, $parser, $collector);

        $scope = new Scope();
        $scope->setUser(['email' => 'jo@x.com', 'password' => 'hunter2']);
        $scope->setExtra('secret_token', 'abc');
        $scope->addBreadcrumb(new Breadcrumb(
            'nav',
            'hi',
            Severity::Info,
            ['token' => 'leakme'],
            new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')),
        ));

        $client->captureException(new \RuntimeException('pii-leak'), $scope);
        $client->flush();

        $events = $transport->capturedEvents();
        self::assertCount(1, $events);
        $captured = $events[0];

        // email is not a scrub field — it must survive
        self::assertSame('jo@x.com', $captured['context']['user']['email'] ?? null);
        // password IS a scrub field — must be redacted
        self::assertSame('[REDACTED]', $captured['context']['user']['password'] ?? null);
        // raw secret value must not appear anywhere in the payload
        self::assertStringNotContainsString('hunter2', json_encode($captured, \JSON_THROW_ON_ERROR));

        // scope extra under a sensitive key ('secret_token' contains 'token') must be redacted
        self::assertSame('[REDACTED]', $captured['context']['extra']['secret_token'] ?? null);
        self::assertStringNotContainsString('abc', json_encode($captured['context']['extra'] ?? [], \JSON_THROW_ON_ERROR));

        // breadcrumb token must be redacted
        self::assertSame('[REDACTED]', $captured['breadcrumbs'][0]['data']['token'] ?? null);
        self::assertStringNotContainsString('leakme', json_encode($captured, \JSON_THROW_ON_ERROR));
    }

    public function testGetStatsMergesTransportDroppedDedupIntoPublicStats(): void
    {
        // Build an HttpTransport with an in-memory PSR-18 client that returns 202,
        // a high-capacity RateLimiter (so it doesn't interfere), and a closed breaker.
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01'));
        $pool = new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_cb_clienttest_dedup_'.uniqid('', true));
        $breaker = new CircuitBreaker($pool, $clock, failureThreshold: 10, openSeconds: 60);

        $psrClient = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(202);
            }
        };

        $psr17 = new Psr17Factory();
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $transport = new HttpTransport(
            $options, $psrClient, $psr17, $psr17,
            new ErrorPayloadFactory(), new NoopRequestFinisher(),
            $breaker, new Stats(),
            new RateLimiter(new FrozenClock(new \DateTimeImmutable('2026-01-01')), new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_rl_dedup_'.uniqid('', true)), 100000.0, 100000),
            new Deduplicator(), '1.0.0',
        );

        [$scrubber, $parser, $collector] = $this->defaultCollaborators();
        $client = new Client($options, $transport, $clock, $scrubber, $parser, $collector);

        // Capture the SAME exception three times. Since they're created at the same line,
        // they will have the same fingerprint (type|file|line) and be deduplicated.
        $e = new \RuntimeException('dup');
        $client->captureException($e, new Scope());
        $client->captureException($e, new Scope());
        $client->captureException($e, new Scope());

        $client->flush();

        $stats = $client->getStats();

        // 3 identical exceptions → 1 survivor + exactly 2 collapsed
        self::assertSame(2, $stats['dropped_dedup']);
        // Exactly 1 event should be sent (the surviving unique one).
        self::assertSame(1, $stats['sent']);
    }

    public function testCaptureEventRunsFullPipelineOnPrebuiltEvent(): void
    {
        $scrubber = new DataScrubber(['password']);
        $parser = new StackTraceParser();
        $collector = new GlobalsContextCollector($scrubber, 'test');
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $transport = new FileTransport($this->path);
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $client = new Client($options, $transport, $clock, $scrubber, $parser, $collector);

        $event = new Event(
            type: 'CustomFatal',
            message: 'boom',
            file: '/app/x.php',
            line: 7,
            level: Severity::Fatal,
            environment: 'production',
            release: null,
            timestamp: new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')),
        );
        $event->context = ['password' => 'hunter2', 'safe' => 'ok'];

        $client->captureEvent($event, new Scope());
        $client->flush();

        $events = $transport->capturedEvents();
        self::assertCount(1, $events);
        // pinned pipeline ran: recursive scrub redacted the sensitive key
        self::assertSame('[REDACTED]', $events[0]['context']['password'] ?? null);
        self::assertSame('ok', $events[0]['context']['safe'] ?? null);
        self::assertSame('fatal', $events[0]['level']);
    }

    public function testCaptureFatalEventForceRedactsMessageButSkipsRecursiveScrub(): void
    {
        // literal DSN is a configured force-redaction literal; 'password' is a scrub key.
        $scrubber = new DataScrubber(['password'], ['https://applogger.eu/0xP']);
        $parser = new StackTraceParser();
        $collector = new GlobalsContextCollector($scrubber, 'test');
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $transport = new FileTransport($this->path);
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $client = new Client($options, $transport, $clock, $scrubber, $parser, $collector);

        $event = new Event(
            type: 'E_ERROR',
            message: 'fatal near https://applogger.eu/0xP boom',
            file: '/app/x.php',
            line: 42,
            level: Severity::Fatal,
            environment: 'production',
            release: null,
            timestamp: new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')),
        );
        $event->partial = true;
        $event->tags = ['oom' => true];
        $event->context = ['password' => 'raw-not-scrubbed-on-lean-path'];

        $client->captureFatalEvent($event);

        $events = $transport->capturedEvents();
        self::assertCount(1, $events);
        // Force-redaction (scrubText) DID strip the literal DSN from the message.
        self::assertStringNotContainsString('applogger.eu/0xP', $events[0]['message']);
        self::assertStringContainsString('[REDACTED]', $events[0]['message']);
        // markers preserved; level is fatal.
        self::assertTrue($events[0]['partial']);
        self::assertTrue($events[0]['tags']['oom']);
        self::assertSame('fatal', $events[0]['level']);
        // Lean path intentionally skips the recursive context scrub (documents the tradeoff).
        self::assertSame('raw-not-scrubbed-on-lean-path', $events[0]['context']['password']);
    }
}
