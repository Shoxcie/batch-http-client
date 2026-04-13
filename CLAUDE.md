# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`shoxcie/batch-http-client` — HTTP request batch executor with individual retries, built on Symfony HttpClient. Namespace: `Shoxcie\BatchHttpClient`.

## Design

Two-phase batch HTTP executor. Requests fire in parallel, each retries independently without blocking others. Split into `request()` and `fetch()` so the caller can do other work while requests are in flight.

### API

```php
$batch = $client->request([...]);
// do other work while requests are in flight
$results = $client->fetch($batch);
```

### `request(requests)` — fire all requests, return immediately

- **Requests array** — each element contains:
  - Standard Symfony HttpClient params: method, URL, options
  - `required` (bool) — if a required request exhausts all retries, throw and cancel all other in-flight requests immediately
  - `retries` (int) — max retry count for this request
  - `decodeJson` (bool) — `true`: call `toArray()`, `false`: call `getContent()` (raw body)
  - `retryDelay` (int, ms) — base delay for exponential backoff: `retryDelay * 2^attempt` (default 0ms)
  - `retryOptions` (array) — Symfony HttpClient options merged onto the original options for retry attempts via `array_replace_recursive($options, $retryOptions)`. Use to override timeout, max_duration, headers, etc. on retries.
- Returns a batch handle object holding Symfony response objects and per-request config

### `fetch(batch)` — wait for responses, handle retries, return results

- **Logger callback** — receives on each error (or all requests if verbose flag is set):
  - URL, HTTP status, request duration, Symfony exception, response headers, response body
- **Log all flag** (bool, default false) — when true, logger is called for successful requests too

### Output

- Array of results matching input order
- Failed optional requests return `null`
- If a required request fails after all retries → exception, all remaining requests cancelled

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
