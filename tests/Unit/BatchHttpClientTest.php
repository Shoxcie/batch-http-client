<?php

declare(strict_types=1);

use Shoxcie\BatchHttpClient\BatchHttpClient;
use Shoxcie\BatchHttpClient\InvalidResponseException;
use Shoxcie\BatchHttpClient\RequestConfig;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

describe('successful batch requests (2xx)', function (): void {
    test('single request returns result with matching key', function (): void {
        $mockClient = new MockHttpClient([
            new JsonMockResponse(['id' => 1, 'name' => 'Alice']),
        ]);

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'users' => new RequestConfig('GET', 'https://api.example.com/users'),
            ])
            ->fetch();

        expect($results)
            ->toHaveCount(1)
            ->toHaveKey('users')
            ->and($results['users'])
                ->toBe(['id' => 1, 'name' => 'Alice']);
    });

    test('multiple requests return results with matching keys', function (): void {
        $mockClient = new MockHttpClient([
            new JsonMockResponse(['id' => 1, 'name' => 'Alice']),
            new JsonMockResponse(['order_id' => 42, 'total' => 99.99]),
            new JsonMockResponse([['sku' => 'A1'], ['sku' => 'B2']]),
        ]);

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'users' => new RequestConfig('GET', 'https://api.example.com/users'),
                'orders' => new RequestConfig('GET', 'https://api.example.com/orders'),
                'products' => new RequestConfig('GET', 'https://api.example.com/products'),
            ])
            ->fetch();

        expect($results)
            ->toHaveCount(3)
            ->toHaveKeys(['users', 'orders', 'products'])
            ->and($results['users'])->toBe(['id' => 1, 'name' => 'Alice'])
            ->and($results['orders'])->toBe(['order_id' => 42, 'total' => 99.99])
            ->and($results['products'])->toBe([['sku' => 'A1'], ['sku' => 'B2']]);
    });

    test('result keys match input config keys exactly', function (): void {
        $mockClient = new MockHttpClient(
            fn(string $method, string $url): JsonMockResponse => new JsonMockResponse(['url' => $url]),
        );

        $configs = [
            'key-alpha' => new RequestConfig('GET', 'https://api.example.com/alpha'),
            'key-beta' => new RequestConfig('GET', 'https://api.example.com/beta'),
            'key-gamma' => new RequestConfig('GET', 'https://api.example.com/gamma'),
        ];

        $results = (new BatchHttpClient($mockClient))
            ->request($configs)
            ->fetch();

        expect(array_keys($results))->toBe(array_keys($configs));
    });

    test('all successful results are non-null', function (): void {
        $mockClient = new MockHttpClient([
            new JsonMockResponse(['a' => 1]),
            new JsonMockResponse(['b' => 2]),
            new JsonMockResponse(['c' => 3]),
        ]);

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'first' => new RequestConfig('GET', 'https://api.example.com/first'),
                'second' => new RequestConfig('GET', 'https://api.example.com/second'),
                'third' => new RequestConfig('GET', 'https://api.example.com/third'),
            ])
            ->fetch();

        expect($results)->each(fn($value) => $value->not->toBeNull());
    });

    test('different HTTP methods resolve successfully', function (): void {
        $receivedMethods = [];

        $mockClient = new MockHttpClient(
            function (string $method, string $url) use (&$receivedMethods): JsonMockResponse {
                $receivedMethods[] = $method;

                return new JsonMockResponse(['method' => $method]);
            },
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'get' => new RequestConfig('GET', 'https://api.example.com/get'),
                'post' => new RequestConfig('POST', 'https://api.example.com/post'),
                'put' => new RequestConfig('PUT', 'https://api.example.com/put'),
            ])
            ->fetch();

        expect($receivedMethods)->toBe(['GET', 'POST', 'PUT'])
            ->and($results['get'])->toBe(['method' => 'GET'])
            ->and($results['post'])->toBe(['method' => 'POST'])
            ->and($results['put'])->toBe(['method' => 'PUT']);
    });
});

describe('mixed success/failure results', function (): void {
    test('successful requests return data while failed return null', function (): void {
        $mockClient = new MockHttpClient(
            function (string $method, string $url): JsonMockResponse {
                if (str_contains($url, '/failing')) {
                    return new JsonMockResponse(['error' => 'server error'], ['http_code' => 500]);
                }

                return new JsonMockResponse(['url' => $url]);
            },
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'users' => new RequestConfig('GET', 'https://api.example.com/users', [], [], false),
                'failing' => new RequestConfig('GET', 'https://api.example.com/failing', [], [], false),
                'orders' => new RequestConfig('GET', 'https://api.example.com/orders', [], [], false),
            ])
            ->fetch();

        expect($results['users'])->toBe(['url' => 'https://api.example.com/users'])
            ->and($results['orders'])->toBe(['url' => 'https://api.example.com/orders'])
            ->and($results['failing'])->toBeNull();
    });

    test('results contain all keys regardless of outcome', function (): void {
        $mockClient = new MockHttpClient(
            function (string $method, string $url): JsonMockResponse {
                if (str_contains($url, '/not-found')) {
                    return new JsonMockResponse([], ['http_code' => 404]);
                }

                if (str_contains($url, '/error')) {
                    return new JsonMockResponse([], ['http_code' => 500]);
                }

                return new JsonMockResponse(['ok' => true]);
            },
        );

        $configs = [
            'ok-1' => new RequestConfig('GET', 'https://api.example.com/ok-1', [], [], false),
            'not-found' => new RequestConfig('GET', 'https://api.example.com/not-found', [], [], false),
            'ok-2' => new RequestConfig('GET', 'https://api.example.com/ok-2', [], [], false),
            'error' => new RequestConfig('GET', 'https://api.example.com/error', [], [], false),
        ];

        $results = (new BatchHttpClient($mockClient))
            ->request($configs)
            ->fetch();

        expect(array_keys($results))->toEqualCanonicalizing(array_keys($configs));
    });
});

