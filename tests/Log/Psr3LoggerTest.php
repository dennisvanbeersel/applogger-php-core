<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Log;

use ApplicationLogger\Sdk\Log\LogSink;
use ApplicationLogger\Sdk\Log\Psr3Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel as Psr3Level;

final class Psr3LoggerTest extends TestCase
{
    public function testIsAPsr3Logger(): void
    {
        $spy = $this->spy();
        $logger = new Psr3Logger($this->sink($spy));
        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testInterpolatesPlaceholdersAndForwardsLevel(): void
    {
        $spy = $this->spy();
        $logger = new Psr3Logger($this->sink($spy));
        $logger->error('User {id} failed login from {ip}', ['id' => 42, 'ip' => '10.0.0.1', 'extra' => 'kept']);

        self::assertCount(1, $spy->calls);
        self::assertSame('error', $spy->calls[0]['level']);
        self::assertSame('User 42 failed login from 10.0.0.1', $spy->calls[0]['message']);
        self::assertSame(['id' => 42, 'ip' => '10.0.0.1', 'extra' => 'kept'], $spy->calls[0]['context']);
    }

    public function testLevelMethodsMapToPsr3Levels(): void
    {
        $spy = $this->spy();
        $logger = new Psr3Logger($this->sink($spy));
        $logger->warning('w');
        $logger->log(Psr3Level::CRITICAL, 'c');
        self::assertSame('warning', $spy->calls[0]['level']);
        self::assertSame('critical', $spy->calls[1]['level']);
    }

    /** A capture object whose `calls` array each sink invocation is appended to. */
    private function spy(): \stdClass
    {
        $spy = new \stdClass();
        $spy->calls = [];

        return $spy;
    }

    private function sink(\stdClass $spy): LogSink
    {
        return new class($spy) implements LogSink {
            public function __construct(private readonly \stdClass $spy)
            {
            }

            public function log(string $level, string $message, array $context = []): void
            {
                $this->spy->calls[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };
    }
}
