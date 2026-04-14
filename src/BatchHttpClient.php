<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final class BatchHttpClient
{
    private readonly HttpClientInterface $httpClient;

    /** @var array<string, ResponseInterface> */
    private array $responses = [];

    /** @var array<string, RequestConfig> */
    private array $configs = [];

    /** @var array<string, int> */
    private array $attempts = [];

    /** @var array<string, float> */
    private array $retryAt = [];

    public function __construct(
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @param array<string, RequestConfig> $requests
     */
    public function request(array $requests): static
    {
        foreach ($requests as $key => $config) {
            $this->configs[$key] = $config;
            $this->attempts[$key] = 0;
            $this->responses[$key] = $this->httpClient->request(
                $config->method,
                $config->url,
                ['user_data' => $key] + $config->options,
            );
        }

        return $this;
    }

    /**
     * @param null|callable(string $url, int $statusCode, float $duration, ?Throwable $exception, array<string, list<string>> $headers, string $body): void $logger
     *
     * @return array<string, mixed>
     */
    public function fetch(?callable $logger = null, bool $logOnSuccess = false): array
    {
        $results = [];

        /** @var array<string, int> */
        $failedStatuses = [];

        while ($this->responses !== [] || $this->retryAt !== []) {
            $this->fireReadyRetries();

            if ($this->responses === []) {
                usleep(1000);

                continue;
            }

            foreach ($this->httpClient->stream($this->responses) as $response => $chunk) {
                try {
                    if ($chunk->isTimeout()) {
                        continue;
                    }

                    if ($chunk->isFirst()) {
                        $key = $this->resolveKey($response);
                        $statusCode = $response->getStatusCode();

                        if ($statusCode < 200 || $statusCode >= 300) {
                            $failedStatuses[$key] = $statusCode;
                        }

                        continue;
                    }

                    if (!$chunk->isLast()) {
                        continue;
                    }

                    $key = $this->resolveKey($response);
                    $config = $this->configs[$key];
                    unset($this->responses[$key]);

                    if (isset($failedStatuses[$key])) {
                        $statusCode = $failedStatuses[$key];
                        unset($failedStatuses[$key]);
                        $this->handleFailedResponse($key, $config, $response, $statusCode, $results, $logger);
                    } else {
                        $results[$key] = $config->decodeJson
                            ? $response->toArray(false)
                            : $response->getContent(false);

                        if ($logOnSuccess && $logger !== null) {
                            $this->callLogger($logger, $response, $response->getStatusCode(), null);
                        }
                    }

                    break;
                } catch (TransportExceptionInterface $e) {
                    $key = $this->resolveKey($response);
                    unset($failedStatuses[$key]);
                    $this->handleTransportError($key, $response, $e, $results, $logger);

                    break;
                }
            }
        }

        $this->reset();

        return $results;
    }

    private function fireReadyRetries(): void
    {
        $now = microtime(true);

        foreach ($this->retryAt as $key => $time) {
            if ($now < $time) {
                continue;
            }

            $config = $this->configs[$key];
            $options = array_replace_recursive($config->options, $config->retryOptions);

            $this->responses[$key] = $this->httpClient->request(
                $config->method,
                $config->url,
                ['user_data' => $key] + $options,
            );

            unset($this->retryAt[$key]);
        }
    }

    private function resolveKey(ResponseInterface $response): string
    {
        $key = $response->getInfo('user_data');
        \assert(\is_string($key));

        return $key;
    }

    /**
     * @param array<string, mixed> $results
     */
    private function handleTransportError(
        string $key,
        ResponseInterface $response,
        TransportExceptionInterface $e,
        array &$results,
        ?callable $logger,
    ): void {
        $config = $this->configs[$key];

        if ($logger !== null) {
            $this->callLogger($logger, $response, 0, $e);
        }

        unset($this->responses[$key]);

        if ($config->retryOnTransportException && $this->attempts[$key] < $config->maxRetries) {
            $this->scheduleRetry($key, $config);

            return;
        }

        if ($config->throwOnError) {
            $this->cancelAll();
            $this->reset();

            throw $e;
        }

        $results[$key] = null;
    }

    /**
     * @param array<string, mixed> $results
     */
    private function handleFailedResponse(
        string $key,
        RequestConfig $config,
        ResponseInterface $response,
        int $statusCode,
        array &$results,
        ?callable $logger,
    ): void {
        $httpException = $this->captureHttpException($response);

        if ($logger !== null) {
            $this->callLogger($logger, $response, $statusCode, $httpException);
        }

        if ($this->attempts[$key] < $config->maxRetries) {
            $this->scheduleRetry($key, $config);

            return;
        }

        if ($config->throwOnError) {
            $this->cancelAll();
            $this->reset();

            if ($httpException instanceof HttpExceptionInterface) {
                throw $httpException;
            }
        }

        $results[$key] = null;
    }

    private function captureHttpException(ResponseInterface $response): ?HttpExceptionInterface
    {
        try {
            $response->getHeaders(true);
        } catch (HttpExceptionInterface $e) {
            return $e;
        }

        return null;
    }

    private function scheduleRetry(string $key, RequestConfig $config): void
    {
        ++$this->attempts[$key];
        $delayMs = $config->initialRetryDelayMs * (2 ** ($this->attempts[$key] - 1));
        $this->retryAt[$key] = microtime(true) + ($delayMs / 1000);
    }

    private function callLogger(
        callable $logger,
        ResponseInterface $response,
        int $statusCode,
        ?Throwable $exception,
    ): void {
        $url = $response->getInfo('url');
        $url = \is_string($url) ? $url : '';
        $totalTime = $response->getInfo('total_time');
        $duration = \is_float($totalTime) ? $totalTime : 0.0;

        try {
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface) {
            $headers = [];
        }

        try {
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            $body = '';
        }

        $logger($url, $statusCode, $duration, $exception, $headers, $body);
    }

    private function cancelAll(): void
    {
        foreach ($this->responses as $response) {
            $response->cancel();
        }
    }

    private function reset(): void
    {
        $this->responses = [];
        $this->configs = [];
        $this->attempts = [];
        $this->retryAt = [];
    }
}