describe('retry behavior', function (): void {
    test('retries up to maxRetries times on failure', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false, true, 3),
            ])
            ->fetch();

        expect($mockClient->getRequestsCount())->toBe(4);
    });

    test('does not retry when maxRetries is zero', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false),
            ])
            ->fetch();

        expect($mockClient->getRequestsCount())->toBe(1);
    });

    test('stops retrying after first success', function (): void {
        $callCount = 0;

        $mockClient = new MockHttpClient(
            function () use (&$callCount): JsonMockResponse {
                ++$callCount;

                if ($callCount === 1) {
                    return new JsonMockResponse([], ['http_code' => 500]);
                }

                return new JsonMockResponse(['ok' => true]);
            },
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], true, true, 3),
            ])
            ->fetch();

        expect($results['api'])->toBe(['ok' => true])
            ->and($mockClient->getRequestsCount())->toBe(2);
    });

    test('each request retries independently', function (): void {
        $callCounts = ['alpha' => 0, 'beta' => 0];

        $mockClient = new MockHttpClient(
            function (string $method, string $url) use (&$callCounts): JsonMockResponse {
                $key = str_contains($url, '/alpha') ? 'alpha' : 'beta';
                ++$callCounts[$key];

                return new JsonMockResponse([], ['http_code' => 500]);
            },
        );

        (new BatchHttpClient($mockClient))
            ->request([
                'alpha' => new RequestConfig('GET', 'https://api.example.com/alpha', [], [], false, true, 2),
                'beta' => new RequestConfig('GET', 'https://api.example.com/beta', [], [], false, true, 4),
            ])
            ->fetch();

        expect($callCounts['alpha'])->toBe(3)
            ->and($callCounts['beta'])->toBe(5);
    });
});

describe('throwOnExhausted: true', function (): void {
    test('throws exception after retries exhausted', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        expect(
            fn(): array => (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], true, true, 2),
            ])
            ->fetch(),
        )->toThrow(ServerException::class);

        expect($mockClient->getRequestsCount())->toBe(3);
    });

    test('throws exception immediately with no retries', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        expect(
            fn(): array => (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api'),
            ])
            ->fetch(),
        )->toThrow(ServerException::class);

        expect($mockClient->getRequestsCount())->toBe(1);
    });

    // TODO: test('cancels all in-flight requests on failure', function (): void {})->todo();
});

describe('throwOnExhausted: false', function (): void {
    test('failed request returns null in results', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false),
            ])
            ->fetch();

        expect($results['api'])->toBeNull();
    });

    test('does not throw on failure', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false),
            ])
            ->fetch();

        expect($results)->toHaveKey('api');
    });

    test('batch continues processing after a failure', function (): void {
        $mockClient = new MockHttpClient(
            function (string $method, string $url): JsonMockResponse {
                if (str_contains($url, '/failing')) {
                    return new JsonMockResponse([], ['http_code' => 500]);
                }

                return new JsonMockResponse(['url' => $url]);
            },
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'failing' => new RequestConfig('GET', 'https://api.example.com/failing', [], [], false),
                'ok-1' => new RequestConfig('GET', 'https://api.example.com/ok-1', [], [], false),
                'ok-2' => new RequestConfig('GET', 'https://api.example.com/ok-2', [], [], false),
            ])
            ->fetch();

        expect($results)->toHaveCount(3)
            ->and($results['failing'])->toBeNull()
            ->and($results['ok-1'])->toBe(['url' => 'https://api.example.com/ok-1'])
            ->and($results['ok-2'])->toBe(['url' => 'https://api.example.com/ok-2']);
    });

    test('returns null after retries exhausted', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false, true, 2),
            ])
            ->fetch();

        expect($results['api'])->toBeNull()
            ->and($mockClient->getRequestsCount())->toBe(3);
    });
});

describe('transport exception handling', function (): void {
    test('handles error before headers received', function (): void {
        $mockClient = new MockHttpClient([
            new MockResponse([], ['error' => 'DNS resolution failed']),
        ]);

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false),
            ])
            ->fetch();

        expect($results['api'])->toBeNull();
    });

    test('handles error during body streaming', function (): void {
        $mockClient = new MockHttpClient([
            new MockResponse([new \RuntimeException('Connection reset')]),
        ]);

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false),
            ])
            ->fetch();

        expect($results['api'])->toBeNull();
    });

    test('throws transport exception with throwOnExhausted true', function (): void {
        $mockClient = new MockHttpClient([
            new MockResponse([], ['error' => 'Host unreachable']),
        ]);

        expect(
            fn(): array => (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api'),
            ])
            ->fetch(),
        )->toThrow(TransportException::class);
    });

    test('retries on transport exception by default', function (): void {
        $mockClient = new MockHttpClient(
            fn(): MockResponse => new MockResponse([], ['error' => 'Connection timed out']),
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false, true, 2),
            ])
            ->fetch();

        expect($results['api'])->toBeNull()
            ->and($mockClient->getRequestsCount())->toBe(3);
    });
});

