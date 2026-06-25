<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

use ApplicationLogger\Sdk\Log\LogClient;

final class Hub
{
    private static ?self $current = null;

    public function __construct(
        private readonly Client $client,
        private Scope $scope,
        private readonly ?LogClient $logClient = null,
    ) {
    }

    public static function getCurrent(): ?self
    {
        return self::$current;
    }

    public static function setCurrent(self $hub): void
    {
        self::$current = $hub;
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getLogClient(): ?LogClient
    {
        return $this->logClient;
    }

    public function getScope(): Scope
    {
        return $this->scope;
    }

    public function captureException(\Throwable $e): void
    {
        $this->client->captureException($e, $this->scope);
    }

    public function captureMessage(string $message, Severity $level): void
    {
        $this->client->captureMessage($message, $level, $this->scope);
    }

    public function captureEvent(Event $event): void
    {
        $this->client->captureEvent($event, $this->scope);
    }

    public function captureFatalEvent(Event $event): void
    {
        $this->client->captureFatalEvent($event);
    }

    public function configureScope(callable $callback): void
    {
        $callback($this->scope);
    }

    public function withScope(callable $callback): void
    {
        $previous = $this->scope;
        $this->scope = clone $previous;
        try {
            $callback($this->scope);
        } finally {
            $this->scope = $previous;
        }
    }

    public function resetScope(): void
    {
        $this->scope->clear();
    }
}
