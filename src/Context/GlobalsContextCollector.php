<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Context;

use ApplicationLogger\Sdk\DataScrubber;

final class GlobalsContextCollector implements ContextCollectorInterface
{
    // Cookie/Authorization/PHP_AUTH_*/HTTP_REFERER and $_ENV are intentionally never read.
    private const SERVER_ALLOWLIST = [
        'REQUEST_URI', 'REQUEST_METHOD', 'SERVER_NAME', 'HTTP_HOST', 'HTTP_USER_AGENT',
    ];
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /** @var array<string, mixed> */
    private readonly array $server;

    /** @param array<string, mixed>|null $server */
    public function __construct(
        private readonly DataScrubber $scrubber,
        private readonly string $sessionHashSalt,
        ?array $server = null,
    ) {
        $this->server = $server ?? $_SERVER;
    }

    /** @return array<string, mixed> */
    public function collect(): array
    {
        try {
            $ctx = ['runtime' => 'PHP '.\PHP_VERSION];

            $uri = $this->str('REQUEST_URI');
            if (null !== $uri) {
                $ctx['url'] = $this->scrubber->scrubUrl($uri);
            }
            $method = $this->str('REQUEST_METHOD');
            if (null !== $method && \in_array(strtoupper($method), self::HTTP_METHODS, true)) {
                $ctx['http_method'] = strtoupper($method);
            }
            $ip = $this->clientIp();
            if (null !== $ip) {
                $ctx['ip_address'] = $this->scrubber->anonymizeIp($ip);
            }
            $hash = $this->sessionHash();
            if (null !== $hash) {
                $ctx['session_hash'] = $hash;
            }

            $server = [];
            foreach (self::SERVER_ALLOWLIST as $key) {
                $v = $this->str($key);
                if (null === $v) {
                    continue;
                }
                // REQUEST_URI can carry secrets in its query string (?token=…); it must be
                // scrubbed with the same treatment as ctx['url'] before entering ctx['server'].
                $server[$key] = 'REQUEST_URI' === $key ? $this->scrubber->scrubUrl($v) : $v;
            }
            if ([] !== $server) {
                $ctx['server'] = $server;
            }

            return $ctx;
        } catch (\Throwable) {
            return [];
        }
    }

    private function clientIp(): ?string
    {
        $xff = $this->str('HTTP_X_FORWARDED_FOR');
        if (null !== $xff) {
            $first = trim(explode(',', $xff)[0]);
            if ('' !== $first) {
                return $first;
            }
        }
        $real = $this->str('HTTP_X_REAL_IP');
        if (null !== $real) {
            return $real;
        }

        return $this->str('REMOTE_ADDR');
    }

    private function sessionHash(): ?string
    {
        if (\PHP_SESSION_ACTIVE !== session_status()) {
            return null;
        }
        $id = session_id();
        if (!\is_string($id) || '' === $id) {
            return null;
        }

        return hash_hmac('sha256', $id, $this->sessionHashSalt);
    }

    private function str(string $key): ?string
    {
        $v = $this->server[$key] ?? null;

        return \is_string($v) && '' !== $v ? $v : null;
    }
}
