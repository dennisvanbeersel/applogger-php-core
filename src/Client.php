<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

use ApplicationLogger\Sdk\Context\ContextCollectorInterface;
use ApplicationLogger\Sdk\Transport\TransportInterface;
use Psr\Clock\ClockInterface;

final class Client
{
    private readonly \Closure $sampler;
    private readonly Stats $stats;

    public function __construct(
        private readonly Options $options,
        private readonly TransportInterface $transport,
        private readonly ClockInterface $clock,
        private readonly DataScrubber $scrubber,
        private readonly StackTraceParser $stackParser,
        private readonly ContextCollectorInterface $context,
        ?\Closure $sampler = null,
    ) {
        // Sampler returns a float in [0,1); event is kept when value < sampleRate.
        $this->sampler = $sampler ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
        $this->stats = new Stats();
    }

    public function captureException(\Throwable $e, Scope $scope): void
    {
        try {
            $event = Event::fromThrowable($e, Severity::Error, $this->clock->now(), $this->options->environment, $this->options->release);
        } catch (\Throwable $t) {
            // The event itself could not be built — nothing to send.
            $this->safeLog('AppLogger: captureException event construction failed', $t);

            return;
        }

        // Enrichment is best-effort: a failure here must NOT drop the error report.
        // We still send the already-built event (type/message/file/line) degraded.
        try {
            $event->stackTrace = $this->stackParser->parse($e);
            $event->context = array_merge($this->context->collect(), $event->context);
        } catch (\Throwable $t) {
            $this->safeLog('AppLogger: captureException enrichment failed (sending degraded event)', $t);
        }

        $this->capture($event, $scope);
    }

    public function captureMessage(string $message, Severity $level, Scope $scope): void
    {
        try {
            $event = new Event(
                type: 'message',
                message: $message,
                file: '',
                line: 0,
                level: $level,
                environment: $this->options->environment,
                release: $this->options->release,
                timestamp: $this->clock->now(),
            );
            $event->context = array_merge($this->context->collect(), $event->context);
        } catch (\Throwable $t) {
            $this->safeLog('AppLogger: captureMessage construction failed', $t);

            return;
        }

        $this->capture($event, $scope);
    }

    /**
     * Capture a caller-built Event through the full pinned pipeline
     * (sample → scope → scrub → before_send → re-scrub → transport).
     * Used by the global ErrorHandler for non-OOM errors/exceptions.
     */
    public function captureEvent(Event $event, Scope $scope): void
    {
        $this->capture($event, $scope);
    }

    /**
     * Lean capture for the OOM/fatal shutdown path. Skips the recursive scrubber,
     * scope, sampling, and before_send (all of which may allocate or fail under
     * memory pressure). STILL applies force-redaction (DSN/api_key literals + token
     * patterns) to message/file via scrubText, which is bounded and linear.
     * Total — never throws (Rule #1).
     *
     * Note: the injected DataScrubber is expected to be pre-seeded with the DSN/api_key
     * literals (which init() does via the $literals array), so force-redaction on the
     * fatal path strips them without any additional allocation.
     */
    public function captureFatalEvent(Event $event): void
    {
        try {
            if (!$this->options->enabled || null === $this->options->dsn) {
                return;
            }
            $event->message = $this->scrubber->scrubText($event->message);
            $event->file = $this->scrubber->scrubText($event->file);
            $this->transport->send($event);
        } catch (\Throwable $t) {
            $this->safeLog('AppLogger: captureFatalEvent failed', $t);
        }
    }

    private function capture(Event $event, Scope $scope): void
    {
        try {
            if (!$this->options->enabled || null === $this->options->dsn) {
                ++$this->stats->droppedDisabled;

                return;
            }

            // Sample BEFORE the scrub pass — sampled-out events never leave the process.
            if (($this->sampler)() >= $this->options->sampleRate) {
                ++$this->stats->droppedSampled;

                return;
            }

            // pinned order: sample -> scope -> scrub -> before_send -> re-scrub -> transport
            $scope->applyTo($event);
            $this->scrubEvent($event);

            $beforeSend = $this->options->beforeSend;
            if (null !== $beforeSend) {
                $result = $beforeSend($event);
                if (!$result instanceof Event) {
                    ++$this->stats->droppedBeforeSend;

                    return;
                }
                $event = $result;
                $this->scrubEvent($event); // final pass: before_send output can't reintroduce raw PII
            }

            $this->transport->send($event);
        } catch (\Throwable $t) {
            // Rule #1: never throw into host code.
            $this->safeLog('AppLogger: capture failed', $t);
        }
    }

    private function scrubEvent(Event $event): void
    {
        $event->message = $this->scrubber->scrubText($event->message);
        $event->context = $this->scrubber->scrub($event->context);
        $event->tags = $this->scrubber->scrub($event->tags);
        $event->breadcrumbs = array_map(
            fn (Breadcrumb $b): Breadcrumb => $this->scrubBreadcrumb($b),
            $event->breadcrumbs,
        );
    }

    private function scrubBreadcrumb(Breadcrumb $b): Breadcrumb
    {
        return new Breadcrumb(
            $b->category,
            $this->scrubber->scrubText($b->message),
            $b->level,
            $this->scrubber->scrub($b->data),
            $b->timestamp,
        );
    }

    public function flush(?float $budget = null): bool
    {
        try {
            return $this->transport->flush($budget);
        } catch (\Throwable $t) {
            $this->safeLog('AppLogger: flush failed', $t);

            return false;
        }
    }

    /** @return array<string, int> */
    public function getStats(): array
    {
        return $this->stats->plus($this->transport->getStats())->toArray();
    }

    private function safeLog(string $message, \Throwable $t): void
    {
        try {
            $this->options->logger->warning($message, ['exception' => $t::class]);
        } catch (\Throwable) {
            // Logging must never break the host (Rule #1).
        }
    }
}