describe('retryOnTransportException', function (): void {
    test('retries transport exception when true', function (): void {
        $mockClient = new MockHttpClient(
            fn(): MockResponse => new MockResponse([], ['error' => 'Connection timed out']),
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false, true, 2, true),
            ])
            ->fetch();

        expect($results['api'])->toBeNull()
            ->and($mockClient->getRequestsCount())->toBe(3);
    });

    test('does not retry transport exception when false', function (): void {
        $mockClient = new MockHttpClient(
            fn(): MockResponse => new MockResponse([], ['error' => 'DNS resolution failed']),
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false, true, 2, false),
            ])
            ->fetch();

        expect($results['api'])->toBeNull()
            ->and($mockClient->getRequestsCount())->toBe(1);
    });

    test('still retries HTTP exceptions when false', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false, true, 2, false),
            ])
            ->fetch();

        expect($results['api'])->toBeNull()
            ->and($mockClient->getRequestsCount())->toBe(3);
    });
});

describe('callbacks', function (): void {
    test('onSuccess receives key, retries, result, and response', function (): void {
        $captured = [];

        $mockClient = new MockHttpClient([
            new JsonMockResponse(['id' => 1]),
            new JsonMockResponse(['id' => 2]),
        ]);

        (new BatchHttpClient($mockClient))
            ->request([
                'users' => new RequestConfig('GET', 'https://api.example.com/users'),
                'orders' => new RequestConfig('GET', 'https://api.example.com/orders'),
            ])
            ->onSuccess(function (string $key, int $retries, $result, ResponseInterface $response) use (&$captured): void {
                $captured[] = ['key' => $key, 'retries' => $retries, 'result' => $result, 'response' => $response];
            })
            ->fetch();

        expect($captured)->toHaveCount(2)
            ->and($captured[0]['key'])->toBe('users')
            ->and($captured[0]['retries'])->toBe(0)
            ->and($captured[0]['result'])->toBe(['id' => 1])
            ->and($captured[0]['response'])->toBeInstanceOf(ResponseInterface::class)
            ->and($captured[1]['key'])->toBe('orders')
            ->and($captured[1]['retries'])->toBe(0)
            ->and($captured[1]['result'])->toBe(['id' => 2])
            ->and($captured[1]['response'])->toBeInstanceOf(ResponseInterface::class);
    });

    test('onSuccess receives non-zero retries when success follows a retry', function (): void {
        $callCount = 0;

        $mockClient = new MockHttpClient(
            function () use (&$callCount): JsonMockResponse {
                ++$callCount;

                return $callCount === 1
                    ? new JsonMockResponse([], ['http_code' => 500])
                    : new JsonMockResponse(['ok' => true]);
            },
        );

        $capturedRetries = null;

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], true, true, 1),
            ])
            ->onSuccess(function (string $key, int $retries, $result, ResponseInterface $response) use (&$capturedRetries): void {
                $capturedRetries = $retries;
            })
            ->fetch();

        expect($capturedRetries)->toBe(1);
    });

    test('onRetry receives key, retries, failed response, exception, and retry response', function (): void {
        $captured = [];
        $callCount = 0;

        $mockClient = new MockHttpClient(
            function () use (&$callCount): JsonMockResponse {
                ++$callCount;

                if ($callCount === 1) {
                    return new JsonMockResponse([], ['http_code' => 500]);
                }

                return new JsonMockResponse(['ok' => true]);
            },
        );

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], true, true, 2),
            ])
            ->onRetry(function (string $key, int $retries, ResponseInterface $failedResponse, ExceptionInterface $e, ResponseInterface $retryResponse) use (&$captured): void {
                $captured[] = [
                    'key' => $key,
                    'retries' => $retries,
                    'failedResponse' => $failedResponse,
                    'exception' => $e,
                    'retryResponse' => $retryResponse,
                ];
            })
            ->fetch();

        expect($captured)->toHaveCount(1)
            ->and($captured[0]['key'])->toBe('api')
            ->and($captured[0]['retries'])->toBe(1)
            ->and($captured[0]['failedResponse'])->toBeInstanceOf(ResponseInterface::class)
            ->and($captured[0]['exception'])->toBeInstanceOf(ExceptionInterface::class)
            ->and($captured[0]['retryResponse'])->toBeInstanceOf(ResponseInterface::class);
    });

    test('onExhausted receives key, retries, response, and exception', function (): void {
        $captured = [];

        $mockClient = new MockHttpClient([
            new JsonMockResponse([], ['http_code' => 500]),
        ]);

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], false),
            ])
            ->onExhausted(function (string $key, int $retries, ResponseInterface $response, \Throwable $e) use (&$captured): void {
                $captured[] = ['key' => $key, 'retries' => $retries, 'response' => $response, 'exception' => $e];
            })
            ->fetch();

        expect($captured)->toHaveCount(1)
            ->and($captured[0]['key'])->toBe('api')
            ->and($captured[0]['retries'])->toBe(0)
            ->and($captured[0]['response'])->toBeInstanceOf(ResponseInterface::class)
            ->and($captured[0]['exception'])->toBeInstanceOf(\Throwable::class);
    });

    test('onExhausted is invoked exactly once when throwOnExhausted rethrows', function (): void {
        $calls = 0;
        $capturedRetries = null;
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        try {
            (new BatchHttpClient($mockClient))
                ->request(['api' => new RequestConfig('GET', 'https://api.example.com/api')])
                ->onExhausted(function (string $key, int $retries) use (&$calls, &$capturedRetries): void {
                    ++$calls;
                    $capturedRetries = $retries;
                })
                ->fetch();
        } catch (ServerException $e) {
        }

        expect($calls)->toBe(1)
            ->and($capturedRetries)->toBe(0);
    });

    test('onExhausted receives retries less than maxRetries when transport short-circuits', function (): void {
        $capturedRetries = null;

        $mockClient = new MockHttpClient(
            fn(): MockResponse => new MockResponse([], ['error' => 'connection refused']),
        );

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    [],
                    false,
                    true,
                    5,
                    false,
                ),
            ])
            ->onExhausted(function (string $key, int $retries, ResponseInterface $response, $e) use (&$capturedRetries): void {
                $capturedRetries = $retries;
            })
            ->fetch();

        expect($capturedRetries)->toBe(0);
    });

    test('onAbort receives key, retries, response, and exception on unexpected exception', function (): void {
        $captured = [];
        $thrown = null;

        $mockClient = new MockHttpClient([
            new JsonMockResponse(['ok' => true]),
        ]);

        try {
            (new BatchHttpClient($mockClient))
                ->request([
                    'api' => new RequestConfig('GET', 'https://api.example.com/api'),
                ])
                ->onSuccess(function (string $key, int $retries, $result, ResponseInterface $response): void {
                    throw new \RuntimeException('boom');
                })
                ->onAbort(function (string $key, int $retries, ResponseInterface $response, \Throwable $e) use (&$captured): void {
                    $captured[] = ['key' => $key, 'retries' => $retries, 'response' => $response, 'exception' => $e];
                })
                ->fetch();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(\RuntimeException::class)
            ->and($captured)->toHaveCount(1)
            ->and($captured[0]['key'])->toBe('api')
            ->and($captured[0]['retries'])->toBe(0)
            ->and($captured[0]['response'])->toBeInstanceOf(ResponseInterface::class)
            ->and($captured[0]['exception'])->toBeInstanceOf(\RuntimeException::class);
    });

    test('onAbort receives non-zero retries when abort follows a retry', function (): void {
        $callCount = 0;

        $mockClient = new MockHttpClient(
            function () use (&$callCount): JsonMockResponse {
                ++$callCount;

                return $callCount === 1
                    ? new JsonMockResponse([], ['http_code' => 500])
                    : new JsonMockResponse(['ok' => true]);
            },
        );

        $capturedRetries = null;

        try {
            (new BatchHttpClient($mockClient))
                ->request([
                    'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], true, true, 1),
                ])
                ->onSuccess(function (string $key, int $retries, $result, ResponseInterface $response): void {
                    throw new \RuntimeException('boom');
                })
                ->onAbort(function (string $key, int $retries, ResponseInterface $response, \Throwable $e) use (&$capturedRetries): void {
                    $capturedRetries = $retries;
                })
                ->fetch();
        } catch (\RuntimeException $e) {
        }

        expect($capturedRetries)->toBe(1);
    });

});

