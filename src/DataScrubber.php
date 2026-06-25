<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

/**
 * Shared, stateless utility for GDPR-compliant data scrubbing and IP anonymization.
 *
 * SCRUBBING BOUNDARY (read carefully - this is NOT general leak prevention):
 * Redaction is KEY-NAME based. {@see scrub()} only redacts a value when its KEY
 * (case-insensitively) contains a configured scrub fragment; values stored under
 * non-matching keys are passed through UNINSPECTED.
 *
 * {@see scrubText()} performs value-level redaction of known token-prefix patterns
 * and configured literal strings (e.g. the DSN). It is bounded to strings ≤ 8192
 * chars to avoid ReDoS/CPU exhaustion on large bodies. This is NOT general secret
 * detection — it only catches well-known prefixed tokens.
 *
 * RESILIENCE: Every public method is total - it never throws. On any internal
 * failure it returns a safe default (redacted/empty/null) rather than leaking data
 * or crashing the host application.
 */
final readonly class DataScrubber
{
    /**
     * Lowercased, de-duplicated, non-empty scrub fragments, precomputed once at
     * construction.
     *
     * @var list<string>
     */
    private array $normalizedScrubFields;

    /**
     * Exact literal strings to redact verbatim (e.g. the configured DSN).
     *
     * @var list<string>
     */
    private array $literals;

    /**
     * Token-prefix patterns for value-level scrubbing in {@see scrubText()}.
     * All patterns are linear/non-backtracking to avoid ReDoS.
     *
     * @var list<non-empty-string>
     */
    private const TOKEN_PATTERNS = [
        '/\bsk_log_[A-Za-z0-9]+/',
        '/\bpk_[A-Za-z0-9]+/',
        '/\bBearer\s+[A-Za-z0-9._\-]+/i',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\bghp_[A-Za-z0-9]+/',
        // Match the WHOLE PEM block (header, body, footer). The secret is the body, so a
        // header-only match would leave it intact. Lazy + literal terminator keeps this
        // linear; the ≤8192-char bound in scrubText() caps any backtracking cost.
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
    ];

    /**
     * @param list<string> $scrubFields field-name fragments to redact (case-insensitive substring match)
     * @param list<string> $literals exact strings to redact verbatim (e.g. the configured DSN)
     */
    public function __construct(array $scrubFields, array $literals = [])
    {
        $normalized = [];
        foreach ($scrubFields as $field) {
            if ('' === $field) {
                continue;
            }
            $lower = strtolower($field);
            $normalized[$lower] = true;
        }
        $this->normalizedScrubFields = array_keys($normalized);

        $this->literals = array_values(array_filter($literals, static fn (string $l): bool => '' !== $l));
    }

    /**
     * Recursively scrub sensitive values from an array.
     *
     * A key is redacted when its name contains (case-insensitively) any configured
     * scrub fragment. Nested arrays are scrubbed recursively, depth-limited to
     * avoid pathological / cyclic structures exhausting the stack.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public function scrub(array $data, int $maxDepth = 16): array
    {
        try {
            return $this->scrubInternal($data, $maxDepth);
        } catch (\Throwable) {
            // Fail safe: an empty array can never leak sensitive data.
            return [];
        }
    }

    /**
     * Redact sensitive VALUES from a raw query string.
     *
     * Returns the input unchanged for null; "" for empty. Never throws.
     */
    public function scrubQueryString(?string $qs): ?string
    {
        if (null === $qs) {
            return null;
        }
        if ('' === $qs) {
            return '';
        }

        try {
            $pairs = explode('&', $qs);
            foreach ($pairs as $i => $pair) {
                if ('' === $pair) {
                    continue;
                }

                $eqPos = strpos($pair, '=');
                if (false === $eqPos) {
                    // Bare key with no value (e.g. "?debug"); redact if sensitive.
                    if ($this->keyIsSensitive(rawurldecode($pair))) {
                        $pairs[$i] = $pair.'=[REDACTED]';
                    }
                    continue;
                }

                $rawName = substr($pair, 0, $eqPos);
                if ($this->keyIsSensitive(rawurldecode($rawName))) {
                    $pairs[$i] = $rawName.'=[REDACTED]';
                }
            }

            return implode('&', $pairs);
        } catch (\Throwable) {
            // Fail safe: redacting the whole query can never leak a sensitive value.
            return '[REDACTED]';
        }
    }

    /**
     * Redact sensitive VALUES from the query string of a URL, and ALWAYS redact
     * any embedded userinfo (credentials) in the authority component.
     *
     * Returns the input unchanged for null; "" for empty. Never throws.
     */
    public function scrubUrl(?string $url): ?string
    {
        if (null === $url) {
            return null;
        }
        if ('' === $url) {
            return '';
        }

        try {
            // Redact embedded credentials (userinfo) FIRST so the rest of the
            // method operates on (and returns) a credential-free URL.
            $url = $this->scrubUrlUserinfo($url);

            $query = parse_url($url, \PHP_URL_QUERY);
            if (false === $query) {
                // Malformed URL — parse_url could not analyse it. Fail closed rather
                // than echo back a string that may carry a secret in a query-like tail.
                return '[REDACTED]';
            }
            if (!\is_string($query) || '' === $query) {
                // Valid URL with no query component — nothing to scrub.
                return $url;
            }

            $scrubbed = $this->scrubQueryString($query);
            if (null === $scrubbed || $scrubbed === $query) {
                return $url;
            }

            // Replace only the query segment, leaving any fragment intact.
            $hashPos = strpos($url, '#');
            $fragment = false !== $hashPos ? substr($url, $hashPos) : '';
            $beforeFragment = false !== $hashPos ? substr($url, 0, $hashPos) : $url;

            $qPos = strpos($beforeFragment, '?');
            if (false === $qPos) {
                return $url;
            }

            return substr($beforeFragment, 0, $qPos + 1).$scrubbed.$fragment;
        } catch (\Throwable) {
            // Fail safe: do not echo back a URL that may carry a sensitive value.
            return '[REDACTED]';
        }
    }

    /**
     * Anonymize an IP address for GDPR data-minimisation.
     *
     * IPv4: mask the last octet (192.168.1.100 -> 192.168.1.0).
     * IPv6: keep the first 48 bits, zero the remaining 80 bits.
     * Returns null on null input or any failure (never the raw IP on error).
     */
    public function anonymizeIp(?string $ip): ?string
    {
        if (null === $ip || '' === $ip) {
            return null;
        }

        try {
            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                $parts = explode('.', $ip);
                $parts[3] = '0';

                return implode('.', $parts);
            }

            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                $addr = inet_pton($ip);
                if (false !== $addr) {
                    $addr = substr($addr, 0, 6).str_repeat("\0", 10);
                    $anonymized = inet_ntop($addr);

                    return false !== $anonymized ? $anonymized : null;
                }
            }

            // Unrecognised format - do not echo it back, treat as unknown.
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Redact known token-prefix patterns and configured literal strings from a text value.
     *
     * Bounded to strings ≤ 8192 chars to avoid ReDoS/CPU exhaustion on large bodies;
     * longer strings are returned unchanged (not a leak path — large bodies are capped
     * elsewhere). This is known-prefix detection, NOT general secret detection.
     *
     * Never throws.
     */
    public function scrubText(string $text): string
    {
        try {
            if ('' === $text || \strlen($text) > 8192) {
                return $text; // length-bounded: avoid scanning large bodies (ReDoS/CPU)
            }
            foreach ($this->literals as $literal) {
                $text = str_replace($literal, '[REDACTED]', $text);
            }
            $result = preg_replace(self::TOKEN_PATTERNS, '[REDACTED]', $text);

            return \is_string($result) ? $result : $text;
        } catch (\Throwable) {
            return $text;
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function scrubInternal(array $data, int $depth): array
    {
        if ($depth <= 0) {
            return [];
        }

        $scrubbed = [];
        foreach ($data as $key => $value) {
            if ($this->keyIsSensitive((string) $key)) {
                $scrubbed[$key] = '[REDACTED]';
                continue;
            }

            $scrubbed[$key] = \is_array($value)
                ? $this->scrubInternal($value, $depth - 1)
                : $value;
        }

        return $scrubbed;
    }

    private function keyIsSensitive(string $key): bool
    {
        $lowerKey = strtolower($key);
        foreach ($this->normalizedScrubFields as $field) {
            if (str_contains($lowerKey, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace any "//user:pass@host" / "//user@host" userinfo in a URL's authority
     * with "//[REDACTED]@host".
     */
    private function scrubUrlUserinfo(string $url): string
    {
        $schemePos = strpos($url, '://');
        if (false !== $schemePos) {
            $authorityStart = $schemePos + \strlen('://');
        } elseif (str_starts_with($url, '//')) {
            $authorityStart = \strlen('//');
        } else {
            return $url;
        }

        // The authority ends at the first '/', '?' or '#' after "scheme://".
        $authorityEnd = \strlen($url);
        foreach (['/', '?', '#'] as $delimiter) {
            $pos = strpos($url, $delimiter, $authorityStart);
            if (false !== $pos && $pos < $authorityEnd) {
                $authorityEnd = $pos;
            }
        }

        $authority = substr($url, $authorityStart, $authorityEnd - $authorityStart);
        $atPos = strrpos($authority, '@');
        if (false === $atPos) {
            return $url;
        }

        $hostPart = substr($authority, $atPos + 1);

        return substr($url, 0, $authorityStart).'[REDACTED]@'.$hostPart.substr($url, $authorityEnd);
    }
}
