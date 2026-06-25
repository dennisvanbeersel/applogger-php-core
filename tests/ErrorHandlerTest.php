<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Client;
use ApplicationLogger\Sdk\Clock\FrozenClock;
use ApplicationLogger\Sdk\Context\GlobalsContextCollector;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\ErrorHandler;
use ApplicationLogger\Sdk\Hub;
use ApplicationLogger\Sdk\MemoryReservation;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\Scope;
use ApplicationLogger\Sdk\StackTraceParser;
use ApplicationLogger\Sdk\Transport\FileTransport;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerTest extends TestCase
{
    private string $path;
    /** @var int Saved error_reporting level, restored after each test. */
    private int $savedErrorReporting;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/applogger_eh_'.uniqid('', true).'.ndjson';
        // PHPUnit sets error_reporting to 245 (UNHANDLEABLE_LEVELS only) while its own
        // ErrorHandler is active. handleError honours the @-suppression check
        // (0 === error_reporting() & $errno), so we must restore E_ALL here to match
        // the production environment where the full error mask is in effect.
        $this->savedErrorReporting = error_reporting(\E_ALL);
        ErrorHandler::resetForTests();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        Hub::reset();
        ErrorHandler::resetForTests();
        error_reporting($this->savedErrorReporting);
    }

    /** @return array{ErrorHandler, FileTransport} */
    private function handler(bool $flushOnShutdown = true): array
    {
        $scrubber = new DataScrubber(['password']);
        $parser = new StackTraceParser();
        $collector = new GlobalsContextCollector($scrubber, 'test');
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $transport = new FileTransport($this->path);
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $client = new Client($options, $transport, $clock, $scrubber, $parser, $collector);
        $hub = new Hub($client, new Scope());
        Hub::setCurrent($hub);
        $handler = new ErrorHandler(new MemoryReservation(), 'production', null, $clock, $flushOnShutdown);

        return [$handler, $transport];
    }

    public function testHandleErrorCapturesAndChainsPreviousBoolean(): void
    {
        [$handler, $transport] = $this->handler();
        $called = false;
        $previous = static function () use (&$called): bool {
            $called = true;

            return true; // previous returns true → our return must propagate it unchanged
        };

        $ret = $handler->handleError(\E_USER_ERROR, 'bad thing', '/app/a.php', 10, $previous);
        self::assertTrue($transport->flush());

        self::assertTrue($ret, 'previous handler boolean must propagate unchanged');
        self::assertTrue($called, 'previous handler must be called after ours');
        $events = $transport->capturedEvents();
        self::assertCount(1, $events);
        self::assertSame('bad thing', $events[0]['message']);
        self::assertSame('error', $events[0]['level']);
    }

    public function testHandleErrorRespectsAtSuppression(): void
    {
        [$handler, $transport] = $this->handler();
        $previousErrorReporting = error_reporting();
        try {
            // Simulate `@`: error_reporting() returns 0 for the suppressed expression.
            error_reporting(0);
            $ret = $handler->handleError(\E_WARNING, 'suppressed', '/app/a.php', 3, null);
        } finally {
            error_reporting($previousErrorReporting);
        }
        self::assertTrue($transport->flush());

        self::assertFalse($ret, 'no previous handler → return false');
        self::assertCount(0, $transport->capturedEvents(), 'suppressed (@) errors must not be captured');
    }

    public function testHandleExceptionCapturesErrorLevelAndChains(): void
    {
        [$handler, $transport] = $this->handler();
        $called = false;
        $previous = static function (\Throwable $e) use (&$called): void {
            $called = true;
        };

        $handler->handleException(new \RuntimeException('kaboom'), $previous);
        self::assertTrue($transport->flush());

        self::assertTrue($called);
        $events = $transport->capturedEvents();
        self::assertCount(1, $events);
        self::assertSame('kaboom', $events[0]['message']);
        self::assertSame('error', $events[0]['level']);
        self::assertSame(\RuntimeException::class, $events[0]['type']);
    }

    public function testHandleShutdownIgnoresNonFatalAndNull(): void
    {
        [$handler, $transport] = $this->handler();
        $handler->handleShutdown(null);
        $handler->handleShutdown(['type' => \E_WARNING, 'message' => 'w', 'file' => 'f', 'line' => 1]);
        self::assertTrue($transport->flush());
        self::assertCount(0, $transport->capturedEvents());
    }

    public function testHandleShutdownCapturesFatalAsContractSafeFatalEvent(): void
    {
        [$handler, $transport] = $this->handler();
        $handler->handleShutdown(['type' => \E_ERROR, 'message' => 'Call to undefined function x()', 'file' => '/app/b.php', 'line' => 0]);
        self::assertTrue($transport->flush());

        $events = $transport->capturedEvents();
        self::assertCount(1, $events);
        self::assertSame('fatal', $events[0]['level']);
        self::assertNotSame('', $events[0]['type']);     // non-blank
        self::assertNotSame('', $events[0]['message']);  // non-blank
        self::assertGreaterThanOrEqual(1, $events[0]['line']); // line >= 1 (was 0)
    }

    public function testHandleShutdownOomBuildsPartialOomMarkedEvent(): void
    {
        [$handler, $transport] = $this->handler();
        $handler->handleShutdown([
            'type' => \E_ERROR,
            'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
            'file' => '/app/c.php',
            'line' => 55,
        ]);
        self::assertTrue($transport->flush());

        $events = $transport->capturedEvents();
        self::assertCount(1, $events);
        self::assertTrue($events[0]['partial']);
        self::assertTrue($events[0]['tags']['oom']);
        self::assertSame('fatal', $events[0]['level']);
    }

    public function testReentrancyGuardPreventsRecursion(): void
    {
        [$handler, $transport] = $this->handler();
        // A previous handler that re-enters handleError must not cause a second capture
        // for the same fault (the static guard short-circuits the nested call).
        $previous = function (int $errno, string $msg, string $file, int $line) use ($handler): bool {
            $handler->handleError($errno, 'nested '.$msg, $file, $line, null);

            return false;
        };
        $handler->handleError(\E_USER_ERROR, 'outer', '/app/a.php', 9, $previous);
        self::assertTrue($transport->flush());

        self::assertCount(1, $transport->capturedEvents(), 'nested re-entry must be guarded to a single capture');
    }

    public function testExceptionReentrancyGuardPreventsRecursion(): void
    {
        [$handler, $transport] = $this->handler();
        // A previous exception handler that re-enters handleException must not cause a
        // second capture for the same fault (the static guard short-circuits the nested call).
        $previous = function (\Throwable $e) use ($handler): void {
            $handler->handleException(new \RuntimeException('nested'), null);
        };
        $handler->handleException(new \RuntimeException('outer'), $previous);
        self::assertTrue($transport->flush());

        self::assertCount(1, $transport->capturedEvents(), 'nested exception re-entry must be guarded to a single capture');
    }

    public function testHandlersRouteThroughCurrentHubAfterSwap(): void
    {
        [$handler, $transportA] = $this->handler();
        // Swap in a SECOND hub with its own transport AFTER the handler was built.
        $pathB = sys_get_temp_dir().'/applogger_eh_b_'.uniqid('', true).'.ndjson';
        $scrubber = new DataScrubber(['password']);
        $collector = new GlobalsContextCollector($scrubber, 'test');
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $transportB = new FileTransport($pathB);
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $hubB = new Hub(new Client($options, $transportB, $clock, $scrubber, new StackTraceParser(), $collector), new Scope());
        Hub::setCurrent($hubB);

        $handler->handleError(\E_USER_ERROR, 'after swap', '/app/a.php', 1, null);
        self::assertTrue($transportB->flush());

        try {
            self::assertCount(0, $transportA->capturedEvents(), 'event must NOT go to the original hub');
            self::assertCount(1, $transportB->capturedEvents(), 'event must route through the current hub');
        } finally {
            @unlink($pathB);
        }
    }
}