describe('decodeJson', function (): void {
    test('returns decoded array when decodeJson is true', function (): void {
        $mockClient = new MockHttpClient([
            new JsonMockResponse(['id' => 1, 'name' => 'test']),
        ]);

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api'),
            ])
            ->fetch();

        expect($results['api'])->toBe(['id' => 1, 'name' => 'test']);
    });

    test('returns raw string when decodeJson is false', function (): void {
        $mockClient = new MockHttpClient([
            new MockResponse('raw body content'),
        ]);

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', [], [], true, false),
            ])
            ->fetch();

        expect($results['api'])->toBe('raw body content');
    });

    test('each request respects its own decodeJson setting', function (): void {
        $mockClient = new MockHttpClient(
            function (string $method, string $url): MockResponse {
                if (str_contains($url, '/json')) {
                    return new JsonMockResponse(['decoded' => true]);
                }

                return new MockResponse('plain text');
            },
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'json' => new RequestConfig('GET', 'https://api.example.com/json'),
                'raw' => new RequestConfig('GET', 'https://api.example.com/raw', [], [], true, false),
            ])
            ->fetch();

        expect($results['json'])->toBe(['decoded' => true])
            ->and($results['raw'])->toBe('plain text');
    });
});

describe('retryOptions merging', function (): void {
    test('retryOptions overrides matching options on retry', function (): void {
        $capturedHeaders = [];
        $callCount = 0;

        $mockClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedHeaders, &$callCount): JsonMockResponse {
                ++$callCount;
                $capturedHeaders[] = $options['normalized_headers'];

                if ($callCount === 1) {
                    return new JsonMockResponse([], ['http_code' => 500]);
                }

                return new JsonMockResponse(['ok' => true]);
            },
        );

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    ['headers' => ['Authorization' => 'Bearer old']],
                    ['headers' => ['Authorization' => 'Bearer new']],
                    true,
                    true,
                    1,
                ),
            ])
            ->fetch();

        expect($capturedHeaders[0]['authorization'])->toBe(['Authorization: Bearer old'])
            ->and($capturedHeaders[1]['authorization'])->toBe(['Authorization: Bearer new']);
    });

    test('retryOptions merges nested arrays recursively', function (): void {
        $capturedHeaders = [];
        $callCount = 0;

        $mockClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedHeaders, &$callCount): JsonMockResponse {
                ++$callCount;
                $capturedHeaders[] = $options['normalized_headers'];

                if ($callCount === 1) {
                    return new JsonMockResponse([], ['http_code' => 500]);
                }

                return new JsonMockResponse(['ok' => true]);
            },
        );

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    ['headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer token']],
                    ['headers' => ['X-Retry' => 'true']],
                    true,
                    true,
                    1,
                ),
            ])
            ->fetch();

        expect($capturedHeaders[1]['accept'])->toBe(['Accept: application/json'])
            ->and($capturedHeaders[1]['authorization'])->toBe(['Authorization: Bearer token'])
            ->and($capturedHeaders[1]['x-retry'])->toBe(['X-Retry: true']);
    });

    test('original options are used when retryOptions is empty', function (): void {
        $capturedHeaders = [];
        $callCount = 0;

        $mockClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedHeaders, &$callCount): JsonMockResponse {
                ++$callCount;
                $capturedHeaders[] = $options['normalized_headers'];

                if ($callCount === 1) {
                    return new JsonMockResponse([], ['http_code' => 500]);
                }

                return new JsonMockResponse(['ok' => true]);
            },
        );

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    ['headers' => ['Authorization' => 'Bearer token']],
                    [],
                    true,
                    true,
                    1,
                ),
            ])
            ->fetch();

        expect($capturedHeaders[1]['authorization'])->toBe(['Authorization: Bearer token']);
    });
});

