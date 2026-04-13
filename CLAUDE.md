# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`shoxcie/batch-http-client` — HTTP request batch executor with individual retries, built on Symfony HttpClient. Namespace: `Shoxcie\BatchHttpClient`.

## Design

A single function that executes HTTP requests in parallel. Each request retries independently without blocking others.

### Input

- **Requests array** — each element contains:
  - Standard Symfony HttpClient params: method, URL, options
  - **Is required** (bool) — if a required request exhausts all retries, throw and cancel all other in-flight requests immediately
  - **Retries count** (int) — max retry count for this request
  - **Decode JSON** (bool) — `true`: call `toArray()`, `false`: call `getContent()` (raw body)
  - **Retry delay** (int, ms) — base delay for exponential backoff: `retryDelay * 2^attempt` (default 0ms)
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
