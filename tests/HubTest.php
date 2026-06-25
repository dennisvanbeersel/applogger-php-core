<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Client;
use ApplicationLogger\Sdk\Clock\FrozenClock;
use ApplicationLogger\Sdk\Context\GlobalsContextCollector;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\Hub;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\Scope;
use ApplicationLogger\Sdk\StackTraceParser;
use ApplicationLogger\Sdk\Transport\FileTransport;
use PHPUnit\Framework\TestCase;

final class HubTest extends TestCase
{
    /**
     * @return array{DataScrubber, StackTraceParser, GlobalsContextCollector}
     */
    private function defaultCollaborators(): array
    {
        $scrubber = new DataScrubber([]);

        return [$scrubber, new StackTraceParser(), new GlobalsContextCollector($scrubber, 'test')];
    }

    public function testCaptureExceptionGoesThroughClientAndScope(): void
    {
        $path = sys_get_temp_dir().'/applogger_hub_'.uniqid('', true).'.jsonl';
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        $transport = new FileTransport($path);
        [$scrubber, $parser, $collector] = $this->defaultCollaborators();
        $client = new Client($options, $transport, new FrozenClock(new \DateTimeImmutable('2026-01-01')), $scrubber, $parser, $collector);
        $hub = new Hub($client, new Scope());

        $hub->getScope()->setTag('region', 'eu');
        $hub->captureException(new \RuntimeException('boom'));

        self::assertCount(1, $transport->capturedEvents());
        self::assertSame('eu', $transport->capturedEvents()[0]['tags']['region']);
        @unlink($path);
    }

    public function testWithScopeBreadcrumbsAreIsolatedFromParent(): void
    {
        $path = sys_get_temp_dir().'/applogger_hub_iso_'.uniqid('', true).'.jsonl';
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        [$scrubber, $parser, $collector] = $this->defaultCollaborators();
        $client = new Client($options, new FileTransport($path), new FrozenClock(new \DateTimeImmutable('2026-01-01')), $scrubber, $parser, $collector);
        $hub = new Hub($client, new Scope());

        $hub->withScope(static function (Scope $scope): void {
            $scope->addBreadcrumb(new \ApplicationLogger\Sdk\Breadcrumb('nav', 'inner', \ApplicationLogger\Sdk\Severity::Info, [], new \DateTimeImmutable('2026-01-01')));
        });

        // After withScope restores the parent, the parent must have NO breadcrumbs.
        $event = \ApplicationLogger\Sdk\Event::fromThrowable(new \RuntimeException('x'), \ApplicationLogger\Sdk\Severity::Error, new \DateTimeImmutable('2026-01-01'), 'production', null);
        $hub->getScope()->applyTo($event);
        self::assertSame([], $event->breadcrumbs);
        @unlink($path);
    }

    public function testResetScopeClearsTags(): void
    {
        $path = sys_get_temp_dir().'/applogger_hub2_'.uniqid('', true).'.jsonl';
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        [$scrubber, $parser, $collector] = $this->defaultCollaborators();
        $client = new Client($options, new FileTransport($path), new FrozenClock(new \DateTimeImmutable('2026-01-01')), $scrubber, $parser, $collector);
        $hub = new Hub($client, new Scope());

        $hub->getScope()->setTag('region', 'eu');
        $hub->resetScope();

        $reflectedEvent = \ApplicationLogger\Sdk\Event::fromThrowable(new \RuntimeException('x'), \ApplicationLogger\Sdk\Severity::Error, new \DateTimeImmutable('2026-01-01'), 'production', null);
        $hub->getScope()->applyTo($reflectedEvent);
        self::assertSame([], $reflectedEvent->tags);
        @unlink($path);
    }

    public function testHubExposesOptionalLogClient(): void
    {
        $options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
        [$scrubber, $parser, $collector] = $this->defaultCollaborators();
        $client = new Client($options, new FileTransport(sys_get_temp_dir().'/applogger_hub_lc_'.uniqid('', true).'.jsonl'), new FrozenClock(new \DateTimeImmutable('2026-01-01')), $scrubber, $parser, $collector);
        $hub = new Hub($client, new Scope());
        self::assertNull($hub->getLogClient());
    }
}
