<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

function getUrl(ResponseInterface $response): string
{
    /** @var string */
    return $response->getInfo('url');
}

function getTotalTime(ResponseInterface $response): float
{
    /** @var float */
    return $response->getInfo('total_time');
}

function getStatusCode(ResponseInterface $response, bool $throw = false): int
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

/** @return array<string, string[]> */
function getHeaders(ResponseInterface $response, bool $throw = false): array
{
    try {
        /** @var array<string, string[]> */
        $headers = $response->getHeaders();

        return $headers;

    } catch (ExceptionInterface $e) {
        if ($throw) {
            throw $e;
        }
    }

    return [];
}

function getContent(ResponseInterface $response, bool $throw = false): string
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

/** @return mixed */
function getUserData(ResponseInterface $response)
{
    /** @var array{0: string, 1: mixed} */
    $userData = $response->getInfo('user_data');

    return $userData[1];
}
