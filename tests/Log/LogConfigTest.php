<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Log;

use ApplicationLogger\Sdk\Log\LogConfig;
use PHPUnit\Framework\TestCase;

final class LogConfigTest extends TestCase
{
    public function testEnabledWhenEndpointAndTokenPresent(): void
    {
        $c = LogConfig::fromArray([
            'log_endpoint' => 'https://myapp.logs.applogger.eu/',
            'log_token' => 'sk_log_abc123',
            'app_name' => 'myapp',
            'environment' => 'production',
        ]);
        self::assertTrue($c->isEnabled());
        self::assertSame('https://myapp.logs.applogger.eu', $c->endpoint); // trailing slash stripped
        self::assertSame('sk_log_abc123', $c->token);
        self::assertSame('https://myapp.logs.applogger.eu/v1/logs/batch', $c->batchUrl());
        self::assertSame('myapp', $c->appName);
    }

    public function testDisabledWhenMissingTokenOrEndpoint(): void
    {
        self::assertFalse(LogConfig::fromArray(['log_endpoint' => 'https://x.logs.applogger.eu'])->isEnabled());
        self::assertFalse(LogConfig::fromArray(['log_token' => 'sk_log_x'])->isEnabled());
        self::assertNull(LogConfig::fromArray(['log_token' => 'sk_log_x'])->batchUrl());
    }

    public function testRejectsMalformedEndpoint(): void
    {
        $c = LogConfig::fromArray(['log_endpoint' => 'not a url', 'log_token' => 'sk_log_x']);
        self::assertNull($c->endpoint);
        self::assertFalse($c->isEnabled());
    }

    public function testStripsPathFromEndpoint(): void
    {
        $c = LogConfig::fromArray(['log_endpoint' => 'https://myapp.logs.applogger.eu/v1/logs', 'log_token' => 'sk_log_x']);
        self::assertSame('https://myapp.logs.applogger.eu', $c->endpoint);
    }

    public function testDefaults(): void
    {
        $c = LogConfig::fromArray([]);
        self::assertSame(2.0, $c->timeout);
        self::assertSame(100, $c->maxBufferedLogs);
        self::assertSame(['password', 'passwd', 'secret', 'token', 'api_key', 'auth', 'credential'], $c->scrubFields);
    }
}
