<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Log;

use ApplicationLogger\Sdk\Cache\FilesystemPsr6Pool;
use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\Clock\FrozenClock;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\Log\LogClient;
use ApplicationLogger\Sdk\Log\LogConfig;
use ApplicationLogger\Sdk\Log\LogPayloadFactory;
use ApplicationLogger\Sdk\RateLimiter;
use ApplicationLogger\Sdk\Stats;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class LogClientTest extends TestCase
{
    /** Requests captured by the spy HTTP client; populated after each flush(). */
    private \stdClass $spy;

    private function client(int $status = 202, string $retryAfter = ''): LogClient
    {
        $this->spy = new \stdClass();
        $this->spy->sent = [];

        $psr18 = new class($this->spy, $status, $retryAfter) implements ClientInterface {
            public function __construct(
                private readonly \stdClass $spy,
                private readonly int $status,
                private readonly string $retryAfter,
            ) {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->spy->sent[] = $request;
                $headers = '' !== $this->retryAfter ? ['Retry-After' => $this->retryAfter] : [];

                return new Response($this->status, $headers);
            }
        };

        $config = LogConfig::fromArray(['log_endpoint' => 'https://app.logs.applogger.eu', 'log_token' => 'sk_log_secret', 'app_name' => 'app', 'environment' => 'production']);
        $psr17 = new Psr17Factory();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $pool = new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_log_'.uniqid('', true));

        return new LogClient(
            $config, $psr18, $psr17, $psr17, new LogPayloadFactory(),
            new DataScrubber(['password'], ['sk_log_secret']),
            new CircuitBreaker($pool, $clock, failureThreshold: 5, openSeconds: 60),
            new RateLimiter($clock, $pool, 1000.0, 1000),
            $clock, new Stats(),
        );
    }

    public function testLogBuffersAndFlushSendsBatchWithApiKey(): void
    {
        $client = $this->client();
        $client->log('error', 'db down', ['attempt' => 3]);
        $client->log('warning', 'retrying');

        self::assertCount(0, $this->spy->sent, 'log() must buffer, not send');
        self::assertTrue($client->flush());
        self::assertCount(1, $this->spy->sent, 'flush sends one batch request');

        /** @var RequestInterface $req */
        $req = $this->spy->sent[0];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://app.logs.applogger.eu/v1/logs/batch', (string) $req->getUri());
        self::assertSame('sk_log_secret', $req->getHeaderLine('X-Api-Key'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertNotSame('', $req->getHeaderLine('X-Idempotency-Key'));

        /** @var array{logs: list<array<string, mixed>>} $body */
        $body = json_decode((string) $req->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['logs']);
        self::assertSame('error', $body['logs'][0]['severity']);
        self::assertSame('db down', $body['logs'][0]['message']);
        self::assertSame('3', $body['logs'][0]['context']['attempt']);

        self::assertSame(2, $client->getStats()['sent']);
    }

    public function testMessageAndContextAreScrubbed(): void
    {
        $client = $this->client();
        $client->log('info', 'token sk_log_secret leaked', ['password' => 'hunter2']);
        $client->flush();

        /** @var RequestInterface $req */
        $req = $this->spy->sent[0];
        /** @var array{logs: list<array<string, mixed>>} $body */
        $body = json_decode((string) $req->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sk_log_secret', $body['logs'][0]['message']);
        self::assertSame('[REDACTED]', $body['logs'][0]['context']['password']);
    }

    public function testBufferCapDropsAndCounts(): void
    {
        $client = $this->client();
        for ($i = 0; $i < 150; ++$i) {
            $client->log('info', 'msg '.$i);
        }
        self::assertSame(50, $client->getStats()['dropped_buffer']); // cap 100
        $client->flush();
        self::assertSame(100, $client->getStats()['sent']);
    }

    public function testServer429RecordsRetryAfterAndDoesNotThrow(): void
    {
        $client = $this->client(429, '1');
        $client->log('error', 'x');
        $client->flush(); // never throws; return value not asserted
        self::assertGreaterThanOrEqual(1, $client->getStats()['dropped_rate_limit']);
    }

    public function testFlushBudgetDropsRemainingChunks(): void
    {
        // Build a client with a large buffer cap so 2500 entries can be buffered.
        $this->spy = new \stdClass();
        $this->spy->sent = [];

        $psr18 = new class($this->spy) implements ClientInterface {
            public function __construct(private readonly \stdClass $spy)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->spy->sent[] = $request;

                return new Response(202);
            }
        };

        $config = LogConfig::fromArray([
            'log_endpoint' => 'https://app.logs.applogger.eu',
            'log_token' => 'sk_log_secret',
            'app_name' => 'app',
            'environment' => 'production',
            'max_buffered_logs' => 3000,
        ]);
        $psr17 = new Psr17Factory();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $pool = new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_log_budget_'.uniqid('', true));

        $client = new LogClient(
            $config, $psr18, $psr17, $psr17, new LogPayloadFactory(),
            new DataScrubber(['password'], []),
            new CircuitBreaker($pool, $clock, failureThreshold: 5, openSeconds: 60),
            new RateLimiter($clock, $pool, 100000.0, 100000),
            $clock, new Stats(),
        );

        // Buffer 2500 entries → 3 chunks of 1000/1000/500.
        for ($i = 0; $i < 2500; ++$i) {
            $client->log('info', 'msg '.$i);
        }

        // Flush with a zero budget: the deadline fires after the first chunk at most.
        $result = $client->flush(0.0);

        $stats = $client->getStats();
        // With budget=0.0, at least some chunks must have been budget-dropped.
        self::assertGreaterThan(0, $stats['dropped_budget'], 'dropped_budget must be > 0 with zero budget');
        self::assertLessThan(2500, $stats['sent'], 'not all 2500 entries should have been sent');
        // flush() returns false when budget is exhausted mid-loop.
        self::assertFalse($result);
    }

    public function testDisabledConfigDropsWithoutSending(): void
    {
        $config = LogConfig::fromArray([]); // no endpoint/token
        $psr17 = new Psr17Factory();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01'));
        $pool = new FilesystemPsr6Pool(sys_get_temp_dir().'/applogger_log_dis_'.uniqid('', true));
        $psr18 = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('must not be called');
            }
        };
        $client = new LogClient(
            $config, $psr18, $psr17, $psr17, new LogPayloadFactory(), new DataScrubber([]),
            new CircuitBreaker($pool, $clock, failureThreshold: 5, openSeconds: 60),
            new RateLimiter($clock, $pool, 1000.0, 1000), $clock, new Stats(),
        );
        $client->log('error', 'x');
        self::assertTrue($client->flush());
        self::assertSame(0, $client->getStats()['sent']);
    }
}
