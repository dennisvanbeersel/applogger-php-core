<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Transport;

use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Stats;

final class NullTransport implements TransportInterface
{
    public function send(Event $event): void
    {
    }

    public function flush(?float $budgetSeconds = null): bool
    {
        return true;
    }

    public function getStats(): Stats
    {
        return new Stats();
    }
}
