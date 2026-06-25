<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

final class Stats
{
    public int $sent = 0;
    public int $droppedSampled = 0;
    public int $droppedBeforeSend = 0;
    public int $droppedDisabled = 0;
    public int $droppedBreaker = 0;
    public int $droppedBudget = 0;
    public int $droppedError = 0;
    public int $droppedRateLimit = 0;
    public int $droppedDedup = 0;
    public int $droppedBuffer = 0;

    public function plus(self $other): self
    {
        $merged = new self();
        $merged->sent = $this->sent + $other->sent;
        $merged->droppedSampled = $this->droppedSampled + $other->droppedSampled;
        $merged->droppedBeforeSend = $this->droppedBeforeSend + $other->droppedBeforeSend;
        $merged->droppedDisabled = $this->droppedDisabled + $other->droppedDisabled;
        $merged->droppedBreaker = $this->droppedBreaker + $other->droppedBreaker;
        $merged->droppedBudget = $this->droppedBudget + $other->droppedBudget;
        $merged->droppedError = $this->droppedError + $other->droppedError;
        $merged->droppedRateLimit = $this->droppedRateLimit + $other->droppedRateLimit;
        $merged->droppedDedup = $this->droppedDedup + $other->droppedDedup;
        $merged->droppedBuffer = $this->droppedBuffer + $other->droppedBuffer;

        return $merged;
    }

    /** @return array{sent: int, dropped_sampled: int, dropped_before_send: int, dropped_disabled: int, dropped_breaker: int, dropped_budget: int, dropped_error: int, dropped_rate_limit: int, dropped_dedup: int, dropped_buffer: int} */
    public function toArray(): array
    {
        return [
            'sent' => $this->sent,
            'dropped_sampled' => $this->droppedSampled,
            'dropped_before_send' => $this->droppedBeforeSend,
            'dropped_disabled' => $this->droppedDisabled,
            'dropped_breaker' => $this->droppedBreaker,
            'dropped_budget' => $this->droppedBudget,
            'dropped_error' => $this->droppedError,
            'dropped_rate_limit' => $this->droppedRateLimit,
            'dropped_dedup' => $this->droppedDedup,
            'dropped_buffer' => $this->droppedBuffer,
        ];
    }
}
