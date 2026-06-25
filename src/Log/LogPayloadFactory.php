<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Log;

/**
 * Serializes a {@see LogRecord} into the collector's `LogEntry` wire array, applying
 * the server's byte caps client-side (fields are truncated, never rejected). Total.
 */
final class LogPayloadFactory
{
    private const MESSAGE_CAP = 65536;
    private const APP_NAME_CAP = 48;
    private const ENVIRONMENT_CAP = 64;
    private const CONTEXT_KEY_CAP = 256;
    private const CONTEXT_VALUE_CAP = 65536;

    /** @return array<string, mixed> */
    public function toWire(LogRecord $record): array
    {
        try {
            $wire = [
                'severity' => LogLevel::normalize($record->level),
                'message' => self::cap($record->message, self::MESSAGE_CAP),
                'timestamp' => $record->timestamp->format(\DATE_RFC3339),
            ];

            if (null !== $record->appName && '' !== $record->appName) {
                $wire['app_name'] = self::cap($record->appName, self::APP_NAME_CAP);
            }
            if (null !== $record->environment && '' !== $record->environment) {
                $wire['environment'] = self::cap($record->environment, self::ENVIRONMENT_CAP);
            }

            $context = [];
            foreach ($record->context as $key => $value) {
                $context[self::cap((string) $key, self::CONTEXT_KEY_CAP)] = self::cap(self::stringify($value), self::CONTEXT_VALUE_CAP);
            }
            if ([] !== $context) {
                $wire['context'] = $context;
            }

            return $wire;
        } catch (\Throwable) {
            // Fail safe: a minimal valid entry rather than throwing into the caller.
            return ['severity' => LogLevel::INFO, 'message' => '<unserializable log entry>'];
        }
    }

    /** Byte-bounded truncation (server caps are byte limits). */
    private static function cap(string $value, int $maxBytes): string
    {
        if (\strlen($value) <= $maxBytes) {
            return $value;
        }

        $value = substr($value, 0, $maxBytes);
        // Back off any partial trailing multibyte sequence so the result is valid UTF-8
        // (json_encode with JSON_THROW_ON_ERROR rejects invalid UTF-8 and would fail the whole batch).
        while ('' !== $value && 1 !== preg_match('//u', $value)) {
            $value = substr($value, 0, -1);
        }

        return $value;
    }

    private static function stringify(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value instanceof \Throwable) {
            return $value::class.': '.$value->getMessage();
        }
        if (\is_array($value) || \is_object($value)) {
            try {
                $json = json_encode($value, \JSON_THROW_ON_ERROR);

                return \is_string($json) ? $json : get_debug_type($value);
            } catch (\Throwable) {
                return get_debug_type($value);
            }
        }

        return null === $value ? '' : (string) $value;
    }
}
