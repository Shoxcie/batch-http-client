# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.0] - 2026-04-29

### Changed

- `BatchHttpClient::onSuccess()` callback signature is now `Closure(string $key, int $retries, mixed $result, ResponseInterface $response): void` (previously `Closure(string $key, ResponseInterface $response): void`). `$retries` is the number of retries that happened before the success (0 for first-attempt, N for success after N retries). `$result` is the value stored in `$results[$key]` — i.e. post-`parseResponse` if one is configured. Not contravariant: existing closures must add the new positional parameters.
- `BatchHttpClient::onExhausted()` callback signature is now `Closure(string $key, int $retries, ResponseInterface $response, ExceptionInterface|InvalidResponseException $e): void` (previously without `$retries`). `$retries` equals `maxRetries` on normal exhaustion and is less than `maxRetries` when a transport error short-circuits with `retryOnTransportException: false`. Not contravariant: existing closures must add the `$retries` parameter.
- `BatchHttpClient::onAbort()` callback signature is now `Closure(string $key, int $retries, ResponseInterface $response, Throwable $e): void` (previously without `$retries`). `$retries` is the retry count for the request being processed when the unexpected abort fired. Not contravariant: existing closures must add the `$retries` parameter. With this change, all four observability callbacks plus `parseResponse` and the `retryOptions` Closure share the `(key, retries, ...)` prefix.
- `RequestConfig::$parseResponse` callback signature is now `Closure(string $key, int $retries, mixed $result, ResponseInterface $response): mixed` (previously without `$retries`). `$retries` is the number of retries that happened before this parse attempt (0 on first attempt) — the same value `onSuccess` sees on a successful parse. Not contravariant: existing closures must add the `$retries` parameter.
- `RequestConfig::$retryOptions` Closure signature is now `Closure(string $key, int $retries, ExceptionInterface|InvalidResponseException $e): array<string, mixed>` (previously without `$key`). Brings the Closure into line with the `(string $key, int $retries, ...)` prefix shared by every other caller-facing closure, useful when a single Closure is reused across multiple `RequestConfig`s and needs to branch by key. Not contravariant: existing closures must add the `$key` parameter.
- `RequestConfig::$throwOnError` renamed to `RequestConfig::$throwOnExhausted`. The previous name suggested "throw on any error" but the property only governs the post-retry-exhausted path; the new name aligns with the existing `onExhausted` callback. No behavior change.
- The retry catch now covers `Symfony\Contracts\HttpClient\Exception\ExceptionInterface | InvalidResponseException` (previously `TransportExceptionInterface | HttpExceptionInterface | InvalidResponseException`). Decoding errors (notably malformed JSON via `decodeJson: true`) and redirection-without-follow errors are now retried instead of routed to `onAbort`. `onRetry()` and `onExhausted()` callback signatures widen to match. **Behavior change**: a 200 OK with malformed JSON now retries up to `maxRetries` and exhausts to `onExhausted` (or rethrows `JsonException` per `throwOnExhausted`), rather than firing `onAbort` and cancelling the batch.

See [upgrade/4.0.md](upgrade/4.0.md) for migration details.

## [3.1.0] - 2026-04-28

### Added

- `RequestConfig::$parseResponse` — optional `Closure(string $key, mixed $result, ResponseInterface $response): mixed` that runs on 2xx responses after decoding and before `onSuccess`. Return value replaces the entry in the results array, enabling custom parsing/transformation.
- `Shoxcie\BatchHttpClient\InvalidResponseException` — marker `RuntimeException` to throw from a `parseResponse` closure to reject a semantically invalid 2xx response and trigger a retry on the existing retry machinery (counts against `maxRetries`, fires `onRetry`, and on exhaustion fires `onExhausted` plus rethrows if `throwOnError: true`). Any other `Throwable` from the parser routes to `onAbort` as before.

### Changed

- `BatchHttpClient::onRetry()` callback signature for `$e` is now `TransportExceptionInterface | HttpExceptionInterface | InvalidResponseException` (previously `ExceptionInterface`). Tightens the documented type to match what is actually thrown at the call site, and aligns with `onExhausted()`. Contravariant for closures with the previous wider typehint.
- `BatchHttpClient::onExhausted()` callback signature widened to accept `TransportExceptionInterface | HttpExceptionInterface | InvalidResponseException` for `$e`. Contravariant — existing closures keep working without modification.

## [3.0.0] - 2026-04-22

### Removed

- `BatchHttpClient::onFailure(Closure)` (deprecated in 2.1.0). Replace with `onExhausted()` for the retries-exhausted path and/or `onAbort()` for the unexpected-abort path.

See [upgrade/3.0.md](upgrade/3.0.md) for migration details.

## [2.1.0] - 2026-04-22

### Added

- `BatchHttpClient::onExhausted(Closure)` — fired when a single request exhausts all retries. Exception parameter is narrowed to `TransportExceptionInterface | HttpExceptionInterface`.
- `BatchHttpClient::onAbort(Closure)` — fired when an unexpected `Throwable` (broken JSON with `decodeJson: true`, user callback throwing, etc.) cancels the whole batch.

### Deprecated

- `BatchHttpClient::onFailure(Closure)` — split into `onExhausted` and `onAbort`. `onFailure` still fires as a fallback for either path when the corresponding new callback is not set, so existing code keeps working. Will be removed in `3.0`.

## [2.0.0] - 2026-04-20

### Removed

- camelCase function aliases in `Response.php` (deprecated in 1.1.0). Use snake_case equivalents: `get_url`, `get_total_time`, `get_status_code`, `get_headers`, `get_content`.
- `Shoxcie\BatchHttpClient\getUserData()` helper. Correlate responses via the batch key passed to `request()` instead.

### Changed

- `BatchHttpClient::request()` now throws `InvalidArgumentException` if `RequestConfig::$options` contains the reserved `user_data` key.
- `BatchHttpClient::fetch()` now throws `InvalidArgumentException` if a `retryOptions` Closure returns options containing `user_data`.

See [upgrade/2.0.md](upgrade/2.0.md) for migration details.

## [1.1.0] - 2026-04-20

### Added

- snake_case free functions in `Shoxcie\BatchHttpClient`: `get_url`, `get_total_time`, `get_status_code`, `get_headers`, `get_content`.

### Deprecated

- camelCase function aliases (`getUrl`, `getTotalTime`, `getStatusCode`, `getHeaders`, `getContent`) — will be removed in 2.0.
- `getUserData()` helper — will be removed in 2.0.

## [1.0.0] - 2026-04-16

Initial release.
