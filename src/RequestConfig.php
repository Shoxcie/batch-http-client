<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Closure;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final readonly class RequestConfig
{
    public function __construct(
        public string         $method,
        public string         $url,
        /** @var array<string, mixed> */
        public array          $options = [],
        /** @var array<string, mixed>|Closure(int, Throwable): array<string, mixed> */
        public array|Closure  $retryOptions = [],
        public bool           $throwOnError = true,
        public bool           $decodeJson = true,
        public int            $maxRetries = 0,
        public bool           $retryOnTransportException = true,
        /** @var null|Closure(string, mixed, ResponseInterface): mixed */
        public ?Closure       $parseResponse = null,
    ) {}
}
