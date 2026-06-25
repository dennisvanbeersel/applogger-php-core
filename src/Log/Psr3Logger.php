<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Log;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 adapter over {@see LogSink}. Performs PSR-3 `{placeholder}` interpolation,
 * then forwards to the log pipeline. Total — never throws into the host.
 */
final class Psr3Logger extends AbstractLogger
{
    public function __construct(private readonly LogSink $sink)
    {
    }

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        try {
            $this->sink->log(
                \is_string($level) ? $level : LogLevel::INFO,
                $this->interpolate((string) $message, $context),
                $context,
            );
        } catch (\Throwable) {
            // Rule #1: logging must never break the host.
        }
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replacements = [];
        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
