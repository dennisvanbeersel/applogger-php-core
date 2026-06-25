<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

final class ErrorPayloadFactory
{
    private const MAX_FRAMES = 250;
    private const MAX_BREADCRUMBS = 50;

    /**
     * @return array<string, mixed>
     */
    public function fromEvent(Event $event): array
    {
        $payload = [
            'type' => $this->nonBlank($this->truncate($event->type, 255), '<unknown>'),
            'message' => $this->nonBlank($this->truncate($event->message, 1000), '<no message>'),
            'file' => $this->nonBlank($this->truncate($event->file, 500), '<unknown>'),
            'line' => max(1, $event->line),
            'level' => $event->level->toServerLevel(),
            'source' => 'backend',
            'environment' => $this->truncate($event->environment, 100),
            'timestamp' => $event->timestamp->format(\DATE_ATOM),
            'tags' => $this->stringMap($event->tags),
            'context' => $event->context,
            'stack_trace' => \array_slice($event->stackTrace, 0, self::MAX_FRAMES),
            'breadcrumbs' => array_map(
                static fn (Breadcrumb $b): array => $b->toArray(),
                \array_slice($event->breadcrumbs, 0, self::MAX_BREADCRUMBS),
            ),
        ];

        if (null !== $event->release) {
            $payload['release'] = $this->truncate($event->release, 255);
        }

        $ctx = $event->context;
        if (isset($ctx['url']) && \is_string($ctx['url'])) {
            $payload['url'] = mb_substr($ctx['url'], 0, 2000);
        }
        if (isset($ctx['http_method']) && \in_array($ctx['http_method'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            $payload['http_method'] = $ctx['http_method'];
        }
        if (isset($ctx['ip_address']) && \is_string($ctx['ip_address']) && false !== filter_var($ctx['ip_address'], \FILTER_VALIDATE_IP)) {
            $payload['ip_address'] = $ctx['ip_address'];
        }
        if (isset($ctx['session_hash']) && \is_string($ctx['session_hash']) && 1 === preg_match('/^[a-f0-9]{64}$/', $ctx['session_hash'])) {
            $payload['session_hash'] = $ctx['session_hash'];
        }
        if (isset($ctx['server']['SERVER_NAME']) && \is_string($ctx['server']['SERVER_NAME'])) {
            $payload['server_name'] = mb_substr($ctx['server']['SERVER_NAME'], 0, 100);
        }
        if (isset($ctx['runtime']) && \is_string($ctx['runtime'])) {
            $payload['runtime'] = mb_substr($ctx['runtime'], 0, 100);
        }

        return $payload;
    }

    private function nonBlank(string $value, string $fallback): string
    {
        return '' === trim($value) ? $fallback : $value;
    }

    private function truncate(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }

    /**
     * @param array<string, scalar|null> $tags
     *
     * @return array<string, string>
     */
    private function stringMap(array $tags): array
    {
        $out = [];
        foreach ($tags as $key => $value) {
            $out[$key] = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $out;
    }
}
