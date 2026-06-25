<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Log;

use ApplicationLogger\Sdk\Log\LogPayloadFactory;
use ApplicationLogger\Sdk\Log\LogRecord;
use PHPUnit\Framework\TestCase;

final class LogPayloadFactoryTest extends TestCase
{
    /** @param array<string, mixed> $context */
    private function record(string $message, array $context = [], ?string $app = 'app', ?string $env = 'production'): LogRecord
    {
        return new LogRecord('warning', $message, $context, new \DateTimeImmutable('2026-01-01T12:00:00+00:00'), $app, $env);
    }

    public function testSerializesCoreFields(): void
    {
        $wire = (new LogPayloadFactory())->toWire($this->record('hello', ['user_id' => 7, 'ok' => true]));
        self::assertSame('warning', $wire['severity']);
        self::assertSame('hello', $wire['message']);
        self::assertSame('2026-01-01T12:00:00+00:00', $wire['timestamp']);
        self::assertSame('app', $wire['app_name']);
        self::assertSame('production', $wire['environment']);
        // context values stringified to a string map
        self::assertSame(['user_id' => '7', 'ok' => 'true'], $wire['context']);
    }

    public function testOmitsEmptyOptionalFields(): void
    {
        $wire = (new LogPayloadFactory())->toWire($this->record('x', [], null, null));
        self::assertArrayNotHasKey('app_name', $wire);
        self::assertArrayNotHasKey('environment', $wire);
        self::assertArrayNotHasKey('context', $wire);
    }

    public function testByteCaps(): void
    {
        $factory = new LogPayloadFactory();
        $wire = $factory->toWire($this->record(str_repeat('m', 70000), ['k' => str_repeat('v', 70000)], str_repeat('a', 60), str_repeat('e', 80)));
        self::assertSame(65536, \strlen($wire['message']));
        self::assertSame(48, \strlen($wire['app_name']));
        self::assertSame(64, \strlen($wire['environment']));
        self::assertSame(65536, \strlen($wire['context']['k']));
    }

    public function testContextKeyCap(): void
    {
        $factory = new LogPayloadFactory();
        $longKey = str_repeat('k', 300);
        $wire = $factory->toWire($this->record('msg', [$longKey => 'v']));

        /** @var array<string, string> $context */
        $context = $wire['context'];
        $keys = array_keys($context);
        self::assertCount(1, $keys);
        self::assertSame(256, \strlen($keys[0]), 'context key must be truncated to 256 bytes');
        self::assertSame('v', $context[$keys[0]]);
    }

    public function testCapIsUtf8Safe(): void
    {
        // '€' is 3 bytes; 30000 repetitions = 90000 bytes, well above the 65536 cap.
        $message = str_repeat('€', 30000);
        $wire = (new LogPayloadFactory())->toWire($this->record($message));

        self::assertLessThanOrEqual(65536, \strlen($wire['message']), 'capped message must not exceed 65536 bytes');
        self::assertTrue(mb_check_encoding($wire['message'], 'UTF-8'), 'capped message must be valid UTF-8');
        // json_encode with JSON_THROW_ON_ERROR must not throw on invalid UTF-8
        self::assertIsString(json_encode($wire, \JSON_THROW_ON_ERROR));
    }

    public function testStringifyHandlesNonScalarContext(): void
    {
        $context = [
            'nested' => ['a' => 1, 'b' => 2],
            'err' => new \RuntimeException('boom'),
            'scalar' => 'hello',
        ];
        $wire = (new LogPayloadFactory())->toWire($this->record('msg', $context));

        self::assertNotSame('<unserializable log entry>', $wire['message'], 'entry must not collapse to fallback');
        /** @var array<string, string> $ctx */
        $ctx = $wire['context'];
        self::assertSame(json_encode(['a' => 1, 'b' => 2]), $ctx['nested'], 'array context value must be JSON-encoded');
        self::assertSame('RuntimeException: boom', $ctx['err'], 'Throwable context value must use class: message format');
        self::assertSame('hello', $ctx['scalar'], 'scalar context value must pass through as string');
    }
}