describe('retryOptions as Closure', function (): void {
    test('closure receives key, attempt number, and exception', function (): void {
        $captured = [];
        $callCount = 0;

        $mockClient = new MockHttpClient(
            function () use (&$callCount): JsonMockResponse {
                ++$callCount;

                if ($callCount <= 2) {
                    return new JsonMockResponse([], ['http_code' => 500]);
                }

                return new JsonMockResponse(['ok' => true]);
            },
        );

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    function (string $key, int $retries, \Throwable $e) use (&$captured): array {
                        $captured[] = ['key' => $key, 'retries' => $retries, 'exception' => $e];

                        return [];
                    },
                    true,
                    true,
                    2,
                ),
            ])
            ->fetch();

        expect($captured)->toHaveCount(2)
            ->and($captured[0]['key'])->toBe('api')
            ->and($captured[0]['retries'])->toBe(1)
            ->and($captured[0]['exception'])->toBeInstanceOf(ExceptionInterface::class)
            ->and($captured[1]['key'])->toBe('api')
            ->and($captured[1]['retries'])->toBe(2)
            ->and($captured[1]['exception'])->toBeInstanceOf(ExceptionInterface::class);
    });

    test('closure return value is used as retry options', function (): void {
        $capturedHeaders = [];
        $callCount = 0;

        $mockClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedHeaders, &$callCount): JsonMockResponse {
                ++$callCount;
                $capturedHeaders[] = $options['normalized_headers'];

                if ($callCount === 1) {
                    return new JsonMockResponse([], ['http_code' => 500]);
                }

                return new JsonMockResponse(['ok' => true]);
            },
        );

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    fn(string $key, int $retries, \Throwable $e): array => ['headers' => ['X-Attempt' => '1']],
                    true,
                    true,
                    1,
                ),
            ])
            ->fetch();

        expect($capturedHeaders[0])->not->toHaveKey('x-attempt')
            ->and($capturedHeaders[1]['x-attempt'])->toBe(['X-Attempt: 1']);
    });

    test('closure can return different options per attempt', function (): void {
        $capturedHeaders = [];
        $callCount = 0;

        $mockClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedHeaders, &$callCount): JsonMockResponse {
                ++$callCount;
                $capturedHeaders[] = $options['normalized_headers'];

                if ($callCount <= 2) {
                    return new JsonMockResponse([], ['http_code' => 500]);
                }

                return new JsonMockResponse(['ok' => true]);
            },
        );

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    fn(string $key, int $retries, \Throwable $e): array => ['headers' => ['X-Attempt' => (string) $retries]],
                    true,
                    true,
                    2,
                ),
            ])
            ->fetch();

        expect($capturedHeaders[1]['x-attempt'])->toBe(['X-Attempt: 1'])
            ->and($capturedHeaders[2]['x-attempt'])->toBe(['X-Attempt: 2']);
    });

    test('closure shared across requests sees each key', function (): void {
        $captured = [];
        $callCounts = ['users' => 0, 'orders' => 0];

        $mockClient = new MockHttpClient(
            function (string $method, string $url) use (&$callCounts): JsonMockResponse {
                $endpoint = str_contains($url, '/users') ? 'users' : 'orders';
                ++$callCounts[$endpoint];

                if ($callCounts[$endpoint] === 1) {
                    return new JsonMockResponse([], ['http_code' => 500]);
                }

                return new JsonMockResponse(['ok' => true]);
            },
        );

        $sharedRetryOptions = function (string $key, int $retries, \Throwable $e) use (&$captured): array {
            $captured[] = ['key' => $key, 'retries' => $retries];

            return [];
        };

        (new BatchHttpClient($mockClient))
            ->request([
                'users' => new RequestConfig(
                    'GET',
                    'https://api.example.com/users',
                    [],
                    $sharedRetryOptions,
                    true,
                    true,
                    1,
                ),
                'orders' => new RequestConfig(
                    'GET',
                    'https://api.example.com/orders',
                    [],
                    $sharedRetryOptions,
                    true,
                    true,
                    1,
                ),
            ])
            ->fetch();

        $keysSeen = array_column($captured, 'key');
        sort($keysSeen);

        expect($captured)->toHaveCount(2)
            ->and($keysSeen)->toBe(['orders', 'users']);
    });
});

