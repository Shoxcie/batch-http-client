# Batch HTTP Client

HTTP request batch executor with individual retries, built on [Symfony HttpClient](https://github.com/symfony/http-client).

Fire multiple HTTP requests in parallel. Each request retries independently without blocking others.

## Requirements

- PHP 8.4+
- Symfony HttpClient 8.0+

## Installation

```bash
composer require shoxcie/batch-http-client
```

## Usage

```php
use Shoxcie\BatchHttpClient\BatchHttpClient;
use Shoxcie\BatchHttpClient\RequestConfig;

$client = new BatchHttpClient();

$results = $client
    ->request([
        'users' => new RequestConfig('GET', 'https://api.example.com/users'),
        'orders' => new RequestConfig('POST', 'https://api.example.com/orders', options: [
            'json' => ['item' => 'widget', 'qty' => 3],
        ]),
    ])
    ->fetch();

$results['users'];  // decoded JSON array
$results['orders']; // decoded JSON array
```

### Retries

```php
$results = $client
    ->request([
        'flaky' => new RequestConfig('GET', 'https://api.example.com/flaky',
            maxRetries: 3,
        ),
    ])
    ->fetch();
```

Any non-2xx response or transport error (connection timeout, DNS failure) triggers a retry. Transport exception retries can be disabled per request with `retryOnTransportException: false`.

### Retry options

Override options on retry with a static array:

```php
new RequestConfig('GET', 'https://api.example.com/resource',
    maxRetries: 2,
    retryOptions: ['timeout' => 30],
)
```

Or dynamically with a Closure:

```php
new RequestConfig('GET', 'https://api.example.com/resource',
    maxRetries: 3,
    retryOptions: function (int $attempt, Throwable $e): array {
        return ['timeout' => 10 * $attempt];
    },
)
```

Retry options are merged onto the original options via `array_replace_recursive()`.

### Callbacks

```php
$results = $client
    ->request([...])
    ->onSuccess(function (string $key, ResponseInterface $response) {
        // called for each 2xx response
    })
    ->onRetry(function (string $key, int $attempt, ResponseInterface $failedResponse, ExceptionInterface $e, ResponseInterface $retryResponse) {
        // called when a retry fires
    })
    ->onFailure(function (string $key, ResponseInterface $response, Throwable $e) {
        // called when a request fails permanently (retries exhausted)
    })
    ->fetch();
```

### Error handling

By default, if a request exhausts all retries, the last exception is rethrown and all in-flight requests are cancelled:

```php
new RequestConfig('GET', 'https://api.example.com/critical',
    maxRetries: 3,
    throwOnError: true, // default
)
```

Set `throwOnError: false` for optional requests. Failed optional requests return `null` in the results array:

```php
new RequestConfig('GET', 'https://api.example.com/optional',
    throwOnError: false,
)
```

### Response decoding

By default, responses are decoded as JSON (`toArray()`). Set `decodeJson: false` to get raw content (`getContent()`):

```php
new RequestConfig('GET', 'https://example.com/file.csv',
    decodeJson: false,
)
```

### Custom HTTP client

Pass any `HttpClientInterface` implementation:

```php
use Symfony\Component\HttpClient\HttpClient;

$httpClient = HttpClient::create([
    'timeout' => 10,
    'max_duration' => 30,
]);

$client = new BatchHttpClient($httpClient);
```

## RequestConfig reference

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `method` | `string` | *(required)* | HTTP method |
| `url` | `string` | *(required)* | Request URL |
| `options` | `array` | `[]` | Symfony HttpClient [options](https://symfony.com/doc/current/http_client.html#configuration) |
| `retryOptions` | `array\|Closure` | `[]` | Options merged on retry, or Closure receiving `(int $attempt, Throwable $e)` |
| `throwOnError` | `bool` | `true` | Rethrow exception after retries exhausted |
| `decodeJson` | `bool` | `true` | Decode response as JSON |
| `maxRetries` | `int` | `0` | Maximum retry attempts |
| `retryOnTransportException` | `bool` | `true` | Retry on transport errors (timeouts, DNS) |

## License

MIT — see [LICENSE](LICENSE) for details.
