<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Shoxcie\BatchHttpClient\BatchHttpClient;
use Shoxcie\BatchHttpClient\RequestConfig;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use function Shoxcie\BatchHttpClient\{get_content, get_headers, get_status_code, get_total_time, get_url};

function minify(string $text): string {
    return preg_replace('/\s+/', ' ', trim($text));
}

function simpleLog(
    string $prefix,
    string $key,
    string $url,
    float $duration,
    ?string $exceptionMessage = null,
    ?int $attempt = null,
    ?int $statusCode = null,
    ?array $headers = null,
    ?string $body = null
): void {
    global $scriptStartTime;

    $parts = [
        date('Y-m-d H:i:s') . ' ' . $prefix,
        'Key => ' . $key,
        'Execution Time => ' . round((microtime(true) - $scriptStartTime) * 1000) . 'ms',
        'Request Time => ' . round($duration * 1000) . 'ms',
        'URL => ' . $url,
    ];

    if (isset($exceptionMessage)) {
        $parts[] = 'Exception => ' . explode(' for "', $exceptionMessage)[0];
    }

    if (isset($attempt)) {
        $parts[] = 'Attempt => ' . $attempt;
    }

    if ($statusCode) {
        $parts[] = 'HTTP Code => ' . $statusCode;
    }

    if ($headers) {
        $parts[] = 'Response Headers => ' . json_encode($headers);
    }

    if ($body) {
        $parts[] = 'Response Body => ' . minify($body);
    }

    echo implode(' | ', $parts) . PHP_EOL . PHP_EOL;
}

function logSuccess(string $key, ResponseInterface $response): void
{
    simpleLog(
        'SUCCESS',
        $key,
        get_url($response),
        get_total_time($response),
        null,
        null,
        get_status_code($response),
        get_headers($response),
        get_content($response),
    );
}

function logRetry(string $key, int $attempt, ResponseInterface $response, ExceptionInterface $e, ResponseInterface $retryResponse): void
{
    simpleLog(
        'RETRY',
        $key,
        get_url($response),
        get_total_time($response),
        $e->getMessage(),
        $attempt,
        get_status_code($response),
        get_headers($response),
        get_content($response),
    );
}

/**
 * @param TransportExceptionInterface|HttpExceptionInterface $e
 */
function logExhausted(string $key, ResponseInterface $response, ExceptionInterface $e): void
{
    simpleLog(
        'EXHAUSTED',
        $key,
        get_url($response),
        get_total_time($response),
        $e->getMessage(),
        null,
        get_status_code($response),
        get_headers($response),
        get_content($response),
    );
}

function logAbort(string $key, ResponseInterface $response, Throwable $e): void
{
    simpleLog(
        'ABORT',
        $key,
        get_url($response),
        get_total_time($response),
        $e->getMessage(),
        null,
        get_status_code($response),
        get_headers($response),
        get_content($response),
    );
}

$scriptStartTime = microtime(true);

$client = (new BatchHttpClient())
    ->onSuccess(Closure::fromCallable('logSuccess'))
    ->onRetry(Closure::fromCallable('logRetry'))
    ->onExhausted(Closure::fromCallable('logExhausted'))
    ->onAbort(Closure::fromCallable('logAbort'));

$requestsStartTime = microtime(true);

try {
    $result = $client->request([
        'Request 1' => new RequestConfig('GET', '',
            ['base_uri' => 'https://httpbin.org/delay/7', 'timeout' =>  5, 'max_duration' =>  5],
            ['base_uri' => 'https://httpbin.org/get',     'timeout' =>  1, 'max_duration' =>  1],
            false,
            true,
            1
        ),
        'Request 2' => new RequestConfig('GET', '',
            ['base_uri' => 'https://httpbin.org/delay/7',  'timeout' =>  1, 'max_duration' =>  1],
            ['base_uri' => 'https://httpbin.org/delay/10', 'timeout' =>  2, 'max_duration' =>  2],
            false,
            true,
            1
        ),
    ])->fetch();

} catch (Throwable $e) {
    $result = null;

    throw $e;
}

$requestsDurationMs = round((microtime(true) - $requestsStartTime) * 1000) . 'ms';

echo 'Total time => ' . $requestsDurationMs;

/**
 * RETRY     | Key => Request 2 | Execution Time => 1021ms | Request Time => 1013ms | URL => https://httpbin.org/delay/7  | Exception => Operation timed out after 1013 milliseconds with 0 bytes received | Attempt => 1
 * EXHAUSTED | Key => Request 2 | Execution Time => 3032ms | Request Time => 2011ms | URL => https://httpbin.org/delay/10 | Exception => Operation timed out after 2010 milliseconds with 0 bytes received
 * RETRY     | Key => Request 1 | Execution Time => 5012ms | Request Time => 5005ms | URL => https://httpbin.org/delay/7  | Exception => Operation timed out after 5004 milliseconds with 0 bytes received | Attempt => 1
 * SUCCESS   | Key => Request 1 | Execution Time => 5147ms | Request Time =>  135ms | URL => https://httpbin.org/get      | HTTP Code => 200 | Response Headers => . . .
 * Total time => 5144ms
 */