describe('user_data rejection', function (): void {
    test('throws InvalidArgumentException when options contain user_data', function (): void {
        $mockClient = new MockHttpClient([
            new JsonMockResponse(['ok' => true]),
        ]);

        expect(
            fn(): array => (new BatchHttpClient($mockClient))
                ->request([
                    'api' => new RequestConfig(
                        'GET',
                        'https://api.example.com/api',
                        ['user_data' => 'anything'],
                    ),
                ])
                ->fetch(),
        )->toThrow(\InvalidArgumentException::class, "must not contain 'user_data'");
    });

    test('throws InvalidArgumentException when retryOptions Closure returns user_data', function (): void {
        $mockClient = new MockHttpClient([
            new JsonMockResponse([], ['http_code' => 500]),
            new JsonMockResponse(['ok' => true]),
        ]);

        expect(
            fn(): array => (new BatchHttpClient($mockClient))
                ->request([
                    'api' => new RequestConfig(
                        'GET',
                        'https://api.example.com/api',
                        [],
                        fn(string $key, int $retries, \Throwable $e): array => ['user_data' => 'nope'],
                        true,
                        true,
                        1,
                    ),
                ])
                ->fetch(),
        )->toThrow(\InvalidArgumentException::class, "must not contain 'user_data'");
    });
});

describe('safety-net catch', function (): void {
    test('rethrows exception thrown from onSuccess callback', function (): void {
        $mockClient = new MockHttpClient([
            new JsonMockResponse(['ok' => true]),
        ]);

        expect(
            fn(): array => (new BatchHttpClient($mockClient))
                ->request([
                    'api' => new RequestConfig('GET', 'https://api.example.com/api'),
                ])
                ->onSuccess(function (string $key, int $retries, $result, ResponseInterface $response): void {
                    throw new \RuntimeException('boom');
                })
                ->fetch(),
        )->toThrow(\RuntimeException::class, 'boom');
    });

    test('no retries are attempted when unexpected exception fires', function (): void {
        $mockClient = new MockHttpClient([
            new JsonMockResponse(['ok' => true]),
            new JsonMockResponse(['ok' => true]),
            new JsonMockResponse(['ok' => true]),
        ]);

        expect(
            fn(): array => (new BatchHttpClient($mockClient))
                ->request([
                    'a' => new RequestConfig('GET', 'https://api.example.com/a', [], [], true, true, 3),
                    'b' => new RequestConfig('GET', 'https://api.example.com/b', [], [], true, true, 3),
                    'c' => new RequestConfig('GET', 'https://api.example.com/c', [], [], true, true, 3),
                ])
                ->onSuccess(function (string $key, int $retries, $result, ResponseInterface $response): void {
                    throw new \RuntimeException('boom');
                })
                ->fetch(),
        )->toThrow(\RuntimeException::class);

        expect($mockClient->getRequestsCount())->toBe(3);
    });
});

describe('decoding errors', function (): void {
    test('rethrows JsonException after exhausting retries (default maxRetries 0)', function (): void {
        $mockClient = new MockHttpClient([
            new MockResponse('not json', ['response_headers' => ['content-type' => 'application/json']]),
        ]);

        expect(
            fn(): array => (new BatchHttpClient($mockClient))
                ->request([
                    'api' => new RequestConfig('GET', 'https://api.example.com/api'),
                ])
                ->fetch(),
        )->toThrow(\JsonException::class);
    });

    test('decoding error triggers retry', function (): void {
        $callCount = 0;

        $mockClient = new MockHttpClient(
            function () use (&$callCount): MockResponse {
                ++$callCount;

                return $callCount === 1
                    ? new MockResponse('not json', ['response_headers' => ['content-type' => 'application/json']])
                    : new JsonMockResponse(['ok' => true]);
            },
        );

        $retryCalls = [];

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    [],
                    true,
                    true,
                    1,
                ),
            ])
            ->onRetry(function (string $key, int $retries, ResponseInterface $failedResponse, Throwable $e, ResponseInterface $retryResponse) use (&$retryCalls): void {
                $retryCalls[] = ['key' => $key, 'retries' => $retries, 'exception' => $e];
            })
            ->fetch();

        expect($mockClient->getRequestsCount())->toBe(2)
            ->and($results['api'])->toBe(['ok' => true])
            ->and($retryCalls)->toHaveCount(1)
            ->and($retryCalls[0]['key'])->toBe('api')
            ->and($retryCalls[0]['retries'])->toBe(1)
            ->and($retryCalls[0]['exception'])->toBeInstanceOf(DecodingExceptionInterface::class);
    });

    test('exhausting retries on decoding error fires onExhausted and stores null', function (): void {
        $mockClient = new MockHttpClient(
            fn(): MockResponse => new MockResponse('not json', ['response_headers' => ['content-type' => 'application/json']]),
        );

        $exhaustedCalls = [];
        $abortCalls = [];

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    [],
                    false,
                    true,
                    2,
                ),
            ])
            ->onExhausted(function (string $key, int $retries, ResponseInterface $response, Throwable $e) use (&$exhaustedCalls): void {
                $exhaustedCalls[] = ['key' => $key, 'retries' => $retries, 'exception' => $e];
            })
            ->onAbort(function (string $key, int $retries, ResponseInterface $response, \Throwable $e) use (&$abortCalls): void {
                $abortCalls[] = ['key' => $key, 'exception' => $e];
            })
            ->fetch();

        expect($results['api'])->toBeNull()
            ->and($mockClient->getRequestsCount())->toBe(3)
            ->and($exhaustedCalls)->toHaveCount(1)
            ->and($exhaustedCalls[0]['key'])->toBe('api')
            ->and($exhaustedCalls[0]['retries'])->toBe(2)
            ->and($exhaustedCalls[0]['exception'])->toBeInstanceOf(DecodingExceptionInterface::class)
            ->and($abortCalls)->toBe([]);
    });
});

