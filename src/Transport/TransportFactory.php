<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Transport;

use ApplicationLogger\Sdk\Cache\FilesystemPsr6Pool;
use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\Clock\SystemClock;
use ApplicationLogger\Sdk\Deduplicator;
use ApplicationLogger\Sdk\ErrorPayloadFactory;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\RateLimiter;
use ApplicationLogger\Sdk\Stats;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;

final class TransportFactory
{
    public static function create(Options $options): TransportInterface
    {
        if (null === $options->dsn) {
            return new NullTransport();
        }

        try {
            $requestFactory = Psr17FactoryDiscovery::findRequestFactory();
            $streamFactory = Psr17FactoryDiscovery::findStreamFactory();
            $client = self::buildClient($options, $streamFactory);
        } catch (\Throwable $t) {
            // Rule #1: degrade, never throw and never "fail loudly".
            $options->logger->warning('AppLogger: no PSR-18 client discoverable, telemetry disabled', ['exception' => $t::class]);

            return new NullTransport();
        }

        $breaker = new CircuitBreaker(
            new FilesystemPsr6Pool($options->cacheDir),
            new SystemClock(),
            jitterSeconds: 3,
            cacheKey: 'applogger.breaker.'.self::dsnHash($options->dsn),
        );

        $rateLimiter = new RateLimiter(
            new SystemClock(),
            new FilesystemPsr6Pool($options->cacheDir),
            cacheKey: 'applogger.ratelimit.'.self::dsnHash($options->dsn),
        );

        return new HttpTransport(
            $options,
            $client,
            $requestFactory,
            $streamFactory,
            new ErrorPayloadFactory(),
            new FastCgiRequestFinisher(),
            $breaker,
            new Stats(),
            $rateLimiter,
            new Deduplicator(),
        );
    }

    private static function dsnHash(\ApplicationLogger\Sdk\Dsn $dsn): string
    {
        return substr(hash('xxh128', $dsn->raw), 0, 16);
    }

    private static function buildClient(
        Options $options,
        \Psr\Http\Message\StreamFactoryInterface $streamFactory,
    ): \Psr\Http\Client\ClientInterface {
        // Prefer a timeout-bounded symfony/http-client so a single sendRequest() can't
        // exceed the budget (closes the Phase-2A unbounded-in-flight gap). Fall back to
        // bare discovery (timeout then depends on the discovered client).
        if (class_exists(\Symfony\Component\HttpClient\Psr18Client::class)) {
            $sf = \Symfony\Component\HttpClient\HttpClient::create([
                'timeout' => $options->timeout,
                'max_duration' => $options->timeout,
            ]);

            return new \Symfony\Component\HttpClient\Psr18Client($sf, null, $streamFactory);
        }

        return Psr18ClientDiscovery::find();
    }
}
