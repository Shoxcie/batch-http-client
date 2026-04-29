# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`shoxcie/batch-http-client` — HTTP request batch executor with individual retries, built on Symfony HttpClient. Namespace: `Shoxcie\BatchHttpClient`.

## Design

Two-phase batch HTTP executor. Requests fire in parallel, each retries independently without blocking others. Split into `request()` and `fetch()` so the caller can do other work while requests are in flight. Uses Symfony's `stream()` API with `isFirst` / `isLast` / `isTimeout` chunk pattern for non-blocking parallel processing.

### API

```php
$client = new BatchHttpClient();

$results = $client
    ->request([
        'users'  => new RequestConfig('GET', 'https://api.example.com/users', maxRetries: 3),
        'orders' => new RequestConfig('POST', 'https://api.example.com/orders', options: ['json' => $data]),
    ])
    ->onSuccess(function (string $key, mixed $result, ResponseInterface $response) { ... })
    ->onRetry(function (string $key, int $attempt, ResponseInterface $failedResponse, TransportExceptionInterface|HttpExceptionInterface|InvalidResponseException $e, ResponseInterface $retryResponse) { ... })
    ->onExhausted(function (string $key, ResponseInterface $response, TransportExceptionInterface|HttpExceptionInterface|InvalidResponseException $e) { ... })
    ->onAbort(function (string $key, ResponseInterface $response, Throwable $e) { ... })
    ->fetch();
```

### Classes

- `BatchHttpClient` — stateful, has `request()`, `onSuccess()`, `onRetry()`, `onExhausted()`, `onAbort()`, and `fetch()`. Accepts optional `HttpClientInterface` in constructor (defaults to `HttpClient::create()`).
- `RequestConfig` — readonly DTO for per-request params. Constructor with defaults:
  - `method` (string)
  - `url` (string)
  - `options` (array, default `[]`) — standard Symfony HttpClient options (timeout, max_duration, headers, etc.)
  - `retryOptions` (array|Closure, default `[]`) — merged onto options for retries via `array_replace_recursive($options, $retryOptions)`. If Closure: receives `(int $attempt, Throwable $e)`, must return options array.
  - `throwOnExhausted` (bool, default `true`) — if true and request exhausts all retries, rethrow last exception and cancel all in-flight requests
  - `decodeJson` (bool, default `true`) — `true`: `toArray()`, `false`: `getContent()`
  - `maxRetries` (int, default `0`) — max retry count
  - `retryOnTransportException` (bool, default `true`) — whether to retry on Symfony transport exceptions (connection timeouts, DNS failures, etc.)
  - `parseResponse` (Closure|null, default `null`) — runs after the body is decoded and before `onSuccess`, only on a 2xx response. Receives `(string $key, mixed $result, ResponseInterface $response)` and returns the value to store in `$results[$key]`. Throwing `InvalidResponseException` triggers a retry just like an HTTP/transport failure (counts against `maxRetries`, fires `onRetry`, and on exhaustion fires `onExhausted` + rethrows if `throwOnExhausted: true`).
- `InvalidResponseException` — marker `RuntimeException` thrown from a `parseResponse` closure to reject a semantically invalid 2xx response and request a retry.

### `request(array<string, RequestConfig>)` — fire all requests, return `static`

Fires all HTTP requests immediately (Symfony HttpClient is async by default). Stores responses and config internally. Returns `$this` for fluent usage. Throws `InvalidArgumentException` if `user_data` is present in `options` — the key is reserved for internal correlation.

### `fetch()` — wait for responses, handle retries, return results

- Uses `stream()` with `isFirst` / `isLast` chunk pattern (Symfony docs recommended approach)
- `isFirst`: acknowledges status code via `$response->getStatusCode()` to prevent generator auto-throw
- `isLast`: reads response body, runs `parseResponse` if configured, stores result
- Non-2xx, transport errors, and `InvalidResponseException` from `parseResponse` caught via `catch (TransportExceptionInterface | HttpExceptionInterface | InvalidResponseException)`
- Outer `try/catch (Throwable)` as safety net — cancels all, calls `onAbort`, rethrows
- Breaks out of `stream()` foreach only when a retry is scheduled (to restart stream with updated pool)

### Callbacks

