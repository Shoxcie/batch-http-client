<?php

declare(strict_types=1);

use Shoxcie\BatchHttpClient\BatchHttpClient;
use Shoxcie\BatchHttpClient\RequestConfig;
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
