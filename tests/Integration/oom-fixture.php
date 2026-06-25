<?php

declare(strict_types=1);

// Child process: wires the SDK to a FileTransport and deliberately exhausts memory.
// argv[1] = output ndjson path.

require __DIR__.'/../../vendor/autoload.php';

use ApplicationLogger\Sdk\Client;
use ApplicationLogger\Sdk\Clock\SystemClock;
use ApplicationLogger\Sdk\Context\GlobalsContextCollector;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\ErrorHandler;
use ApplicationLogger\Sdk\Hub;
use ApplicationLogger\Sdk\MemoryReservation;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\Scope;
use ApplicationLogger\Sdk\StackTraceParser;
use ApplicationLogger\Sdk\Transport\FileTransport;

$path = $argv[1];

$scrubber = new DataScrubber(['password'], ['https://applogger.eu/0xP']);
$collector = new GlobalsContextCollector($scrubber, 'salt');
$options = Options::fromArray(['dsn' => 'https://applogger.eu/0xP']);
$transport = new FileTransport($path);
$clock = new SystemClock();
$client = new Client($options, $transport, $clock, $scrubber, new StackTraceParser(), $collector);
$hub = new Hub($client, new Scope());
Hub::setCurrent($hub);

$handler = new ErrorHandler(new MemoryReservation(), 'production', null, $clock, true);
$handler->register();

// Exhaust memory: grow a string past the -d memory_limit ceiling.
$buf = '';
/* @phpstan-ignore while.alwaysTrue (intentional infinite growth to force a deterministic OOM fatal) */
while (true) {
    $buf .= str_repeat('x', 1024 * 1024);
}