- `onSuccess(Closure)` — called for each 2xx response after `parseResponse` (if any) returns: `(string $key, mixed $result, ResponseInterface $response)`. `$result` is the value stored in `$results[$key]` (post-`parseResponse` if configured).
- `onRetry(Closure)` — called when a retry fires: `(string $key, int $attempt, ResponseInterface $failedResponse, TransportExceptionInterface|HttpExceptionInterface|InvalidResponseException $e, ResponseInterface $retryResponse)`
- `onExhausted(Closure)` — called when a single request exhausts all retries: `(string $key, ResponseInterface $response, TransportExceptionInterface|HttpExceptionInterface|InvalidResponseException $e)`
- `onAbort(Closure)` — called when an unexpected exception cancels the whole batch (broken JSON, throwing user callback, parser throwing something other than `InvalidResponseException`, etc.): `(string $key, ResponseInterface $response, Throwable $e)`. Skipped if the throw happened before any response was processed.

### Retry behavior

- Any non-2xx HTTP status triggers a retry (Symfony throws `HttpExceptionInterface` which is caught)
- Transport exceptions (connection timeout, DNS failure) retry based on `retryOnTransportException` per request (configurable, default true)
- A `parseResponse` closure throwing `InvalidResponseException` triggers a retry on the same machinery (counts against `maxRetries`, fires `onRetry`/`onExhausted`)
- Retries fire immediately (no backoff delay)
- Retry requests use `array_replace_recursive($options, $retryOptions)` for options
- `retryOptions` can be a Closure receiving `(int $attempt, Throwable $e)` for dynamic retry configuration
- Each request retries independently without blocking others
- `$response->cancel()` called on transport errors before retry to free the broken socket

### Output

- `array<string, mixed>` of results keyed by request key
- Failed optional requests (`throwOnExhausted: false`) return `null`
- If `throwOnExhausted` request fails after all retries → rethrow the last caught Symfony exception, cancel all remaining requests

## Commands

```bash
composer test                # Run Pest tests
composer test -- --filter=X  # Run a single test by name
composer analyse             # PHPStan (level 10, bleeding edge, strict rules)
composer cs:check            # PHP-CS-Fixer dry run (PER-CS 2.0)
composer cs:fix              # Auto-fix code style
composer rector              # Apply Rector refactorings
composer rector:check        # Rector dry run
composer quality             # Run analyse + rector:check + cs:check + test in sequence
```

## Code Standards

- `declare(strict_types=1)` in every PHP file — enforced by CS Fixer and architecture tests
- All classes must be `final` — enforced by architecture test
- No `dd`, `dump`, `var_dump`, `die`, `exit` — enforced by architecture test
- PER Coding Style 2.0 with risky rules, strict comparisons (`===`), alphabetical imports
- PHPStan level 10 with strict rules and bleeding edge — no baseline, fix all errors
- When adding dependencies, use `composer require package/name` without version constraints — let Composer resolve versions

## Tests

Unit tests in `tests/Unit/BatchHttpClientTest.php` using `MockHttpClient` / `MockResponse` / `JsonMockResponse`, grouped by `describe` blocks:

- Successful batch requests (2xx) — verify results array matches input keys
- Mixed success/failure results — some 2xx, some errors
- Retry behavior — verify retry count
- `throwOnExhausted: true` — exception thrown after retries exhausted, all in-flight cancelled
- `throwOnExhausted: false` — failed requests return `null`
- Transport exception handling — DNS failure, connection timeout
- `retryOnTransportException: true` vs `false`
- `onSuccess` / `onRetry` / `onExhausted` / `onAbort` callbacks — verify they receive correct arguments
- `decodeJson: true` vs `false` — `toArray()` vs `getContent()`
- `retryOptions` merging — verify `array_replace_recursive` behavior on retries
- `retryOptions` as Closure — verify dynamic retry options based on attempt/exception
- `user_data` rejection — throws `InvalidArgumentException` when `options` or a `retryOptions` Closure contains `user_data`
- Safety-net catch — outer `catch(Throwable)` handles unexpected exceptions (e.g. from callbacks, broken JSON with `decodeJson: true`)
- `parseResponse` — replaces results, retries on `InvalidResponseException`, exhausts to `onExhausted`/`null`/rethrow per `throwOnExhausted`, and routes any other throwable to `onAbort`
