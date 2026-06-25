<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Transport;

use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Stats;

interface TransportInterface
{
    public function send(Event $event): void;

    public function flush(?float $budgetSeconds = null): bool;

    public function getStats(): Stats;
}
