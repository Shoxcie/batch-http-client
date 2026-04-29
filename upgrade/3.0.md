# Upgrading to 3.0

One breaking change. It was signaled via `#[Deprecated]` in `2.1.0` — if you've cleared the deprecation notices there, the upgrade is a no-op.

## 1. `onFailure()` removed — split into `onExhausted()` and `onAbort()`

The old `onFailure` callback fired in two semantically different situations:

1. A request had no retries left after a transport or HTTP error (expected, per-request).
2. An unexpected exception (broken JSON, a throwing user callback, etc.) escaped the stream loop and the whole batch was cancelled (unexpected, batch-wide).

`3.0` removes `onFailure` and exposes the two cases as separate callbacks.

| Before (removed) | After |
|---|---|
| `onFailure(fn(string $key, ResponseInterface $r, Throwable $e))` (retries-exhausted path) | `onExhausted(fn(string $key, ResponseInterface $r, TransportExceptionInterface\|HttpExceptionInterface $e))` |
| `onFailure(fn(string $key, ResponseInterface $r, Throwable $e))` (unexpected-abort path) | `onAbort(fn(string $key, ResponseInterface $r, Throwable $e))` |

```php
// before — one handler, two meanings
new BatchHttpClient()
    ->request([...])
    ->onFailure(function (string $key, ResponseInterface $r, Throwable $e): void {
        log_failure($key, $r, $e);
    })
    ->fetch();

// after — split by intent
new BatchHttpClient()
    ->request([...])
    ->onExhausted(function (string $key, ResponseInterface $r, TransportExceptionInterface|HttpExceptionInterface $e): void {
        log_exhausted($key, $r, $e);
    })
    ->onAbort(function (string $key, ResponseInterface $r, Throwable $e): void {
        log_abort($key, $r, $e);
    })
    ->fetch();
```

If you only care about per-request exhaustion, omit `onAbort` — the unexpected exception still bubbles up via `throw` from `fetch()`, so a `try/catch` around `fetch()` is enough.

## Finding callers before you upgrade

Staying on `2.1.0` first? The `#[Deprecated]` attribute makes PHP emit a deprecation notice at runtime for every deprecated call. Surface them by:

- Running your test suite — Pest/PHPUnit report deprecations per test
- Setting `error_reporting(E_ALL)` in a staging environment
- Grepping for the call: `grep -rn '->onFailure(' src tests`

Fix all hits on `2.1.0`, then bump to `^3.0` — the upgrade itself should be clean.
