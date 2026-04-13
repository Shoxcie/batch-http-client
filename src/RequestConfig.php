<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

final readonly class RequestConfig
{
    public function __construct(
        public string $method,
        public string $url,
        /** @var array<string, mixed> */
        public array  $options                   = [],
        /** @var array<string, mixed> */
        public array  $retryOptions              = [],
        public bool   $throwOnError              = true,
        public bool   $decodeJson                = true,
        public int    $maxRetries                = 0,
        public int    $initialRetryDelayMs       = 0,
        public bool   $retryOnTransportException = true,
    ) {}
}
