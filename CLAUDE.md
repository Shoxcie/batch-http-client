# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`shoxcie/batch-http-client` — HTTP request batch executor with individual retries, built on Symfony HttpClient. Namespace: `Shoxcie\BatchHttpClient`.

## Design

Two-phase batch HTTP executor. Requests fire in parallel, each retries independently without blocking others. Split into `request()` and `fetch()` so the caller can do other work while requests are in flight. `BatchHttpClient` is stateful but reusable — `fetch()` resets internal state after collecting results.

### API

```php
$client = new BatchHttpClient();

$client->request([
    new RequestConfig('GET', 'https://api.example.com/users', required: true, retries: 3),
    new RequestConfig('POST', 'https://api.example.com/orders', options: ['json' => $data]),
]);

// do other work while requests are in flight

$results = $client->fetch(logger: function (RequestError $e) { ... });
```

### Classes

- `BatchHttpClient` — stateful, has `request()` and `fetch()`. Accepts optional `HttpClientInterface` in constructor (defaults to `HttpClient::create()`).
- `RequestConfig` — readonly DTO for per-request params (separate file). Constructor with defaults:
  - `method` (string)
  - `url` (string)
  - `options` (array, default `[]`) — standard Symfony HttpClient options (timeout, max_duration, headers, etc.)
  - `retryOptions` (array, default `[]`) — merged onto options for retries via `array_replace_recursive($options, $retryOptions)`
  - `throwOnError` (bool, default `true`) — if true and request exhausts all retries, rethrow last exception and cancel all in-flight requests
  - `decodeJson` (bool, default `true`) — `true`: `toArray()`, `false`: `getContent()`
  - `maxRetries` (int, default `0`) — max retry count
  - `initialRetryDelayMs` (int, ms, default `0`) — base delay for exponential backoff: `initialRetryDelayMs * 2^attempt`
  - `retryOnTransportException` (bool, default `true`) — whether to retry on Symfony transport exceptions (connection timeouts, DNS failures, etc.)

### `request(array<RequestConfig>)` — fire all requests, return `static`

Fires all HTTP requests immediately (Symfony HttpClient is async by default). Stores responses and config internally. Returns `$this` for fluent usage.

### `fetch(logger, logAll)` — wait for responses, handle retries, return results

- `logger` (callable, optional) — called on each error (or all requests if `logAll` is true), receives: URL, HTTP status, request duration, Symfony exception, response headers, response body
- `logAll` (bool, default `false`) — when true, logger is called for successful requests too
- Resets internal state after collecting results (object is reusable)

### Retry behavior

- Any non-2xx HTTP status triggers a retry (hardcoded)
- Transport exceptions (connection timeout, DNS failure) retry based on `retryOnTransportException` per request (configurable, default true)
- Retries use exponential backoff: `initialRetryDelayMs * 2^attempt`
- Retry requests use `array_replace_recursive($options, $retryOptions)` for options
- Each request retries independently without blocking others

### Output

- Array of results matching input order
- Failed optional requests return `null`
- If a required request fails after all retries → rethrow the last caught Symfony exception, cancel all remaining requests

## Commands

```bash
composer test                # Run Pest tests
composer test -- --filter=X  # Run a single test by name
composer analyse             # PHPStan (level 10, bleeding edge, strict rules)
composer cs:check            # PHP-CS-Fixer dry run (PER-CS 2.0)
composer cs:fix              # Auto-fix code style
composer rector              # Apply Rector refactorings
composer rector:check        # Rector dry run
composer quality             # Run analyse + cs:check + test in sequence
```

## Code Standards

- `declare(strict_types=1)` in every PHP file — enforced by CS Fixer and architecture tests
- All classes must be `final` — enforced by architecture test
- No `dd`, `dump`, `var_dump`, `die`, `exit` — enforced by architecture test
- PER Coding Style 2.0 with risky rules, strict comparisons (`===`), alphabetical imports
- PHPStan level 10 with strict rules and bleeding edge — no baseline, fix all errors
- When adding dependencies, use `composer require package/name` without version constraints — let Composer resolve versions
