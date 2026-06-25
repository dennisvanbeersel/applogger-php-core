<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Dsn;
use PHPUnit\Framework\TestCase;

final class DsnTest extends TestCase
{
    public function testParsesSchemeHostProjectIdAndKeepsRawByteExact(): void
    {
        $raw = 'https://applogger.eu/0xPROJECT';
        $dsn = Dsn::tryParse($raw);

        self::assertNotNull($dsn);
        self::assertSame($raw, $dsn->raw);
        self::assertSame('https', $dsn->scheme);
        self::assertSame('applogger.eu', $dsn->host);
        self::assertNull($dsn->port);
        self::assertSame('0xPROJECT', $dsn->projectId);
        self::assertSame('https://applogger.eu/api/v1/errors', $dsn->ingestUrl('/api/v1/errors'));
    }

    public function testKeepsPortAndDoesNotNormalise(): void
    {
        $dsn = Dsn::tryParse('https://localhost:8111/abc');
        self::assertNotNull($dsn);
        self::assertSame(8111, $dsn->port);
        self::assertSame('https://localhost:8111/api/v1/errors', $dsn->ingestUrl('/api/v1/errors'));
    }

    public function testReturnsNullForEmptyOrInvalidNeverThrows(): void
    {
        self::assertNull(Dsn::tryParse(''));
        self::assertNull(Dsn::tryParse('   '));
        self::assertNull(Dsn::tryParse('not a url'));
        self::assertNull(Dsn::tryParse('https://applogger.eu')); // no project id
    }

    public function testRejectsUserinfo(): void
    {
        self::assertNull(Dsn::tryParse('https://public_key@applogger.eu/0xP'));
        self::assertNull(Dsn::tryParse('https://user:pass@applogger.eu/0xP'));
    }

    public function testRejectsNonHttpScheme(): void
    {
        self::assertNull(Dsn::tryParse('ftp://applogger.eu/0xP'));
        self::assertNull(Dsn::tryParse('file://applogger.eu/0xP'));
    }

    public function testToStringIsMasked(): void
    {
        $dsn = Dsn::tryParse('https://applogger.eu/0xPROJECT');
        self::assertNotNull($dsn);
        self::assertStringNotContainsString('https://', (string) $dsn);
        self::assertStringContainsString('applogger.eu/0xPROJECT', (string) $dsn);
    }

    public function testIngestUrlNormalisesMissingLeadingSlash(): void
    {
        $dsn = Dsn::tryParse('https://applogger.eu/0xP');
        self::assertNotNull($dsn);
        self::assertSame('https://applogger.eu/api/v1/errors', $dsn->ingestUrl('api/v1/errors'));
        self::assertSame('https://applogger.eu/api/v1/errors', $dsn->ingestUrl('/api/v1/errors'));
    }
}
