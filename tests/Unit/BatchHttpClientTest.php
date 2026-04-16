<?php

declare(strict_types=1);

use Shoxcie\BatchHttpClient\BatchHttpClient;
use Shoxcie\BatchHttpClient\RequestConfig;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

describe('successful batch requests (2xx)', function (): void {
    test('single request returns result with matching key', function (): void {
        $mockClient = new MockHttpClient([
            new JsonMockResponse(['id' => 1, 'name' => 'Alice']),
        ]);

        $results = new BatchHttpClient($mockClient)
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

        $results = new BatchHttpClient($mockClient)
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

        $results = new BatchHttpClient($mockClient)
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

        $results = new BatchHttpClient($mockClient)
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

        $results = new BatchHttpClient($mockClient)
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

        $results = new BatchHttpClient($mockClient)
            ->request([
                'users' => new RequestConfig('GET', 'https://api.example.com/users', throwOnError: false),
                'failing' => new RequestConfig('GET', 'https://api.example.com/failing', throwOnError: false),
                'orders' => new RequestConfig('GET', 'https://api.example.com/orders', throwOnError: false),
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
            'ok-1' => new RequestConfig('GET', 'https://api.example.com/ok-1', throwOnError: false),
            'not-found' => new RequestConfig('GET', 'https://api.example.com/not-found', throwOnError: false),
            'ok-2' => new RequestConfig('GET', 'https://api.example.com/ok-2', throwOnError: false),
            'error' => new RequestConfig('GET', 'https://api.example.com/error', throwOnError: false),
        ];

        $results = new BatchHttpClient($mockClient)
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

        new BatchHttpClient($mockClient)
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', throwOnError: false, maxRetries: 3),
            ])
            ->fetch();

        expect($mockClient->getRequestsCount())->toBe(4);
    });

    test('does not retry when maxRetries is zero', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        new BatchHttpClient($mockClient)
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', throwOnError: false),
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

        $results = new BatchHttpClient($mockClient)
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', maxRetries: 3),
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

        new BatchHttpClient($mockClient)
            ->request([
                'alpha' => new RequestConfig('GET', 'https://api.example.com/alpha', throwOnError: false, maxRetries: 2),
                'beta' => new RequestConfig('GET', 'https://api.example.com/beta', throwOnError: false, maxRetries: 4),
            ])
            ->fetch();

        expect($callCounts['alpha'])->toBe(3)
            ->and($callCounts['beta'])->toBe(5);
    });
});

describe('throwOnError: true', function (): void {
    test('throws exception after retries exhausted', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        expect(
            fn(): array => new BatchHttpClient($mockClient)
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', maxRetries: 2),
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
            fn(): array => new BatchHttpClient($mockClient)
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api'),
            ])
            ->fetch(),
        )->toThrow(ServerException::class);

        expect($mockClient->getRequestsCount())->toBe(1);
    });

    test('cancels all in-flight requests on failure', function (): void {})->todo();
});

describe('throwOnError: false', function (): void {
    test('failed request returns null in results', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        $results = new BatchHttpClient($mockClient)
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', throwOnError: false),
            ])
            ->fetch();

        expect($results['api'])->toBeNull();
    });

    test('does not throw on failure', function (): void {
        $mockClient = new MockHttpClient(
            fn(): JsonMockResponse => new JsonMockResponse([], ['http_code' => 500]),
        );

        $results = new BatchHttpClient($mockClient)
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', throwOnError: false),
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

        $results = new BatchHttpClient($mockClient)
            ->request([
                'failing' => new RequestConfig('GET', 'https://api.example.com/failing', throwOnError: false),
                'ok-1' => new RequestConfig('GET', 'https://api.example.com/ok-1', throwOnError: false),
                'ok-2' => new RequestConfig('GET', 'https://api.example.com/ok-2', throwOnError: false),
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

        $results = new BatchHttpClient($mockClient)
            ->request([
                'api' => new RequestConfig('GET', 'https://api.example.com/api', throwOnError: false, maxRetries: 2),
            ])
            ->fetch();

        expect($results['api'])->toBeNull()
            ->and($mockClient->getRequestsCount())->toBe(3);
    });
});
