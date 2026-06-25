<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

use Psr\Clock\ClockInterface;

/**
 * Global error/exception/fatal handler. The LOGIC lives in handleError/
 * handleException/handleShutdown (callable, previous-handler-as-data, unit
 * testable in-process). register()/onError/onException/onShutdown are the thin
 * global-registration shim. Total on every path (Rule #1).
 */
final class ErrorHandler
{
    private const FATAL_MASK = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR | \E_USER_ERROR;

    private static bool $registered = false;
    private static bool $inHandler = false;

    /** @var callable|null */
    private $previousError;
    /** @var callable|null */
    private $previousException;

    public function __construct(
        private readonly MemoryReservation $reservation,
        private readonly string $environment,
        private readonly ?string $release,
        private readonly ClockInterface $clock,
        private readonly bool $flushOnShutdown = true,
    ) {
    }

    public function handleError(int $errno, string $message, string $file, int $line, ?callable $previous): bool
    {
        // Honor `@` / error_reporting level exactly as PHP 8.x: skip capture, still chain.
        if (0 === (error_reporting() & $errno)) {
            return null !== $previous ? (bool) $previous($errno, $message, $file, $line) : false;
        }

        // Re-entrancy guard: a nested call (e.g. from a previous-handler that calls us again)
        // must not trigger a second capture. The guard is held for the full duration, including
        // the chain to $previous, so that a re-entering $previous is also blocked.
        if (self::$inHandler) {
            return null !== $previous ? (bool) $previous($errno, $message, $file, $line) : false;
        }

        self::$inHandler = true;
        try {
            try {
                $event = new Event(
                    type: $this->errnoName($errno),
                    message: $message,
                    file: $file,
                    line: $line,
                    level: $this->errnoSeverity($errno),
                    environment: $this->environment,
                    release: $this->release,
                    timestamp: $this->clock->now(),
                );
                $event->stackTrace = $this->backtrace();
                $hub = Hub::getCurrent();
                $hub?->captureEvent($event);
            } catch (\Throwable) {
                // Never amplify (Rule #1).
            }

            return null !== $previous ? (bool) $previous($errno, $message, $file, $line) : false;
        } finally {
            self::$inHandler = false;
        }
    }

    public function handleException(\Throwable $e, ?callable $previous): void
    {
        // Re-entrancy guard: mirrors handleError — guard is held across the full duration,
        // including the chain to $previous, so a re-entering $previous cannot trigger a
        // second capture for the same fault.
        if (self::$inHandler) {
            if (null !== $previous) {
                try {
                    $previous($e);
                } catch (\Throwable) {
                    // A faulty previous exception handler must not break shutdown.
                }
            }

            return;
        }

        self::$inHandler = true;
        try {
            try {
                $event = Event::fromThrowable($e, Severity::Error, $this->clock->now(), $this->environment, $this->release);
                // framesFromTrace() never copies the 'args' key, so call arguments are never captured (privacy).
                $event->stackTrace = $this->framesFromTrace($e->getTrace());
                $hub = Hub::getCurrent();
                $hub?->captureEvent($event);
            } catch (\Throwable) {
                // Never amplify (Rule #1).
            }

            if (null !== $previous) {
                try {
                    $previous($e);
                } catch (\Throwable) {
                    // A faulty previous exception handler must not break shutdown.
                }
            }
        } finally {
            self::$inHandler = false;
        }
    }

