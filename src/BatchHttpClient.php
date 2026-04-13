<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class BatchHttpClient
{
    private readonly HttpClientInterface $httpClient;

    /** @var array<string, ResponseInterface> */
    private array $responses = [];

    public function __construct(
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @param array<string, RequestConfig> $requestsParams
     */
    public function request(array $requestsParams): static
    {
        // start all requests but don't wait yet

        foreach ($requestsParams as $key => $params) {
            $this->responses[$key] = $this->httpClient->request(
                $params->method,
                $params->url,
                $params->options,
            );
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(?callable $logger = null, bool $logOnSuccess = false): array
    {
        // wait and return all the responses

        $result = [];
        foreach ($this->responses as $key => $response) {
            $result[$key] = $response->toArray();
        }

        $this->responses = [];

        return $result;
    }
}
