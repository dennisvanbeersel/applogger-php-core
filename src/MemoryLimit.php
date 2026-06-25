<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

/**
 * Stateless helpers for the PHP `memory_limit` ini directive. Used by the OOM
 * shutdown path to win enough headroom to build and send the fatal event.
 */
final class MemoryLimit
{
    /**
     * Parse a memory_limit string to bytes. Returns null for unlimited (`-1`),
     * empty, or unparseable input.
     */
    public static function parse(string $raw): ?int
    {
        $value = trim($raw);
        if ('' === $value || '-1' === $value) {
            return null;
        }

        if (!preg_match('/^(\d+)\s*([KMG])?$/i', $value, $matches)) {
            return null;
        }

        $bytes = (int) $matches[1];
        $suffix = isset($matches[2]) ? strtoupper($matches[2]) : '';

        return match ($suffix) {
            'K' => $bytes * 1024,
            'M' => $bytes * 1024 * 1024,
            'G' => $bytes * 1024 * 1024 * 1024,
            default => $bytes,
        };
    }

    /**
     * Raise the current memory_limit by $extraBytes once. No-op when the limit is
     * unlimited or cannot be read/parsed. Never throws.
     */
    public static function bump(int $extraBytes): void
    {
        try {
            $current = self::parse((string) \ini_get('memory_limit'));
            if (null === $current || $extraBytes <= 0) {
                return;
            }
            ini_set('memory_limit', (string) ($current + $extraBytes));
        } catch (\Throwable) {
            // Rule #1: degrade silently.
        }
    }
}
