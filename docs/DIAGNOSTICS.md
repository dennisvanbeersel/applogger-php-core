# AppLogger SDK Diagnostics

The SDK ships a first-class diagnostics tool that validates your configuration, checks transport and cache availability, verifies the log pipeline, and (optionally) fires a real test event so you can confirm end-to-end connectivity before going to production.

---

## PHP facade

```php
use function ApplicationLogger\Sdk\diagnose;

$report = diagnose(['dsn' => 'https://your-host.applogger.eu/project-id']);

if ($report->isHealthy()) {
    echo "SDK is healthy\n";
} else {
    foreach ($report->checks as $check) {
        printf("[%-4s] %-12s %s\n", strtoupper($check->status), $check->name, $check->detail);
    }
}
```

**Skip the network call** (useful in CI or tests):

```php
$report = diagnose(['dsn' => '...'], sendTestEvent: false);
```

`DiagnosticReport::isHealthy()` returns `false` only when at least one check has status `fail`. `warn` and `info` statuses do not make the report unhealthy.

---

## CLI

```
vendor/bin/applogger diagnose [options]
```

### Options

| Option | Description |
|---|---|
| `--dsn=URL` | Error-tracking DSN (`scheme://host/project-id`) |
| `--log-endpoint=URL` | Log pipeline endpoint (`https://{slug}.logs.applogger.eu`) |
| `--log-token=TOKEN` | Log token (`sk_log_...`) |
| `--app-name=NAME` | Application name tag sent with log events |
| `--cache-dir=PATH` | Cache directory for circuit-breaker/rate-limit state |
| `--no-send` | Skip sending a real test event (no network calls) |
| `--json` | Machine-readable JSON output |
| `-h`, `--help` | Show usage |

### Environment fallbacks

If a CLI option is omitted, the tool reads the corresponding environment variable:

| Option | Environment variable |
|---|---|
| `--dsn` | `APPLOGGER_DSN` |
| `--log-endpoint` | `APPLOGGER_LOG_ENDPOINT` |
| `--log-token` | `APPLOGGER_LOG_TOKEN` |
| `--app-name` | `APPLOGGER_APP_NAME` |
| `--cache-dir` | `APPLOGGER_CACHE_DIR` |

### Examples

```bash
# Full check including a real test event
vendor/bin/applogger diagnose --dsn=https://your-host.applogger.eu/project-id

# Skip the test event (no network required)
vendor/bin/applogger diagnose --dsn=https://your-host.applogger.eu/project-id --no-send

# Machine-readable output for scripts
vendor/bin/applogger diagnose --dsn=https://your-host.applogger.eu/project-id --json

# Read DSN from environment
APPLOGGER_DSN=https://your-host.applogger.eu/project-id vendor/bin/applogger diagnose --no-send
```

### Text output (default)

```
AppLogger SDK diagnostics

  [OK  ] DSN          host=your-host.applogger.eu project=project-id
  [OK  ] Transport    HTTP transport active
  [OK  ] Cache        filesystem at /tmp/applogger-sdk
  [INFO] Logging      not configured (set log_endpoint + log_token)
  [OK  ] Test event   accepted (HTTP 202, 84ms)

Result: healthy
```

### JSON output (`--json`)

```json
{
    "healthy": true,
    "checks": [
        { "name": "DSN",        "status": "ok",   "detail": "host=your-host.applogger.eu project=project-id" },
        { "name": "Transport",  "status": "ok",   "detail": "HTTP transport active" },
        { "name": "Cache",      "status": "ok",   "detail": "filesystem at /tmp/applogger-sdk" },
        { "name": "Logging",    "status": "info", "detail": "not configured (set log_endpoint + log_token)" },
        { "name": "Test event", "status": "ok",   "detail": "accepted (HTTP 202, 84ms)" }
    ]
}
```

---

## Checks

| Check | What it tests |
|---|---|
| **DSN** | Parses and validates the DSN string. Fails if missing or malformed. |
| **Transport** | Discovers a PSR-18 HTTP client. Reports `ok` for `HttpTransport`, `warn` (telemetry disabled) when no DSN is set, or `fail` if a DSN is present but no PSR-18 client is discoverable. |
| **Cache** | Verifies the cache directory (from `cacheDir` option, defaulting to `sys_get_temp_dir().'/applogger-sdk'`) is writable. A non-writable directory is a `warn` (circuit-breaker and rate-limit state will not persist), not a hard failure. |
| **Logging** | Checks whether `log_endpoint` + `log_token` are configured and a PSR-18 client is available for the log pipeline. Reports `info` when not configured, `fail` when configured but the client is unavailable. |
| **Test event** | Sends a single real HTTP `POST` to `/api/v1/errors` with a PII-free, identifiable diagnostic payload (`type=Diagnostic`, `message=applogger diagnose test event`). HTTP 202 or 409 is treated as success. Skipped when `--no-send` is given or when there is no DSN. |

---

## Exit codes

| Code | Meaning |
|---|---|
| `0` | All checks passed (healthy) |
| `1` | At least one check has status `fail` (unhealthy), or an internal error occurred |

`warn` and `info` results do **not** produce a non-zero exit code.
