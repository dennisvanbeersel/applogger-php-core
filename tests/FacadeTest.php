<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Breadcrumb;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Hub;
use ApplicationLogger\Sdk\Severity;
use PHPUnit\Framework\TestCase;

use function ApplicationLogger\Sdk\addBreadcrumb;
use function ApplicationLogger\Sdk\captureEvent;
use function ApplicationLogger\Sdk\captureException;
use function ApplicationLogger\Sdk\captureMessage;
use function ApplicationLogger\Sdk\configureScope;
use function ApplicationLogger\Sdk\flush;
use function ApplicationLogger\Sdk\init;
use function ApplicationLogger\Sdk\logger;
use function ApplicationLogger\Sdk\withScope;

final class FacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Hub::reset();
    }

    public function testInitThenCaptureDoesNotThrowAndFlushReturnsTrue(): void
    {
        // No DSN → TransportFactory returns NullTransport; flush always returns true.
        // default_integrations=false keeps this test from installing global handlers.
        init(['default_integrations' => false]);
        captureException(new \RuntimeException('boom'));
        self::assertTrue(flush());
    }

    public function testCaptureBeforeInitIsSafeNoOp(): void
    {
        Hub::reset();
        captureException(new \RuntimeException('boom'));
        self::assertTrue(flush());
    }

    public function testAllFacadeFunctionsRunAfterInit(): void
    {
        $this->expectNotToPerformAssertions();
        init(['dsn' => 'https://applogger.eu/0xP', 'default_integrations' => false]);
        captureMessage('hi');
        addBreadcrumb(new Breadcrumb('nav', 'x', Severity::Info, [], new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'))));
        configureScope(static fn ($s) => $s->setTag('k', 'v'));
        withScope(static fn ($s) => $s->setTag('k2', 'v2'));
    }

    public function testAllFacadeFunctionsAreNoOpsBeforeInit(): void
    {
        $this->expectNotToPerformAssertions();
        Hub::reset();
        captureMessage('hi');
        addBreadcrumb(new Breadcrumb('nav', 'x', Severity::Info, [], new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'))));
        configureScope(static fn ($s) => $s->setTag('k', 'v'));
        withScope(static fn ($s) => $s->setTag('k2', 'v2'));
    }

    public function testCaptureEventFacadeRoutesToHub(): void
    {
        init(['dsn' => 'https://applogger.eu/0xP', 'default_integrations' => false]);

        $event = new Event(
            type: 'X', message: 'm', file: 'f', line: 1,
            level: Severity::Error, environment: 'production', release: null,
            timestamp: new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')),
        );
        // Must not throw and must reach the current Hub (no exception = routed).
        captureEvent($event);
        self::assertNotNull(Hub::getCurrent());
    }

    public function testLoggerFacadeReturnsNullLoggerWithoutLogConfig(): void
    {
        init(['dsn' => 'https://applogger.eu/0xP', 'default_integrations' => false]);
        self::assertInstanceOf(\Psr\Log\NullLogger::class, logger());
    }

    public function testLoggerFacadeReturnsPsr3LoggerWhenConfigured(): void
    {
        init([
            'dsn' => 'https://applogger.eu/0xP',
            'default_integrations' => false,
            'log_endpoint' => 'https://app.logs.applogger.eu',
            'log_token' => 'sk_log_x',
        ]);
        self::assertInstanceOf(\ApplicationLogger\Sdk\Log\Psr3Logger::class, logger());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testInitRegistersHandlersOnceAndIsIdempotent(): void
    {
        \ApplicationLogger\Sdk\ErrorHandler::resetForTests();

        // Capture whatever is on the handler stack BEFORE init (in the isolated
        // subprocess this is PHPUnit's own error handler, or null).
        $preInit = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        \ApplicationLogger\Sdk\init(['dsn' => 'https://applogger.eu/0xP', 'flush_mode' => 'cli']);
        $afterFirst = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        // init() MUST have changed the top-of-stack handler (i.e. actually registered ours).
        // assertNotNull would be vacuous here: PHPUnit's subprocess handler is non-null regardless.
        self::assertNotSame($preInit, $afterFirst, 'init() must install a new error handler');

        // Second init must NOT push another handler (register-once sentinel).
        \ApplicationLogger\Sdk\init(['dsn' => 'https://applogger.eu/0xP', 'flush_mode' => 'cli']);
        $afterSecond = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        self::assertSame($afterFirst, $afterSecond, 'handlers must register exactly once across inits');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testDefaultIntegrationsFalseSkipsRegistration(): void
    {
        \ApplicationLogger\Sdk\ErrorHandler::resetForTests();

        $before = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        \ApplicationLogger\Sdk\init(['dsn' => 'https://applogger.eu/0xP', 'default_integrations' => false]);

        $after = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        self::assertSame($before, $after, 'default_integrations=false must not install handlers');
    }
}