describe('parseResponse', function (): void {
    test('return value replaces result in $results', function (): void {
        $mockClient = new MockHttpClient([
            new JsonMockResponse(['data' => ['id' => 1, 'name' => 'Alice'], 'meta' => ['v' => 1]]),
        ]);

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    [],
                    true,
                    true,
                    0,
                    true,
                    fn(string $key, int $retries, $result, ResponseInterface $response) => $result['data'],
                ),
            ])
            ->fetch();

        expect($results['api'])->toBe(['id' => 1, 'name' => 'Alice']);
    });

    test('onSuccess receives the parseResponse-transformed result, not the raw decoded body', function (): void {
        $captured = null;

        $mockClient = new MockHttpClient([
            new JsonMockResponse(['data' => ['id' => 42], 'meta' => ['v' => 1]]),
        ]);

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    [],
                    true,
                    true,
                    0,
                    true,
                    fn(string $key, int $retries, $result, ResponseInterface $response) => $result['data'],
                ),
            ])
            ->onSuccess(function (string $key, int $retries, $result, ResponseInterface $response) use (&$captured): void {
                $captured = $result;
            })
            ->fetch();

        expect($captured)->toBe(['id' => 42]);
    });

    test('receives key, decoded result, and response', function (): void {
        $captured = null;

        $mockClient = new MockHttpClient([
            new JsonMockResponse(['id' => 7]),
        ]);

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    [],
                    true,
                    true,
                    0,
                    true,
                    function (string $key, int $retries, $result, ResponseInterface $response) use (&$captured) {
                        $captured = ['key' => $key, 'retries' => $retries, 'result' => $result, 'response' => $response];

                        return $result;
                    },
                ),
            ])
            ->fetch();

        expect($captured['key'])->toBe('api')
            ->and($captured['retries'])->toBe(0)
            ->and($captured['result'])->toBe(['id' => 7])
            ->and($captured['response'])->toBeInstanceOf(ResponseInterface::class);
    });

    test('receives raw string when decodeJson is false', function (): void {
        $captured = null;

        $mockClient = new MockHttpClient([
            new MockResponse('plain body'),
        ]);

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    [],
                    true,
                    false,
                    0,
                    true,
                    function (string $key, int $retries, $result, ResponseInterface $response) use (&$captured) {
                        $captured = $result;

                        return $result;
                    },
                ),
            ])
            ->fetch();

        expect($captured)->toBe('plain body');
    });

    test('receives $retries === 0 on first-attempt success', function (): void {
        $captured = null;

        $mockClient = new MockHttpClient([
            new JsonMockResponse(['ok' => true]),
        ]);

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    [],
                    true,
                    true,
                    0,
                    true,
                    function (string $key, int $retries, $result) use (&$captured) {
                        $captured = $retries;

                        return $result;
                    },
                ),
            ])
            ->fetch();

        expect($captured)->toBe(0);
    });

    test('throwing InvalidResponseException triggers retry', function (): void {
        $callCount = 0;
        $retryCalls = [];
        $parseRetries = [];

        $mockClient = new MockHttpClient(
            function () use (&$callCount): JsonMockResponse {
                ++$callCount;

                return new JsonMockResponse(['attempt' => $callCount]);
            },
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    [],
                    true,
                    true,
                    2,
                    true,
                    function (string $key, int $retries, array $result) use (&$parseRetries): array {
                        $parseRetries[] = $retries;

                        if ($result['attempt'] < 2) {
                            throw new InvalidResponseException('not ready');
                        }

                        return $result;
                    },
                ),
            ])
            ->onRetry(function (string $key, int $retries, ResponseInterface $failedResponse, Throwable $e, ResponseInterface $retryResponse) use (&$retryCalls): void {
                $retryCalls[] = ['key' => $key, 'retries' => $retries, 'exception' => $e];
            })
            ->fetch();

        expect($mockClient->getRequestsCount())->toBe(2)
            ->and($results['api'])->toBe(['attempt' => 2])
            ->and($parseRetries)->toBe([0, 1])
            ->and($retryCalls)->toHaveCount(1)
            ->and($retryCalls[0]['key'])->toBe('api')
            ->and($retryCalls[0]['retries'])->toBe(1)
            ->and($retryCalls[0]['exception'])->toBeInstanceOf(InvalidResponseException::class);
    });

    test('exhausting retries with throwOnExhausted false fires onExhausted and stores null', function (): void {
        $exhaustedCalls = [];
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse(['ok' => true]),
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    [],
                    false,
                    true,
                    2,
                    true,
                    function (): void {
                        throw new InvalidResponseException('always invalid');
                    },
                ),
            ])
            ->onExhausted(function (string $key, int $retries, ResponseInterface $response, $e) use (&$exhaustedCalls): void {
                $exhaustedCalls[] = ['key' => $key, 'retries' => $retries, 'exception' => $e];
            })
            ->fetch();

        expect($mockClient->getRequestsCount())->toBe(3)
            ->and($results['api'])->toBeNull()
            ->and($exhaustedCalls)->toHaveCount(1)
            ->and($exhaustedCalls[0]['key'])->toBe('api')
            ->and($exhaustedCalls[0]['retries'])->toBe(2)
            ->and($exhaustedCalls[0]['exception'])->toBeInstanceOf(InvalidResponseException::class);
    });

    test('exhausting retries with throwOnExhausted true rethrows InvalidResponseException without firing onAbort', function (): void {
        $abortCalls = 0;
        $exhaustedCalls = 0;
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse(['ok' => true]),
        );

        try {
            (new BatchHttpClient($mockClient))
                ->request([
                    'api' => new RequestConfig(
                        'GET',
                        'https://api.example.com/api',
                        [],
                        [],
                        true,
                        true,
                        1,
                        true,
                        function (): void {
                            throw new InvalidResponseException('always invalid');
                        },
                    ),
                ])
                ->onExhausted(function () use (&$exhaustedCalls): void {
                    ++$exhaustedCalls;
                })
                ->onAbort(function () use (&$abortCalls): void {
                    ++$abortCalls;
                })
                ->fetch();

            $this->fail('expected InvalidResponseException');

        } catch (InvalidResponseException $e) {
            expect($e->getMessage())->toBe('always invalid');
        }

        expect($exhaustedCalls)->toBe(1)
            ->and($abortCalls)->toBe(0);
    });

    test('throwing a non-marker exception routes to onAbort', function (): void {
        $aborted = null;
        $mockClient = new MockHttpClient([
            new JsonMockResponse(['ok' => true]),
        ]);

        try {
            (new BatchHttpClient($mockClient))
                ->request([
                    'api' => new RequestConfig(
                        'GET',
                        'https://api.example.com/api',
                        [],
                        [],
                        true,
                        true,
                        0,
                        true,
                        function (): void {
                            throw new \LogicException('unexpected');
                        },
                    ),
                ])
                ->onAbort(function (string $key, int $retries, ResponseInterface $response, \Throwable $e) use (&$aborted): void {
                    $aborted = ['key' => $key, 'exception' => $e];
                })
                ->fetch();
        } catch (\LogicException $e) {
        }

        expect($aborted['key'])->toBe('api')
            ->and($aborted['exception'])->toBeInstanceOf(\LogicException::class);
    });

    test('not invoked when HTTP response is non-2xx', function (): void {
        $parserCalls = 0;
        $mockClient = new MockHttpClient([
            new JsonMockResponse([], ['http_code' => 500]),
        ]);

        (new BatchHttpClient($mockClient))
            ->request([
                'api' => new RequestConfig(
                    'GET',
                    'https://api.example.com/api',
                    [],
                    [],
                    false,
                    true,
                    0,
                    true,
                    function () use (&$parserCalls) {
                        ++$parserCalls;

                        return null;
                    },
                ),
            ])
            ->fetch();

        expect($parserCalls)->toBe(0);
    });

    test('only the failing request retries when one request validates and another does not', function (): void {
        $callCounts = ['ok' => 0, 'flaky' => 0];
        $flakyParseRetries = [];

        $mockClient = new MockHttpClient(
            function (string $method, string $url) use (&$callCounts): JsonMockResponse {
                if (str_contains($url, '/ok')) {
                    ++$callCounts['ok'];

                    return new JsonMockResponse(['fine' => true]);
                }

                ++$callCounts['flaky'];

                return new JsonMockResponse(['attempt' => $callCounts['flaky']]);
            },
        );

        $results = (new BatchHttpClient($mockClient))
            ->request([
                'ok' => new RequestConfig('GET', 'https://api.example.com/ok'),
                'flaky' => new RequestConfig(
                    'GET',
                    'https://api.example.com/flaky',
                    [],
                    [],
                    true,
                    true,
                    2,
                    true,
                    function (string $key, int $retries, array $result) use (&$flakyParseRetries): array {
                        $flakyParseRetries[] = $retries;

                        if ($result['attempt'] < 2) {
                            throw new InvalidResponseException('retry me');
                        }

                        return $result;
                    },
                ),
            ])
            ->fetch();

        expect($callCounts['ok'])->toBe(1)
            ->and($callCounts['flaky'])->toBe(2)
            ->and($flakyParseRetries)->toBe([0, 1])
            ->and($results['ok'])->toBe(['fine' => true])
            ->and($results['flaky'])->toBe(['attempt' => 2]);
    });
});
