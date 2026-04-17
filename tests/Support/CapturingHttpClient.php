<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient\Tests\Support;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class CapturingHttpClient implements HttpClientInterface
{
    /** @var array<string, ResponseInterface> */
    private array $responses = [];

    public function __construct(
        private readonly HttpClientInterface $inner,
    ) {}


    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->responses[$options["user_data"][0]] = $this->inner->request($method, $url, $options);
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return $this;
    }

    public function getResponse(string $key): ?ResponseInterface
    {
        return $this->responses[$key] ?? null;
    }

    public function getResponses(): array
    {
        return $this->responses;
    }
}
