<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

use ApplicationLogger\Sdk\Clock\SystemClock;
use ApplicationLogger\Sdk\Context\GlobalsContextCollector;
use ApplicationLogger\Sdk\Transport\TransportFactory;

if (!\function_exists(__NAMESPACE__.'\\init')) {
    /** @param array<string, mixed> $options */
    function init(array $options): void
    {
        $opts = Options::fromArray($options);
        $literals = array_values(array_filter(
            [null !== $opts->dsn ? $opts->dsn->raw : null, $opts->apiKey],
            static fn (?string $v): bool => \is_string($v) && '' !== $v,
        ));
        $scrubber = new DataScrubber($opts->scrubFields, $literals);
        $collector = new GlobalsContextCollector($scrubber, $opts->sessionHashSalt);
        $clock = new SystemClock();
        $client = new Client($opts, TransportFactory::create($opts), $clock, $scrubber, new StackTraceParser(), $collector);

        $logConfig = Log\LogConfig::fromArray($options);
        $logClient = Log\LogClientFactory::create($logConfig, $scrubber);

        $hub = new Hub($client, new Scope($opts->maxBreadcrumbs), $logClient);
        Hub::setCurrent($hub);

        if (null !== $logClient && $opts->defaultIntegrations) {
            register_shutdown_function(static function () use ($logClient): void {
                try {
                    $logClient->flush();
                } catch (\Throwable) {
                    // never amplify
                }
            });
        }

        if ($opts->defaultIntegrations) {
            $mode = DeliveryMode::resolve($opts->flushMode, DeliveryMode::detectWorker(), DeliveryMode::detectFastCgi());
            $handler = new ErrorHandler(
                new MemoryReservation(),
                $opts->environment,
                $opts->release,
                $clock,
                DeliveryMode::flushesOnShutdown($mode),
            );
            $handler->register();
        }
    }
}

if (!\function_exists(__NAMESPACE__.'\\captureException')) {
    function captureException(\Throwable $e): void
    {
        Hub::getCurrent()?->captureException($e);
    }
}

if (!\function_exists(__NAMESPACE__.'\\captureMessage')) {
    function captureMessage(string $message, Severity $level = Severity::Info): void
    {
        Hub::getCurrent()?->captureMessage($message, $level);
    }
}

if (!\function_exists(__NAMESPACE__.'\\captureEvent')) {
    function captureEvent(Event $event): void
    {
        Hub::getCurrent()?->captureEvent($event);
    }
}

if (!\function_exists(__NAMESPACE__.'\\addBreadcrumb')) {
    function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        Hub::getCurrent()?->getScope()->addBreadcrumb($breadcrumb);
    }
}

if (!\function_exists(__NAMESPACE__.'\\configureScope')) {
    function configureScope(callable $callback): void
    {
        Hub::getCurrent()?->configureScope($callback);
    }
}

if (!\function_exists(__NAMESPACE__.'\\withScope')) {
    function withScope(callable $callback): void
    {
        Hub::getCurrent()?->withScope($callback);
    }
}

if (!\function_exists(__NAMESPACE__.'\\flush')) {
    function flush(?float $budget = null): bool
    {
        return Hub::getCurrent()?->getClient()->flush($budget) ?? true;
    }
}

if (!\function_exists(__NAMESPACE__.'\\logger')) {
    function logger(): \Psr\Log\LoggerInterface
    {
        $logClient = Hub::getCurrent()?->getLogClient();

        return null !== $logClient ? new Log\Psr3Logger($logClient) : new \Psr\Log\NullLogger();
    }
}

if (!\function_exists(__NAMESPACE__.'\\diagnose')) {
    /** @param array<string, mixed> $options */
    function diagnose(array $options = [], bool $sendTestEvent = true): Diagnostic\DiagnosticReport
    {
        return (new Diagnostic\Diagnostics())->run($options, $sendTestEvent);
    }
}
