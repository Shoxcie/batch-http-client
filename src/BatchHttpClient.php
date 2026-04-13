<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @todo Replace this placeholder with the actual implementation.
 */
final class BatchHttpClient
{
    private readonly HttpClientInterface $httpClient;
    private ?ResponseInterface $response = null;

    public function __construct(
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    public function request(string $url): static
    {
        $this->response = $this->httpClient->request('GET', $url);

        return $this;
    }

    /**
     * @return array<mixed>|null
     */
    public function fetch(): ?array
    {
        return $this->response?->toArray();
    }
}
