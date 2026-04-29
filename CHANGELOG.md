# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.0] - 2026-04-29

### Changed

- `BatchHttpClient::onSuccess()` callback signature is now `Closure(string $key, mixed $result, ResponseInterface $response): void` (previously `Closure(string $key, ResponseInterface $response): void`). The `$result` is the value stored in `$results[$key]` — i.e. post-`parseResponse` if one is configured. Not contravariant: existing closures must add the `$result` parameter.

See [UPGRADE-4.0.md](UPGRADE-4.0.md) for migration details.

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

See [UPGRADE-3.0.md](UPGRADE-3.0.md) for migration details.

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

See [UPGRADE-2.0.md](UPGRADE-2.0.md) for migration details.

## [1.1.0] - 2026-04-20

### Added

- snake_case free functions in `Shoxcie\BatchHttpClient`: `get_url`, `get_total_time`, `get_status_code`, `get_headers`, `get_content`.

### Deprecated

- camelCase function aliases (`getUrl`, `getTotalTime`, `getStatusCode`, `getHeaders`, `getContent`) — will be removed in 2.0.
- `getUserData()` helper — will be removed in 2.0.

## [1.0.0] - 2026-04-16

Initial release.
