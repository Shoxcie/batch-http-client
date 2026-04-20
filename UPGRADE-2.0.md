# Upgrading to 2.0

Two breaking changes. Both were signaled via `#[Deprecated]` in `1.1.0` — if you've cleared the deprecation notices there, the upgrade is a no-op.

## 1. Response helper functions renamed to snake_case

All free functions in `Shoxcie\BatchHttpClient` now use snake_case. The camelCase aliases from `1.1.0` are removed.

| Before (removed) | After |
|---|---|
| `getUrl($response)` | `get_url($response)` |
| `getTotalTime($response)` | `get_total_time($response)` |
| `getStatusCode($response)` | `get_status_code($response)` |
| `getHeaders($response)` | `get_headers($response)` |
| `getContent($response)` | `get_content($response)` |

```php
// before
use function Shoxcie\BatchHttpClient\getStatusCode;

$code = getStatusCode($response);

// after
use function Shoxcie\BatchHttpClient\get_status_code;

$code = get_status_code($response);
```

## 2. `user_data` option is now reserved

`BatchHttpClient` uses Symfony HttpClient's `user_data` option internally to correlate responses back to batch keys. In `1.x` the library wrapped the caller's `user_data` as `[$key, $originalUserData]` and exposed a `getUserData()` helper to unwrap it.

**In `2.0`:**
- Passing `user_data` in `RequestConfig::$options` throws `InvalidArgumentException`
- Returning `user_data` from a `retryOptions` Closure throws `InvalidArgumentException`
- `Shoxcie\BatchHttpClient\getUserData()` is removed

**Migration:** use the batch key you already pass to `request()`.

```php
// before — user_data to carry caller context through callbacks
use function Shoxcie\BatchHttpClient\getUserData;

new BatchHttpClient()
    ->request([
        'orders' => new RequestConfig(
            'GET',
            'https://api.example.com/orders',
            options: ['user_data' => ['tenant_id' => 42]],
        ),
    ])
    ->onSuccess(function (string $key, ResponseInterface $response): void {
        $context = getUserData($response); // ['tenant_id' => 42]
    })
    ->fetch();

// after — correlate via the batch key
$contexts = ['orders' => ['tenant_id' => 42]];

new BatchHttpClient()
    ->request([
        'orders' => new RequestConfig('GET', 'https://api.example.com/orders'),
    ])
    ->onSuccess(function (string $key, ResponseInterface $response) use ($contexts): void {
        $context = $contexts[$key]; // ['tenant_id' => 42]
    })
    ->fetch();
```

If you have many requests, key your context map the same way you key the batch:

```php
$configs = [
    'orders' => new RequestConfig('GET', '...'),
    'users'  => new RequestConfig('GET', '...'),
];

$contexts = [
    'orders' => ['tenant_id' => 42],
    'users'  => ['tenant_id' => 42, 'include_archived' => true],
];

new BatchHttpClient()
    ->request($configs)
    ->onSuccess(fn(string $key, ResponseInterface $r) => handle($r, $contexts[$key]))
    ->fetch();
```

## Finding callers before you upgrade

Staying on `1.1.0` first? The `#[Deprecated]` attribute makes PHP emit a deprecation notice at runtime for every deprecated call. Surface them by:

- Running your test suite — Pest/PHPUnit report deprecations per test
- Setting `error_reporting(E_ALL)` in a staging environment
- Grepping for the function imports (more reliable than matching bare names, since `getContent()` / `getHeaders()` / `getStatusCode()` also exist as methods on Symfony's `ResponseInterface`): `grep -rn 'use function Shoxcie\\BatchHttpClient\\' src tests`

Fix all hits on `1.1.0`, then bump to `^2.0` — the upgrade itself should be clean.
