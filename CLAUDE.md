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
    ->onSuccess(function (string $key, ResponseInterface $response) { ... })
    ->onRetry(function (string $key, int $attempt, ResponseInterface $failedResponse, ExceptionInterface $e, ResponseInterface $retryResponse) { ... })
    ->onFailure(function (string $key, ResponseInterface $response, Throwable $e) { ... })
    ->fetch();
```

### Classes

- `BatchHttpClient` — stateful, has `request()`, `onSuccess()`, `onRetry()`, `onFailure()`, and `fetch()`. Accepts optional `HttpClientInterface` in constructor (defaults to `HttpClient::create()`).
- `RequestConfig` — readonly DTO for per-request params. Constructor with defaults:
  - `method` (string)
  - `url` (string)
  - `options` (array, default `[]`) — standard Symfony HttpClient options (timeout, max_duration, headers, etc.)
  - `retryOptions` (array|Closure, default `[]`) — merged onto options for retries via `array_replace_recursive($options, $retryOptions)`. If Closure: receives `(int $attempt, Throwable $e)`, must return options array.
  - `throwOnError` (bool, default `true`) — if true and request exhausts all retries, rethrow last exception and cancel all in-flight requests
  - `decodeJson` (bool, default `true`) — `true`: `toArray()`, `false`: `getContent()`
  - `maxRetries` (int, default `0`) — max retry count
  - `retryOnTransportException` (bool, default `true`) — whether to retry on Symfony transport exceptions (connection timeouts, DNS failures, etc.)

### `request(array<string, RequestConfig>)` — fire all requests, return `static`

Fires all HTTP requests immediately (Symfony HttpClient is async by default). Stores responses and config internally. Returns `$this` for fluent usage. Preserves caller's `user_data` by wrapping as `[$internalKey, $originalUserData]`.

### `fetch()` — wait for responses, handle retries, return results

- Uses `stream()` with `isFirst` / `isLast` chunk pattern (Symfony docs recommended approach)
- `isFirst`: acknowledges status code via `$response->getStatusCode()` to prevent generator auto-throw
- `isLast`: reads response body, stores result
- Non-2xx and transport errors caught via `catch (TransportExceptionInterface | HttpExceptionInterface)`
- Outer `try/catch (Throwable)` as safety net — cancels all, calls `onFailure`, rethrows
- Breaks out of `stream()` foreach only when a retry is scheduled (to restart stream with updated pool)

### Callbacks

- `onSuccess(Closure)` — called for each 2xx response: `(string $key, ResponseInterface $response)`
- `onRetry(Closure)` — called when a retry fires: `(string $key, int $attempt, ResponseInterface $failedResponse, ExceptionInterface $e, ResponseInterface $retryResponse)`
- `onFailure(Closure)` — called when a request fails permanently: `(string $key, ResponseInterface $response, Throwable $e)`

### Retry behavior

- Any non-2xx HTTP status triggers a retry (Symfony throws `HttpExceptionInterface` which is caught)
- Transport exceptions (connection timeout, DNS failure) retry based on `retryOnTransportException` per request (configurable, default true)
- Retries fire immediately (no backoff delay)
- Retry requests use `array_replace_recursive($options, $retryOptions)` for options
- `retryOptions` can be a Closure receiving `(int $attempt, Throwable $e)` for dynamic retry configuration
- Each request retries independently without blocking others
- `$response->cancel()` called on transport errors before retry to free the broken socket

### Output

- `array<string, mixed>` of results keyed by request key
- Failed optional requests (`throwOnError: false`) return `null`
- If `throwOnError` request fails after all retries → rethrow the last caught Symfony exception, cancel all remaining requests

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

## TODO: Tests

Write comprehensive unit tests using `MockHttpClient` / `MockResponse` / `JsonMockResponse`:

- [x] Successful batch requests (2xx) — verify results array matches input keys
- [x] Mixed success/failure results — some 2xx, some errors
- [x] Retry behavior — verify retry count
- [x] `throwOnError: true` — exception thrown after retries exhausted, all in-flight cancelled
- [x] `throwOnError: false` — failed requests return `null`
- [x] Transport exception handling — DNS failure, connection timeout
- [x] `retryOnTransportException: true` vs `false`
- [x] `onSuccess` / `onRetry` / `onFailure` callbacks — verify they receive correct arguments
- [x] `decodeJson: true` vs `false` — `toArray()` vs `getContent()`
- [x] `retryOptions` merging — verify `array_replace_recursive` behavior on retries
- [x] `retryOptions` as Closure — verify dynamic retry options based on attempt/exception
- [x] `user_data` preservation — caller's original user_data accessible after batch processing
- [ ] Safety-net catch — outer `catch(Throwable)` handles unexpected exceptions (e.g. from callbacks, broken JSON with `decodeJson: true`)

## TODO: Breaking changes (next major)

- [ ] Remove caller `user_data` preservation. Internally we wrap caller's `user_data` as `[$key, $originalUserData]` and expose a `getUserData()` helper to unwrap it — this is unintuitive. In a future major release: keep using `user_data` internally (store the key directly, not a wrapper array), but throw an exception if the caller passes `user_data` in `options`. Drop the `getUserData()` helper.
