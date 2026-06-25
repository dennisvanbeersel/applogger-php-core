<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Log;

use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\Log\LogClient;
use ApplicationLogger\Sdk\Log\LogClientFactory;
use ApplicationLogger\Sdk\Log\LogConfig;
use PHPUnit\Framework\TestCase;

final class LogClientFactoryTest extends TestCase
{
    public function testReturnsNullWhenDisabled(): void
    {
        self::assertNull(LogClientFactory::create(LogConfig::fromArray([]), new DataScrubber([])));
    }

    public function testBuildsClientWhenConfigured(): void
    {
        if (!class_exists(\Symfony\Component\HttpClient\Psr18Client::class)) {
            try {
                \Http\Discovery\Psr18ClientDiscovery::find();
            } catch (\Throwable) {
                self::markTestSkipped('No PSR-18 client discoverable');
            }
        }

        $config = LogConfig::fromArray(['log_endpoint' => 'https://app.logs.applogger.eu', 'log_token' => 'sk_log_x']);
        $client = LogClientFactory::create($config, new DataScrubber([]));
        // A PSR-18 client is discoverable in dev (symfony/http-client is a dev dep), so this is non-null.
        self::assertInstanceOf(LogClient::class, $client);
    }
}
