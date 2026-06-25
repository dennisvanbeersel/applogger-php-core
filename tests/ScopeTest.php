<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Breadcrumb;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Scope;
use ApplicationLogger\Sdk\Severity;
use PHPUnit\Framework\TestCase;

final class ScopeTest extends TestCase
{
    private function event(): Event
    {
        return Event::fromThrowable(new \RuntimeException('x'), Severity::Error, new \DateTimeImmutable('2026-01-01'), 'production', null);
    }

    public function testAppliesTagsUserExtraBreadcrumbs(): void
    {
        $scope = new Scope();
        $scope->setTag('region', 'eu');
        $scope->setUser(['id' => 'u1']);
        $scope->setExtra('order', 42);
        $scope->addBreadcrumb(new Breadcrumb('nav', 'home', Severity::Info, [], new \DateTimeImmutable('2026-01-01')));

        $event = $this->event();
        $scope->applyTo($event);

        self::assertSame('eu', $event->tags['region']);
        self::assertSame(['id' => 'u1'], $event->context['user']);
        self::assertSame(42, $event->context['extra']['order']);
        self::assertCount(1, $event->breadcrumbs);
    }

    public function testApplyToPreservesExistingEventBreadcrumbs(): void
    {
        $scope = new Scope();
        $scope->addBreadcrumb(new Breadcrumb('nav', 'from-scope', Severity::Info, [], new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'))));

        $event = $this->event();
        $event->breadcrumbs = [new Breadcrumb('nav', 'pre-existing', Severity::Info, [], new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')))];
        $scope->applyTo($event);

        $messages = array_map(static fn (Breadcrumb $b): string => $b->message, $event->breadcrumbs);
        self::assertSame(['pre-existing', 'from-scope'], $messages);
    }

    public function testClearResetsEverything(): void
    {
        $scope = new Scope();
        $scope->setTag('region', 'eu');
        $scope->addBreadcrumb(new Breadcrumb('nav', 'home', Severity::Info, [], new \DateTimeImmutable('2026-01-01')));
        $scope->setUser(['id' => 'u1']);
        $scope->setExtra('k', 'v');
        $scope->clear();

        $event = $this->event();
        $scope->applyTo($event);

        self::assertSame([], $event->tags);
        self::assertSame([], $event->breadcrumbs);
        self::assertArrayNotHasKey('user', $event->context);
        self::assertArrayNotHasKey('extra', $event->context);
    }
}
