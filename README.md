# applogger/sdk-core

Framework-agnostic PHP error-tracking and log aggregation SDK for [AppLogger](https://applogger.eu) — the EU-hosted, privacy-first application monitoring platform. This package is the core that framework adapters (e.g. `applogger/symfony-bundle`) build on. It speaks PSR-3 (log interface), PSR-18 (HTTP client), and PSR-17 (HTTP factories), and is designed to never throw into the host application under any circumstances.

![PHP ^8.3](https://img.shields.io/badge/PHP-%5E8.3-blue) ![License MIT](https://img.shields.io/badge/License-MIT-green)

---

## Features

- **Exception and message capture** with automatic fingerprinting and deduplication (`captureException`, `captureMessage`, `captureEvent`).
- **Structured log aggregation** via a PSR-3 `LoggerInterface` returned by `logger()` — ships log records to AppLogger's ClickHouse-backed log pipeline independently of the error pipeline.
- **Automatic global error and fatal/OOM handler** — opt-in via `default_integrations` (default: enabled). Registers a shutdown handler and uses `MemoryReservation` to capture out-of-memory fatals.
- **Worker / FastCGI-aware delivery** — `DeliveryMode` auto-detects FrankenPHP, Swoole, RoadRunner, FastCGI and standard CLI; chooses the correct post-response or shutdown flush strategy.
- **Breadcrumbs and scope** — attach tags, user context, and extra data via `configureScope`/`withScope`; add breadcrumbs with `addBreadcrumb`.
- **GDPR-compliant data scrubbing** (`DataScrubber`) — key-name-based field redaction, known-prefix token redaction (`Bearer`, `sk_log_`, `pk_`, `AKIA*`, `ghp_`, PEM private keys), URL/query-string scrubbing, embedded-credential stripping, and IPv4/IPv6 anonymization.
- **Proxy-aware context collection** — request context collected from PHP globals with sensitivity scrubbing applied before capture.
- **Circuit breaker + rate limiter + bounded buffering** — filesystem-backed state; SDK backs off automatically when the ingest endpoint is unreachable; events beyond `max_buffered_events` are discarded without blocking.
- **`before_send` hook** — inspect and mutate or discard any `Event` before it is sent.
- **`diagnose()` self-check** — programmatic diagnostics returning a `DiagnosticReport`; also exposed as the `vendor/bin/applogger` CLI.
- **Resilient by design** — every public method is *total*: it never propagates exceptions into the host. Rule #1 is inviolable.

---

## Requirements

- PHP **^8.3**
- A discoverable **PSR-18 HTTP client** (e.g. `symfony/http-client`, `guzzlehttp/guzzle` with `php-http/guzzle7-adapter`).
  - `nyholm/psr7` (PSR-17 request/response factories) ships as a **direct dependency** and is always available.
  - If **no PSR-18 client is discoverable** at runtime, the SDK silently degrades to a `NullTransport` — telemetry is disabled but the host application continues unaffected. Install a PSR-18 client to actually send events.
- `ext-json`, `ext-mbstring`

---

## Installation

```bash
composer require applogger/sdk-core
```

> **Stability note**: until a stable tagged release is published this package tracks `dev-master`. Consumers that have `"minimum-stability": "stable"` (the Composer default) must add:
> ```json
> "minimum-stability": "dev",
> "prefer-stable": true
> ```
> Framework adapters that already require the bundle (e.g. `applogger/symfony-bundle`) typically pin a compatible stability themselves.

---

## Quick Start

### Global function API

The global functions in `src/functions.php` are auto-loaded by Composer and provide the fastest integration path for plain PHP applications.

```php
use function ApplicationLogger\Sdk\{init, captureException, captureMessage, addBreadcrumb, configureScope, withScope, flush, logger};
use ApplicationLogger\Sdk\{Breadcrumb, Severity};

// 1. Initialise once (typically in your bootstrap / front-controller).
init([
    'dsn'         => 'https://your-ingest-host.example.com/your-project-id',
    'api_key'     => 'your-api-key',
    'environment' => 'production',
    'release'     => '1.4.2',
]);

// 2. Capture exceptions.
try {
    riskyOperation();
} catch (\Throwable $e) {
    captureException($e);
}

// 3. Capture a plain message.
captureMessage('Checkout completed', Severity::Info);

// 4. Add a breadcrumb.
addBreadcrumb(new Breadcrumb(
    category: 'db',
    message:  'User record loaded',
    level:    Severity::Debug,
    data:     ['user_id' => 42],
    timestamp: new \DateTimeImmutable(),
));

// 5. Enrich the current scope (tags, user, extra).
configureScope(function (\ApplicationLogger\Sdk\Scope $scope): void {
    $scope->setTag('plan', 'premium');
    $scope->setUser(['id' => 42, 'email' => 'alice@example.com']);
    $scope->setExtra('memory_mb', memory_get_usage(true) / 1048576);
});

// 6. Use an isolated scope for one block (does not affect the global scope).
withScope(function (\ApplicationLogger\Sdk\Scope $scope): void {
    $scope->setTag('component', 'payment');
    captureMessage('Payment gateway timeout', Severity::Warning);
});

// 7. PSR-3 logger for structured log aggregation (requires log_endpoint + log_token).
$log = logger();
$log->info('Order {id} placed', ['id' => 99]);
$log->error('Unexpected response', ['status' => 503]);

// 8. Flush pending events (useful in CLI scripts or before process termination).
flush(budget: 2.0); // optional wall-clock budget in seconds
```

### Hub — DI / framework-adapter entry point

Framework adapters use `Hub` directly instead of the global functions, so they can wire the hub into the DI container.

```php
use ApplicationLogger\Sdk\Hub;

// After init() (or after a framework adapter has constructed its own Hub):
$hub = Hub::getCurrent();

$hub->captureException($throwable);
$hub->captureMessage('Something happened', \ApplicationLogger\Sdk\Severity::Warning);
$hub->configureScope(function (\ApplicationLogger\Sdk\Scope $scope): void {
    $scope->setTag('tenant', 'acme');
});
$hub->withScope(function (\ApplicationLogger\Sdk\Scope $scope): void {
    // isolated scope
});
```

The Symfony bundle (`applogger/symfony-bundle`) constructs a `Hub` via its DI container and never calls the global `init()`; it uses `Hub::setCurrent()` so the global functions still delegate to the same instance.

---

## DSN Format

```
scheme://host[:port]/projectId
```

Examples:

```
https://ingest.applogger.eu/01J9X3K5QV8N2P7G4HZYWEM6BF
https://localhost:8080/my-project
```

**Important**: the DSN must NOT contain userinfo (`user@` or `user:pass@`). A DSN with a `@` in the authority is explicitly rejected by `Dsn::tryParse()` and resolves to `null`, which causes the SDK to use a `NullTransport` (no events sent, no error thrown).

Authentication is passed via the `api_key` option, which the SDK sends as the `X-Application-Logger-Api-Key` request header. The `dsn` option carries only the routing information (host + project ID).

---

## Configuration Reference

All options are passed as a flat array to `init()` (or `Options::fromArray()`). Unknown keys are warned via the configured `logger` and silently ignored.

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `dsn` | `string` | `null` | Ingest DSN (`scheme://host/projectId`, no userinfo). Required to send error events. |
| `api_key` | `string` | `null` | API key sent as `X-Application-Logger-Api-Key`. Required by the platform. |
| `environment` | `string` | `'production'` | Environment label attached to every event (`production`, `staging`, etc.). |
| `release` | `string\|null` | `null` | Application version / release identifier (e.g. `1.4.2`, git SHA). |
| `enabled` | `bool` | `true` | Master switch. When `false`, all captures are no-ops. |
| `sample_rate` | `float` | `1.0` | Fraction of events to actually send (0.0–1.0). `1.0` = send all. |
| `max_breadcrumbs` | `int` | `50` | Maximum number of breadcrumbs retained per scope. Minimum 1. |
| `logger` | `LoggerInterface` | `NullLogger` | Internal SDK logger for warnings/debug output (not your application logger). |
| `before_send` | `\Closure` | `null` | Called with `(Event $event): ?Event` before each send. Return `null` to drop the event. |
| `circuit_breaker` | `array` | see below | Circuit breaker configuration sub-array. |
| `circuit_breaker.failure_threshold` | `int` | `5` | Number of consecutive failures before the circuit opens. |
| `circuit_breaker.timeout` | `int` | `60` | Seconds the circuit stays open before attempting half-open. Minimum 10. |
| `circuit_breaker.half_open_attempts` | `int` | `1` | Test requests allowed while half-open. |
| `max_buffered_events` | `int` | `100` | Maximum events buffered in memory before older ones are discarded. |
| `flush_budget` | `float` | `2.0` | Wall-clock cap (seconds) on the post-response/shutdown telemetry drain. Clamped 0.05–5.0. |
| `timeout` | `float` | `2.0` | Per-request HTTP timeout in seconds. Clamped 0.5–5.0. |
| `cache_dir` | `string` | `sys_get_temp_dir().'/applogger-sdk'` | Filesystem directory for circuit-breaker and rate-limiter state. |
| `session_hash_salt` | `string` | derived from DSN | Salt used when hashing session identifiers. |
| `scrub_fields` | `string[]` | `['password','passwd','secret','token','api_key','auth','credential']` | Field-name fragments (case-insensitive substring) whose values are replaced with `[REDACTED]`. |
| `flush_mode` | `string` | `'auto'` | Delivery mode: `'auto'` (detect), `'web'` (FastCGI), `'worker'` (FrankenPHP/Swoole/RoadRunner), `'cli'`. |
| `default_integrations` | `bool` | `true` | Register the global error/exception/shutdown handler (`ErrorHandler`) automatically. |
| `log_endpoint` | `string\|null` | `null` | Log aggregation endpoint (`https://…`). Both `log_endpoint` and `log_token` must be set to enable logging. |
| `log_token` | `string\|null` | `null` | Log API token (typically `sk_log_…`). |
| `app_name` | `string\|null` | `null` | Application name attached to log records. |

---

## Log Aggregation

The SDK ships two independent pipelines through a single `init()` call:

1. **Error tracking** — `captureException` / `captureMessage` → `POST {dsn-host}/api/v1/errors` (PostgreSQL, fingerprinted + grouped). Auth: `X-Application-Logger-Api-Key`.
2. **Log aggregation** — `logger()` PSR-3 bridge → buffered → `POST {log_endpoint}/v1/logs/batch` (ClickHouse). Auth: `X-Api-Key` header with `log_token`.

Logging is **disabled** when either `log_endpoint` or `log_token` is absent — `logger()` returns a `NullLogger` and no network calls are made.

```php
init([
    'dsn'          => 'https://ingest.applogger.eu/my-project',
    'api_key'      => 'my-api-key',
    'log_endpoint' => 'https://logs.applogger.eu',
    'log_token'    => 'sk_log_xxxxx',
    'app_name'     => 'my-app',
    'environment'  => 'production',
]);

$log = logger(); // PSR-3 LoggerInterface
$log->info('User signed in', ['user_id' => 123]);
$log->critical('Payment processor unreachable');
```

Log records are flushed automatically on shutdown when `default_integrations` is enabled.

---

## Resilience and Design Guarantees

- **Rule #1 — never throws into the host.** Every public surface is wrapped in `try/catch (\Throwable)`. On any internal failure the SDK logs to its internal logger (if configured) and returns a safe default.
- **Circuit breaker** — after `failure_threshold` consecutive transport failures the circuit opens for `timeout` seconds, skipping all outbound calls. It self-resets via the half-open probe.
- **Rate limiter + dedup** — bounded event volume; repeated identical events within a window are counted but not re-sent.
- **Bounded buffers** — `max_buffered_events` caps in-memory storage; oldest events are dropped when the buffer is full.
- **`before_send` hook** — return `null` from the closure to drop an event entirely. This is the extension point for server-side filtering (e.g. ignore 404s, drop health-check noise).
- **Data scrubbing defaults** — sensitive field names (`password`, `passwd`, `secret`, `token`, `api_key`, `auth`, `credential`) are redacted recursively in all captured context. URL userinfo and known token prefixes are scrubbed from text values. IP addresses are anonymized (IPv4: last octet zeroed; IPv6: last 80 bits zeroed).
- **Delivery mode awareness** — `DeliveryMode::AUTO` detects FrankenPHP (`frankenphp_handle_request`), Swoole (`\Swoole\Coroutine`), RoadRunner (`RR_MODE` env), and FastCGI (`fastcgi_finish_request`). Worker mode registers a shutdown flush; web mode relies on the framework's terminate event (e.g. `kernel.terminate` in Symfony) to drain after the response is sent.

---

## CLI / Diagnostics

`vendor/bin/applogger` is a zero-dependency CLI that runs a self-check against an SDK configuration.

```bash
# Human-readable output
vendor/bin/applogger diagnose \
  --dsn=https://ingest.applogger.eu/my-project \
  --log-endpoint=https://logs.applogger.eu \
  --log-token=sk_log_xxxxx

# Machine-readable JSON (exit 0 = healthy, 1 = a check failed)
vendor/bin/applogger diagnose --dsn=https://… --json

# Skip sending a real test event
vendor/bin/applogger diagnose --dsn=https://… --no-send

# Options can also be supplied via environment variables:
# APPLOGGER_DSN, APPLOGGER_LOG_ENDPOINT, APPLOGGER_LOG_TOKEN,
# APPLOGGER_APP_NAME, APPLOGGER_CACHE_DIR
```

The CLI runs five checks and prints a `[OK]` / `[WARN]` / `[FAIL]` / `[INFO]` line for each:

| Check | What it verifies |
|-------|-----------------|
| **DSN** | DSN parses to a valid `scheme://host/projectId` (no userinfo). |
| **Transport** | A PSR-18 HTTP client is discoverable; reports `NullTransport` degradation if not. |
| **Cache** | The `cache_dir` is writable (circuit-breaker/rate-limit state persistence). |
| **Logging** | `log_endpoint` + `log_token` are set and a log client can be constructed. |
| **Test event** | Sends a real `Diagnostic` event to the configured DSN and expects HTTP 202 or 409. |

The same checks are available programmatically:

```php
use function ApplicationLogger\Sdk\diagnose;

$report = diagnose([
    'dsn'     => 'https://ingest.applogger.eu/my-project',
    'api_key' => 'my-api-key',
], sendTestEvent: true);

if (!$report->isHealthy()) {
    foreach ($report->checks as $check) {
        if ($check->status === 'fail') {
            echo $check->name.': '.$check->detail.PHP_EOL;
        }
    }
}
```

---

## Versioning

This package follows **Semantic Versioning** once a stable tag is published. It is currently pre-release (`dev-master` / `1.0-dev`). Breaking changes within the 1.x series will not be made without a major-version bump.

---

## License

MIT — see [LICENSE](LICENSE).

---

## Links

- Homepage: https://applogger.eu
- Getting-started guide: https://app.applogger.eu/docs/core-sdk
- Source / issues: https://github.com/dennisvanbeersel/applogger-php-core
- Symfony bundle: https://github.com/dennisvanbeersel/symfony-logger-client
