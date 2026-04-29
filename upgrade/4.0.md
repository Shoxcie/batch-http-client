# Upgrading to 4.0

Breaking callback signature change.

## 1. `onSuccess()` callback receives the parsed result

`onSuccess` now gets the value already stored in the results array as a third positional argument, mirroring `parseResponse`. Callbacks no longer need to call `$response->toArray()` again to inspect the body, and they automatically see whatever `parseResponse` returned (if one is configured).

This is **not** contravariant — every existing `onSuccess` closure must add the new `mixed $result` parameter between `$key` and `$response`.

| Before | After |
|---|---|
| `onSuccess(fn(string $key, ResponseInterface $r))` | `onSuccess(fn(string $key, mixed $result, ResponseInterface $r))` |

```php
// before
new BatchHttpClient()
    ->request([...])
    ->onSuccess(function (string $key, ResponseInterface $response): void {
        $body = $response->toArray(); // re-decoded the body just to read it
        log_success($key, $body);
    })
    ->fetch();

// after
new BatchHttpClient()
    ->request([...])
    ->onSuccess(function (string $key, mixed $result, ResponseInterface $response): void {
        // $result is what's already in $results[$key] — post-parseResponse if configured
        log_success($key, $result);
    })
    ->fetch();
```

If a `RequestConfig` configured a `parseResponse` closure, `$result` is its return value (i.e. the same value `fetch()` returns in `$results[$key]`). With no `parseResponse`, `$result` is `array` (when `decodeJson: true`, the default) or `string` (when `decodeJson: false`).

## Finding callers before you upgrade

Grep for the call:

```
grep -rn '->onSuccess(' src tests
```

Each match must add the `$result` parameter to its closure signature.
