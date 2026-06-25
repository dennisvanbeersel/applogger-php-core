<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests;

use ApplicationLogger\Sdk\StackTraceParser;
use PHPUnit\Framework\TestCase;

final class StackTraceParserTest extends TestCase
{
    public function testParsesFramesWithoutArgs(): void
    {
        $e = new \RuntimeException('boom');
        $frames = (new StackTraceParser())->parse($e);

        self::assertNotEmpty($frames);
        foreach ($frames as $frame) {
            self::assertArrayHasKey('file', $frame);
            self::assertArrayHasKey('line', $frame);
            self::assertArrayHasKey('in_app', $frame);
            self::assertArrayNotHasKey('args', $frame); // never capture arguments
        }
    }

    public function testCapsAt100Frames(): void
    {
        $deep = static function (int $n) use (&$deep): void {
            if ($n > 0) {
                $deep($n - 1);
            } else {
                throw new \RuntimeException('deep');
            }
        };
        try {
            $deep(150);
        } catch (\RuntimeException $e) {
            $frames = (new StackTraceParser())->parse($e);
            self::assertLessThanOrEqual(100, \count($frames));
        }
    }
}
