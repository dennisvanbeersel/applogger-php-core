<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Context;

use ApplicationLogger\Sdk\Context\GlobalsContextCollector;
use ApplicationLogger\Sdk\DataScrubber;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class GlobalsContextCollectorTest extends TestCase
{
    /** @param array<string, mixed> $server */
    private function collector(array $server): GlobalsContextCollector
    {
        return new GlobalsContextCollector(new DataScrubber(['password', 'token']), 'test-salt', $server);
    }

    public function testCollectsAllowlistedFieldsAndPseudonymizesIp(): void
    {
        $ctx = $this->collector([
            'REQUEST_URI' => '/checkout?token=abc&step=2',
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => '203.0.113.55',
            'HTTP_USER_AGENT' => 'Mozilla',
            'SERVER_NAME' => 'shop.example',
        ])->collect();

        self::assertSame('POST', $ctx['http_method']);
        self::assertSame('203.0.113.0', $ctx['ip_address']);            // last octet masked
        self::assertStringContainsString('token=[REDACTED]', $ctx['url']); // query scrubbed
        self::assertStringContainsString('PHP ', $ctx['runtime']);
        self::assertEmpty(array_diff(array_keys($ctx['server']), ['REQUEST_URI', 'REQUEST_METHOD', 'SERVER_NAME', 'HTTP_HOST', 'HTTP_USER_AGENT']));
    }

    public function testNeverEmitsCredentialsOrEnv(): void
    {
        $ctx = $this->collector([
            'REQUEST_URI' => '/x',
            'HTTP_COOKIE' => 'session=secret',
            'HTTP_AUTHORIZATION' => 'Bearer xyz',
            'PHP_AUTH_PW' => 'hunter2',
            'PHP_AUTH_USER' => 'admin',
            'DATABASE_URL' => 'postgres://u:p@h/db',
        ])->collect();

        $flat = json_encode($ctx, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret', $flat);
        self::assertStringNotContainsString('Bearer xyz', $flat);
        self::assertStringNotContainsString('hunter2', $flat);
        self::assertStringNotContainsString('postgres://', $flat);
    }

    public function testPrefersForwardedForFirstHop(): void
    {
        $ctx = $this->collector([
            'REQUEST_URI' => '/x',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.23, 10.0.0.1',
            'REMOTE_ADDR' => '10.0.0.1',
        ])->collect();
        self::assertSame('198.51.100.0', $ctx['ip_address']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSessionIdIsKeyedHashedNeverRaw(): void
    {
        // Drive collect() with a REAL active session so a regression that omits or
        // raw-emits session_hash is caught. Isolated process → no output yet → the
        // session can actually start (headers_sent() is false).
        $salt = 'my-test-salt';
        $sessionId = 'sess-id-abcdef0123456789';
        session_id($sessionId);
        self::assertTrue(@session_start(), 'session must start in the isolated test process');

        try {
            $ctx = (new GlobalsContextCollector(new DataScrubber([]), $salt, ['REQUEST_URI' => '/x']))->collect();

            self::assertArrayHasKey('session_hash', $ctx);
            // Keyed HMAC, never the raw id.
            self::assertSame(hash_hmac('sha256', $sessionId, $salt), $ctx['session_hash']);
            self::assertNotSame($sessionId, $ctx['session_hash']);
            self::assertStringNotContainsString($sessionId, json_encode($ctx, \JSON_THROW_ON_ERROR));
        } finally {
            session_write_close();
        }
    }
}
