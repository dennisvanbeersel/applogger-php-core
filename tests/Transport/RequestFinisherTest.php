<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Transport;

use ApplicationLogger\Sdk\Transport\FastCgiRequestFinisher;
use ApplicationLogger\Sdk\Transport\NoopRequestFinisher;
use PHPUnit\Framework\TestCase;

final class RequestFinisherTest extends TestCase
{
    public function testNoopIsNeverAvailableAndFinishDoesNothing(): void
    {
        $finisher = new NoopRequestFinisher();
        self::assertFalse($finisher->isAvailable());
        $finisher->finish(); // must not throw
    }

    public function testFastCgiAvailabilityMatchesFunctionExistence(): void
    {
        $finisher = new FastCgiRequestFinisher();
        self::assertSame(\function_exists('fastcgi_finish_request'), $finisher->isAvailable());
    }
}
