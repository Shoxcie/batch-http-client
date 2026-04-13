<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @todo Replace this placeholder with the actual implementation.
 */
final class BatchHttpClient
{
    public function __construct(
        private ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient ??= HttpClient::create();
    }

    public function request(): int
    {
        return 0;
    }
}
