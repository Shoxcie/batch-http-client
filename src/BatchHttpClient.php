<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class BatchHttpClient
{
    private readonly HttpClientInterface $httpClient;

    /** @var array<ResponseInterface> */
    private array $responses = [];

    public function __construct(
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    public function request(array $requestsParams): static
    {
        // start all requests but don't wait yet

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(callable $logger, bool $logOnSuccess = false): array
    {
        // wait and return all the responses
    }
}