    /** @param array{type:int,message:string,file:string,line:int}|null $lastError */
    public function handleShutdown(?array $lastError): void
    {
        try {
            if (null === $lastError || 0 === ($lastError['type'] & self::FATAL_MASK)) {
                return;
            }

            $isOom = str_contains($lastError['message'], 'Allowed memory size');
            if ($isOom) {
                // OOM order: (1) release reservation, (2) bump +5 MiB once.
                $this->reservation->release();
                MemoryLimit::bump(5 * 1024 * 1024);
            }

            $event = new Event(
                type: $this->nonBlank($this->errnoName($lastError['type']), 'FatalError'),
                message: $this->nonBlank($lastError['message'], '<no message>'),
                file: $this->nonBlank($lastError['file'], '<unknown>'),
                line: max(1, $lastError['line']),
                level: Severity::Fatal,
                environment: $this->environment,
                release: $this->release,
                timestamp: $this->clock->now(),
            );

            $hub = Hub::getCurrent();
            if ($isOom) {
                $event->partial = true;
                $event->tags = ['oom' => true, 'partial' => true];
                // Lean path: skip recursive scrub / scope / before_send (allocation discipline).
                $hub?->captureFatalEvent($event);
            } else {
                // Non-OOM fatal: memory is fine, run the full pipeline.
                $hub?->captureEvent($event);
            }
        } catch (\Throwable) {
            // Never amplify.
        }
    }

    public function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        $this->previousError = set_error_handler($this->onError(...));
        $this->previousException = set_exception_handler($this->onException(...));
        register_shutdown_function($this->onShutdown(...));
    }

    public function onError(int $errno, string $message, string $file = '', int $line = 0): bool
    {
        return $this->handleError($errno, $message, $file, $line, $this->previousError);
    }

    public function onException(\Throwable $e): void
    {
        $this->handleException($e, $this->previousException);
    }

    public function onShutdown(): void
    {
        $this->handleShutdown(error_get_last());
        if ($this->flushOnShutdown) {
            try {
                Hub::getCurrent()?->getClient()->flush();
            } catch (\Throwable) {
                // Never amplify.
            }
        }
    }

    /** @internal test-only reset of process-global registration state. */
    public static function resetForTests(): void
    {
        self::$registered = false;
        self::$inHandler = false;
    }

    private function errnoSeverity(int $errno): Severity
    {
        return match ($errno) {
            \E_WARNING, \E_USER_WARNING => Severity::Warning,
            // 2048 = E_STRICT (constant deprecated in PHP 8.4, use raw value to avoid deprecation warning).
            \E_NOTICE, \E_USER_NOTICE, \E_DEPRECATED, \E_USER_DEPRECATED, 2048 => Severity::Info,
            \E_USER_ERROR, \E_RECOVERABLE_ERROR => Severity::Error,
            default => Severity::Warning,
        };
    }

    private function errnoName(int $errno): string
    {
        return match ($errno) {
            \E_ERROR => 'E_ERROR',
            \E_WARNING => 'E_WARNING',
            \E_PARSE => 'E_PARSE',
            \E_NOTICE => 'E_NOTICE',
            \E_CORE_ERROR => 'E_CORE_ERROR',
            \E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            \E_USER_ERROR => 'E_USER_ERROR',
            \E_USER_WARNING => 'E_USER_WARNING',
            \E_USER_NOTICE => 'E_USER_NOTICE',
            \E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            \E_DEPRECATED => 'E_DEPRECATED',
            \E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN',
        };
    }

    /** @return list<array<string, mixed>> args-free frames from the current call site. */
    private function backtrace(): array
    {
        return $this->framesFromTrace(debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 50));
    }

    /**
     * @param list<array<string, mixed>> $trace
     *
     * @return list<array<string, mixed>>
     */
    private function framesFromTrace(array $trace): array
    {
        $frames = [];
        foreach (\array_slice($trace, 0, 50) as $f) {
            $frames[] = [
                'function' => (string) ($f['function'] ?? '<unknown>'),
                'file' => isset($f['file']) ? (string) $f['file'] : null,
                'line' => isset($f['line']) ? (int) $f['line'] : null,
                'class' => isset($f['class']) ? (string) $f['class'] : null,
                // NB: 'args' is intentionally never copied (DEBUG_BACKTRACE_IGNORE_ARGS / args-free).
            ];
        }

        return $frames;
    }

    private function nonBlank(string $value, string $fallback): string
    {
        return '' !== trim($value) ? $value : $fallback;
    }
}
