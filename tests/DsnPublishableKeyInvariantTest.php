<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Dsn;
use PHPUnit\Framework\TestCase;

/**
 * 2.1.0 publishable-key invariant guard (design spec §6.1 / §6.4-A).
 *
 * The publishable key (pk_…) is carried OUT-OF-BAND (X-Publishable-Key header
 * + ?pk= query param), never in the DSN. sdk-core ships ZERO feature changes.
 * If a future change relaxes Dsn::tryParse() to accept userinfo so a pk_ could
 * ride in the authority, this test fails — by design.
 */
final class DsnPublishableKeyInvariantTest extends TestCase
{
    public function testPublishableKeyInUserinfoIsRejectedNotParsed(): void
    {
        // A pk_ smuggled into DSN userinfo must NOT parse.
        self::assertNull(Dsn::tryParse('https://pk_live_a1b2c3d4@applogger.eu/0xPROJECT'));
        self::assertNull(Dsn::tryParse('https://pk_test_deadbeef:@applogger.eu/0xPROJECT'));
    }

    public function testCanonicalDsnHasNoUserinfoSlotAndKeepsProjectId(): void
    {
        $dsn = Dsn::tryParse('https://applogger.eu/0xPROJECT');
        self::assertNotNull($dsn);
        self::assertSame('0xPROJECT', $dsn->projectId);
        // No public property carries a key — the key lives outside the DSN.
        self::assertObjectNotHasProperty('publishableKey', $dsn);
        self::assertObjectNotHasProperty('key', $dsn);
    }
}
