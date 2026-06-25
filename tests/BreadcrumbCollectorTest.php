<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\Breadcrumb;
use ApplicationLogger\Sdk\BreadcrumbCollector;
use ApplicationLogger\Sdk\Severity;
use PHPUnit\Framework\TestCase;

final class BreadcrumbCollectorTest extends TestCase
{
    private function crumb(string $msg): Breadcrumb
    {
        return new Breadcrumb('default', $msg, Severity::Info, [], new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')));
    }

    public function testKeepsOnlyLastMaxInOrder(): void
    {
        $c = new BreadcrumbCollector(max: 3);
        foreach (['a', 'b', 'c', 'd'] as $m) {
            $c->add($this->crumb($m));
        }

        $messages = array_map(static fn (Breadcrumb $b): string => $b->message, $c->all());
        self::assertSame(['b', 'c', 'd'], $messages);
    }

    public function testClearEmptiesBuffer(): void
    {
        $c = new BreadcrumbCollector();
        $c->add($this->crumb('a'));
        $c->clear();
        self::assertSame([], $c->all());
    }

    public function testToArrayShape(): void
    {
        $b = $this->crumb('hello');
        self::assertSame(
            ['category' => 'default', 'message' => 'hello', 'level' => 'info', 'data' => [], 'timestamp' => '2026-01-01T00:00:00+00:00'],
            $b->toArray(),
        );
    }
}
