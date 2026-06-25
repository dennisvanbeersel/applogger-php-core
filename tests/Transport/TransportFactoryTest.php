<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Transport;

use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\Transport\HttpTransport;
use ApplicationLogger\Sdk\Transport\NullTransport;
use ApplicationLogger\Sdk\Transport\TransportFactory;
use PHPUnit\Framework\TestCase;

final class TransportFactoryTest extends TestCase
{
    public function testReturnsNullTransportWhenDsnMissing(): void
    {
        self::assertInstanceOf(NullTransport::class, TransportFactory::create(Options::fromArray([])));
    }

    public function testReturnsHttpTransportWhenDsnPresentAndClientDiscoverable(): void
    {
        // nyholm/psr7 + symfony/http-client are dev deps, so discovery succeeds here.
        $transport = TransportFactory::create(Options::fromArray(['dsn' => 'https://applogger.eu/0xP']));
        self::assertInstanceOf(HttpTransport::class, $transport);
    }
}
