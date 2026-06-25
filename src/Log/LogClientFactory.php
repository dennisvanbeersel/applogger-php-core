<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Log;

use ApplicationLogger\Sdk\Cache\FilesystemPsr6Pool;
use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\Clock\SystemClock;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\RateLimiter;
use ApplicationLogger\Sdk\Stats;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Builds a wired {@see LogClient}, or null when logging is unconfigured / no HTTP client is available. */
final class LogClientFactory
{
    public static function create(LogConfig $config, DataScrubber $scrubber): ?LogClient
    {
        if (!$config->isEnabled()) {
            return null;
        }

        try {
            $requestFactory = Psr17FactoryDiscovery::findRequestFactory();
            $streamFactory = Psr17FactoryDiscovery::findStreamFactory();
            $http = self::buildClient($config, $streamFactory);
        } catch (\Throwable) {
            return null; // no PSR-18/17 available → degrade silently
        }

        try {
            $pool = new FilesystemPsr6Pool($config->cacheDir);
            $clock = new SystemClock();
            $key = substr(hash('xxh128', (string) $config->endpoint), 0, 16);

            $breaker = new CircuitBreaker(
                $pool,
                $clock,
                cacheKey: 'log_cb_'.$key,
            );

            $rateLimiter = new RateLimiter(
                $clock,
                $pool,
                cacheKey: 'log_rl_'.$key,
            );

            return new LogClient(
                $config,
                $http,
                $requestFactory,
                $streamFactory,
                new LogPayloadFactory(),
                $scrubber,
                $breaker,
                $rateLimiter,
                $clock,
                new Stats(),
            );
        } catch (\Throwable) {
            return null; // construction failed (e.g. unwritable cacheDir) → degrade silently
        }
    }

    private static function buildClient(LogConfig $config, StreamFactoryInterface $streamFactory): ClientInterface
    {
        // Prefer a timeout-bounded symfony/http-client so a single sendRequest() can't
        // exceed the budget (mirrors TransportFactory::buildClient). Fall back to bare discovery.
        if (class_exists(\Symfony\Component\HttpClient\Psr18Client::class)) {
            $sf = \Symfony\Component\HttpClient\HttpClient::create([
                'timeout' => $config->timeout,
                'max_duration' => $config->timeout,
            ]);

            return new \Symfony\Component\HttpClient\Psr18Client($sf, null, $streamFactory);
        }

        return Psr18ClientDiscovery::find();
    }
}
