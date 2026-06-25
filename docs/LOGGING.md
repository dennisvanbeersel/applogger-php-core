# Application Logging

This document covers the SDK's built-in log pipeline: how to configure it, how to emit logs via the PSR-3 facade, delivery semantics, and field constraints. For forwarding **infrastructure** logs (nginx, rsyslog, syslog) see [Forwarding logs from nginx / rsyslog / syslog](#forwarding-logs-from-nginx--rsyslog--syslog) below.

---

## Separate credentials from error tracking

The log pipeline uses a **dedicated** endpoint and token that are independent of your error-tracking DSN.

| Concern | Config key | Example value |
|---------|-----------|---------------|
| Log host | `log_endpoint` | `https://{slug}.logs.applogger.eu` |
| Log auth token | `log_token` | `sk_log_…` |
| Error tracking | `dsn` | `https://ingest.applogger.eu/your-project-id` |

Never pass a `sk_log_` token as the DSN, and never use the error DSN as a log token — they are routed to different collectors.

---

## Configuration

Pass `log_endpoint`, `log_token`, and optionally `app_name` to `init()` alongside your error-tracking options:

```php
use function ApplicationLogger\Sdk\init;

init([
    // Error tracking (optional — can use logging standalone).
    // The DSN is scheme://host/project-id — it carries NO "user@" credential
    // (a DSN with userinfo is rejected and silently disables error tracking).
    'dsn' => 'https://ingest.applogger.eu/your-project-id',

    // Log pipeline
    'log_endpoint' => 'https://{slug}.logs.applogger.eu',
    'log_token'    => 'sk_log_your_token_here',
    'app_name'     => 'my-service',      // optional; identifies the source service
    'environment'  => 'production',      // optional; forwarded with every log entry
]);
```

`init()` only activates the log pipeline when **both** `log_endpoint` and `log_token` are non-empty strings. Omitting either disables logging silently — no error is raised.

---

## Usage via the PSR-3 facade

```php
use function ApplicationLogger\Sdk\logger;

// All 8 PSR-3 levels are available:
logger()->emergency('Disk full on {host}', ['host' => 'web-01']);
logger()->alert('Certificate expires in {days} days', ['days' => 3]);
logger()->critical('Database connection failed');
logger()->error('User {id} failed to authenticate', ['id' => 42]);
logger()->warning('Deprecated endpoint called: {path}', ['path' => '/v1/old']);
logger()->notice('Cache miss on {key}', ['key' => 'user:profile:99']);
logger()->info('Order {id} placed', ['id' => 'ord_abc123']);
logger()->debug('Query took {ms} ms', ['ms' => 14.7]);
```

`logger()` returns a `\Psr\Log\LoggerInterface`. When logging is configured it returns a `Psr3Logger` backed by `LogClient`; when logging is **not** configured (missing endpoint or token) it returns a `\Psr\Log\NullLogger` — all calls are no-ops, nothing throws.

This means you can call `logger()` freely without guarding it:

```php
// Safe even if log_endpoint / log_token were not set in init():
logger()->info('App started');
```

---

## Delivery semantics

### Buffered + flushed on shutdown

Calls to `logger()->*()` buffer log entries in memory. They are **not** sent immediately. When `default_integrations` is enabled (the default), `init()` registers a `register_shutdown_function` that calls `LogClient::flush()` automatically at the end of each PHP request or CLI invocation.

### Explicit flush

For long-running workers, queued jobs, or daemon processes you can flush manually at any point:

```php
use ApplicationLogger\Sdk\Hub;

Hub::getCurrent()?->getLogClient()?->flush();
```

`flush()` drains the buffer in full and returns `true` if every batch was delivered successfully, `false` otherwise. It never throws.

### Batching

`flush()` sends records in chunks of at most **1 000** per HTTP request to `{log_endpoint}/v1/logs/batch`. The request body is JSON: `{"logs": [...]}`.

### Never throws

Both `log()` and `flush()` swallow all internal exceptions. A network failure, serialization error, or misconfiguration will never propagate into your application.

### Circuit breaker and rate limiter

The log pipeline has its **own** `CircuitBreaker` and `RateLimiter` — the same classes as the error transport, but separate instances with log-specific state (so a tripped log breaker never affects error delivery, and vice versa):

- If the collector returns `5xx` errors repeatedly the circuit breaker opens and subsequent batches are dropped (counted in `droppedBreaker`).
- If the collector returns `429 Too Many Requests` the SDK reads the `Retry-After` response header and pauses sending for that duration (counted in `droppedRateLimit`).
- A client-side rate limiter caps egress independently of server-side feedback.

### Accepted status codes

| HTTP status | Meaning |
|-------------|---------|
| `202 Accepted` | Batch queued — counts as delivered |
| `409 Conflict` | Duplicate batch (idempotency key reuse) — counts as delivered |
| `429 Too Many Requests` | Rate limited — retried after `Retry-After` seconds |
| `4xx` (other) | Client error — batch dropped, no retry |
| `5xx` | Server error — breaker records a failure |

---

## Field constraints

Fields are truncated **client-side** before transmission so they are never rejected by the server. The limits below are byte limits (not character limits):

| Field | Cap |
|-------|-----|
| `message` | 65 536 bytes (64 KiB) |
| `app_name` | 48 bytes |
| `environment` | 64 bytes |
| Context key | 256 bytes |
| Context value | 65 536 bytes (64 KiB) |

Levels must be one of the 8 PSR-3 standard levels: `emergency`, `alert`, `critical`, `error`, `warning`, `notice`, `info`, `debug`. Non-standard level strings are normalised to `info`.

---

## Forwarding logs from nginx / rsyslog / syslog

The SDK is for **application-emitted** logs (your PHP code calling `logger()`). For forwarding infrastructure logs from web servers, system daemons, or log shippers to AppLogger, see the platform documentation:

- **[docs/website/papertrail.md](../../../../docs/website/papertrail.md)** — Papertrail / rsyslog / syslog-ng forwarding via syslog-TLS (port 6514).
- **[docs/website/logs.md](../../../../docs/website/logs.md)** — HTTP log ingestion overview and the `/v1/logs` endpoint.
- **[docs/LOG_AGGREGATION_SPEC.md](../../../../docs/LOG_AGGREGATION_SPEC.md)** — Full collector spec (rate limits, payload schema, TTL, ClickHouse storage).

Infrastructure log forwarding uses **the same `sk_log_` token** as the SDK. The transport differs:
- **Syslog-TLS**: send to `{slug}.logs.applogger.eu:6514` with the token in the `STRUCTURED-DATA` field.
- **Direct HTTP**: `POST {log_endpoint}/v1/logs` (single entry) or `/v1/logs/batch` (bulk) with header `X-Api-Key: sk_log_…`.
