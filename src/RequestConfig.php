<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Closure;
use Throwable;

final class RequestConfig
{
    public string $method;
    public string $url;
    /** @var array<string, mixed> */
    public array $options = [];
    /** @var array<string, mixed>|Closure(int, Throwable): array<string, mixed> */
    public $retryOptions = [];
    public bool $throwOnError = true;
    public bool $decodeJson = true;
    public int $maxRetries = 0;
    public bool $retryOnTransportException = true;

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed>|Closure(int, Throwable): array<string, mixed> $retryOptions
     */
    public function __construct(
        string $method,
        string $url,
        array $options = [],
        $retryOptions = [],
        bool $throwOnError = true,
        bool $decodeJson = true,
        int $maxRetries = 0,
        bool $retryOnTransportException = true
    ) {
        $this->method = $method;
        $this->url = $url;
        $this->options = $options;
        $this->retryOptions = $retryOptions;
        $this->throwOnError = $throwOnError;
        $this->decodeJson = $decodeJson;
        $this->maxRetries = $maxRetries;
        $this->retryOnTransportException = $retryOnTransportException;
    }
}
