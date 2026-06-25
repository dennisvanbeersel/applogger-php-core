<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\DataScrubber;
use PHPUnit\Framework\TestCase;

final class DataScrubberTest extends TestCase
{
    private function scrubber(): DataScrubber
    {
        return new DataScrubber(['password', 'token', 'authorization'], ['https://applogger.eu/secret-dsn']);
    }

    public function testRedactsSensitiveKeysRecursively(): void
    {
        $out = $this->scrubber()->scrub(['user' => ['password' => 'hunter2', 'name' => 'jo'], 'api_token' => 'x']);
        self::assertSame('[REDACTED]', $out['user']['password']);
        self::assertSame('jo', $out['user']['name']);
        self::assertSame('[REDACTED]', $out['api_token']);
    }

    public function testScrubUrlRedactsUserinfoAndSensitiveQuery(): void
    {
        $out = $this->scrubber()->scrubUrl('https://u:p@host/path?token=abc&page=2');
        self::assertStringContainsString('[REDACTED]@host', $out);
        self::assertStringContainsString('token=[REDACTED]', $out);
        self::assertStringContainsString('page=2', $out);
    }

    public function testAnonymizeIp(): void
    {
        self::assertSame('192.168.1.0', $this->scrubber()->anonymizeIp('192.168.1.100'));
        self::assertSame('2001:db8:1234::', $this->scrubber()->anonymizeIp('2001:db8:1234:5678:9abc:def0:1234:5678'));
        self::assertNull($this->scrubber()->anonymizeIp(null));
    }

    public function testScrubTextRedactsTokenPrefixesAndLiterals(): void
    {
        $s = $this->scrubber();
        self::assertStringNotContainsString('sk_log_', $s->scrubText('key is sk_log_abcDEF123 ok'));
        self::assertStringNotContainsString('AKIA', $s->scrubText('aws AKIAIOSFODNN7EXAMPLE here'));
        self::assertStringNotContainsString('secret-dsn', $s->scrubText('dsn https://applogger.eu/secret-dsn leaked'));
    }

    public function testScrubTextRedactsWholePemPrivateKeyBlock(): void
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\n"
            ."MIIBOgIBAAJBAKj34GkxFhD90vcNLYLInFEX6Ppy1tPf9Cnzj4p4WGeKLs1Pt8Qu\n"
            ."KUpRKfFLfRYC9AIKjbJTWit+CqvjWYzvQwECAwEAAQ==\n"
            .'-----END RSA PRIVATE KEY-----';
        $out = $this->scrubber()->scrubText("here is a key:\n".$pem."\nrest");

        // The body and footer (the actual secret) must be gone, not just the header.
        self::assertStringNotContainsString('MIIBOgIBAAJBAKj34', $out);
        self::assertStringNotContainsString('-----END RSA PRIVATE KEY-----', $out);
        self::assertStringContainsString('[REDACTED]', $out);
        self::assertStringContainsString('rest', $out);
    }

    public function testScrubUrlFailsClosedOnMalformedUrl(): void
    {
        // parse_url() returns false on a seriously malformed URL (bad port). The
        // method must NOT echo it back — it must fail closed to [REDACTED].
        self::assertSame('[REDACTED]', $this->scrubber()->scrubUrl('http://host:-1?token=abc'));
    }

    public function testScrubUrlReturnsQuerylessUrlUnchanged(): void
    {
        // A valid URL with no query has nothing to scrub and must survive intact.
        self::assertSame('/checkout/step/2', $this->scrubber()->scrubUrl('/checkout/step/2'));
    }

    public function testScrubTextLeavesLongStringsUntouched(): void
    {
        $long = str_repeat('a', 9000).'sk_log_x';
        // Over the 8KB cap → returned unchanged (ReDoS/CPU guard). Not a leak path: long bodies are capped elsewhere.
        self::assertSame($long, $this->scrubber()->scrubText($long));
    }

    public function testNeverThrows(): void
    {
        $s = $this->scrubber();
        self::assertSame(['a' => 'b'], $s->scrub(['a' => 'b']));
        self::assertNull($s->scrubUrl(null));
    }

    public function testScrubTextAnchorsPrefixPatterns(): void
    {
        $s = $this->scrubber();
        // real standalone token → redacted
        self::assertStringNotContainsString('sk_log_abc123', $s->scrubText('key sk_log_abc123 here'));
        // benign substring containing "pk_" mid-word (e.g. "opk_") must NOT be redacted
        self::assertStringContainsString('opk_internal', $s->scrubText('the opk_internal flag stays'));
    }
}
