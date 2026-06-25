<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

final class StackTraceParser
{
    private const MAX_FRAMES = 100;

    /**
     * @return list<array{file: string, line: int, function: string, class: string, type: string, in_app: bool}>
     */
    public function parse(\Throwable $e): array
    {
        try {
            $frames = [];
            // getTrace() omits call arguments since PHP 7.4 — never capture args.
            foreach (\array_slice($e->getTrace(), 0, self::MAX_FRAMES) as $frame) {
                $file = \is_string($frame['file'] ?? null) ? $frame['file'] : '';
                $frames[] = [
                    'file' => $file,
                    'line' => (int) ($frame['line'] ?? 0),
                    'function' => \is_string($frame['function'] ?? null) ? $frame['function'] : '',
                    'class' => \is_string($frame['class'] ?? null) ? $frame['class'] : '',
                    'type' => \is_string($frame['type'] ?? null) ? $frame['type'] : '',
                    'in_app' => '' !== $file && !str_contains($file, '/vendor/'),
                ];
            }

            return $frames;
        } catch (\Throwable) {
            return [];
        }
    }
}
