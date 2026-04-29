<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Closure;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class RequestConfig
{
    public function __construct(
        public string         $method,
        public string         $url,
        /** @var array<string, mixed> */
        public array          $options = [],
        /** @var array<string, mixed>|Closure(int, ExceptionInterface|InvalidResponseException): array<string, mixed> */
        public array|Closure  $retryOptions = [],
        public bool           $throwOnExhausted = true,
        public bool           $decodeJson = true,
        public int            $maxRetries = 0,
        public bool           $retryOnTransportException = true,
        /** @var null|Closure(string, int, mixed, ResponseInterface): mixed */
        public ?Closure       $parseResponse = null,
    ) {}
}
