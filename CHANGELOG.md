# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
