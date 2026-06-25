<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Severity;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function testServerLevelsAreTheFiveAllowedStrings(): void
    {
        self::assertSame('debug', Severity::Debug->toServerLevel());
        self::assertSame('info', Severity::Info->toServerLevel());
        self::assertSame('warning', Severity::Warning->toServerLevel());
        self::assertSame('error', Severity::Error->toServerLevel());
        self::assertSame('fatal', Severity::Fatal->toServerLevel());
    }

    public function testFromNameMapsAliasesAndDefaultsToError(): void
    {
        self::assertSame(Severity::Warning, Severity::fromName('warn'));
        self::assertSame(Severity::Error, Severity::fromName('critical'));
        self::assertSame(Severity::Error, Severity::fromName('err'));
        self::assertSame(Severity::Info, Severity::fromName('INFO'));
        self::assertSame(Severity::Error, Severity::fromName('nonsense'));
    }
}
