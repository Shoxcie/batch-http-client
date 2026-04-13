<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @todo Replace this placeholder with the actual implementation.
 */
final readonly class BatchHttpClient
{
    private HttpClientInterface $httpClient;

    public function __construct(
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @return array<mixed>
     */
    public function request(string $url): array
    {
        $response = $this->httpClient->request('GET', $url);

        return $response->toArray();
    }
}
