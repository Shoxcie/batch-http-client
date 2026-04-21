<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Closure;
use InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final class BatchHttpClient
{
    private HttpClientInterface $httpClient;

    /** @var array<string, RequestConfig> */
    private array $configs = [];

    /** @var array<string, ResponseInterface> */
    private array $responses = [];

    /** @var array<string, int> */
    private array $retriesCount = [];

    /** @var array<string, mixed> */
    private array $results = [];

    /** @var null|Closure(string, ResponseInterface): void */
    private ?Closure $onSuccess = null;

    /** @var null|Closure(string, int, ResponseInterface, ExceptionInterface, ResponseInterface): void */
    private ?Closure $onRetry = null;

    /** @var null|Closure(string, ResponseInterface, Throwable): void */
    private ?Closure $onFailure = null;

    public function __construct(
        ?HttpClientInterface $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @param array<string, RequestConfig> $configs
     *
     * @throws InvalidArgumentException if any config's `options` contains the reserved `user_data` key
     */
    public function request(array $configs): self
    {
        if ($this->responses !== []) {
            return $this;
        }

        $this->configs = $configs;

        foreach ($configs as $key => $config) {
            $this->retriesCount[$key] = 0;

            $this->assertNoUserDataOption($config->options, $key);

            $options = ['user_data' => $key] + $config->options;

            $this->responses[$key] = $this->httpClient->request(
                $config->method,
                $config->url,
                $options,
            );
        }

        return $this;
    }

    /** @param Closure(string, ResponseInterface): void $closure */
    public function onSuccess(Closure $closure): self
    {
        $this->onSuccess = $closure;

        return $this;
    }

    /** @param Closure(string, int, ResponseInterface, ExceptionInterface, ResponseInterface): void $closure */
    public function onRetry(Closure $closure): self
    {
        $this->onRetry = $closure;

        return $this;
    }

    /** @param Closure(string, ResponseInterface, Throwable): void $closure */
    public function onFailure(Closure $closure): self
    {
        $this->onFailure = $closure;

        return $this;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException if `retryOptions` contains the reserved `user_data` key
     * @throws TransportExceptionInterface|HttpExceptionInterface if a `throwOnError` request fails after exhausting retries
     */
    public function fetch(): array
    {
        $this->results = [];

        try {
            while ($this->responses !== []) {
                /** @var ResponseInterface $response */
                foreach ($this->httpClient->stream($this->responses) as $response => $chunk) {
                    try {
                        if ($chunk->isFirst()) {
                            $response->getStatusCode();

                        } elseif ($chunk->isLast()) {
                            $key = $this->getKey($response);
                            $decodeJson = $this->configs[$key]->decodeJson;

                            $this->results[$key] = $decodeJson ? $response->toArray() : $response->getContent();

                            if ($this->onSuccess instanceof Closure) {
                                ($this->onSuccess)($key, $response);
                            }

                            unset($this->responses[$key]);
                        }

                    } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
                        if ($this->handleTransportOrHttpException($response, $e)) {
                            break;
                        }
                    }
                }
            }

        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            $this->cancelAll();

            throw $e;

        } catch (Throwable $e) {
            $this->cancelAll();

            if (isset($response)) {
                $key = $this->getKey($response);

                if ($this->onFailure instanceof Closure) {
                    ($this->onFailure)($key, $response, $e);
                }
            }

            throw $e;
        }

        return $this->results;
    }

    /** @param TransportExceptionInterface|HttpExceptionInterface $e */
    private function handleTransportOrHttpException(ResponseInterface $response, ExceptionInterface $e): bool
    {
        $isTransportException = $e instanceof TransportExceptionInterface;

        $key = $this->getKey($response);
        $config = $this->configs[$key];

        if ($isTransportException) {
            $response->cancel();
        }

        unset($this->responses[$key]);

        if (
            (!$isTransportException || $config->retryOnTransportException)
            && $this->retriesCount[$key] < $config->maxRetries
        ) {
            $retryResponse = $this->retry($key, $config, $e);

            if ($this->onRetry instanceof Closure) {
                ($this->onRetry)($key, $this->retriesCount[$key], $response, $e, $retryResponse);
            }

            return true;
        }

        if ($this->onFailure instanceof Closure) {
            ($this->onFailure)($key, $response, $e);
        }

        if ($config->throwOnError) {
            throw $e;
        }

        $this->results[$key] = null;

        return false;
    }

    private function getKey(ResponseInterface $response): string
    {
        /** @var string */
        return $response->getInfo('user_data');
    }

    /**
     * @throws InvalidArgumentException if `$config->retryOptions` contains the reserved `user_data` key
     */
    private function retry(string $key, RequestConfig $config, ExceptionInterface $e): ResponseInterface
    {
        ++$this->retriesCount[$key];

        if ($config->retryOptions instanceof Closure) {
            $retryOptions = ($config->retryOptions)($this->retriesCount[$key], $e);

        } else {
            $retryOptions = $config->retryOptions;
        }

        $this->assertNoUserDataOption($retryOptions, $key);

        $options = array_replace_recursive($config->options, ['user_data' => $key] + $retryOptions);

        return $this->responses[$key] = $this->httpClient->request(
            $config->method,
            $config->url,
            $options,
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws InvalidArgumentException if `$options` contains the reserved `user_data` key
     */
    private function assertNoUserDataOption(array $options, string $key): void
    {
        if (!isset($options['user_data'])) {
            return;
        }

        $this->cancelAll();

        throw new InvalidArgumentException(
            "RequestConfig options must not contain 'user_data' — it's reserved for internal use."
            . " Use the batch key (got key: '{$key}') to correlate responses instead.",
        );
    }

    private function cancelAll(): void
    {
        foreach ($this->responses as $response) {
            $response->cancel();
        }

        $this->responses = [];
    }
}
