<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

function get_url(ResponseInterface $response): string
{
    /** @var string */
    return $response->getInfo('url');
}

function get_total_time(ResponseInterface $response): float
{
    /** @var float */
    $totalTime = $response->getInfo('total_time') ?? 0.0;

    return $totalTime;
}

function get_status_code(ResponseInterface $response, bool $throw = false): int
{
    try {
        return $response->getStatusCode();

    } catch (TransportExceptionInterface $e) {
        if ($throw) {
            throw $e;
        }
    }

    return 0;
}

/** @return array<array<string>> */
function get_headers(ResponseInterface $response, bool $throw = false): array
{
    try {
        return $response->getHeaders();

    } catch (ExceptionInterface $e) {
        if ($throw) {
            throw $e;
        }
    }

    return [];
}

function get_content(ResponseInterface $response, bool $throw = false): string
{
    try {
        return $response->getContent();

    } catch (ExceptionInterface $e) {
        if ($throw) {
            throw $e;
        }
    }

    return '';
}
