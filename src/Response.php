<?php

declare(strict_types=1);

namespace Shoxcie\BatchHttpClient;

use Deprecated;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[Deprecated(message: 'use get_url() instead')]
function getUrl(ResponseInterface $response): string
{
    /** @var string */
    return $response->getInfo('url');
}

#[Deprecated(message: 'use get_total_time() instead')]
function getTotalTime(ResponseInterface $response): float
{
    /** @var float */
    return $response->getInfo('total_time');
}

#[Deprecated(message: 'use get_status_code() instead')]
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

/**
 * @return array<string, mixed>
 */
#[Deprecated(message: 'use get_headers() instead')]
function getHeaders(ResponseInterface $response, bool $throw = false): array
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

#[Deprecated(message: 'use get_content() instead')]
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

#[Deprecated(message: "passing custom user_data won't be allowed")]
function getUserData(ResponseInterface $response): mixed
{
    /** @var array{0: string, 1: mixed} */
    $userData = $response->getInfo('user_data');

    return $userData[1];
}

function get_url(ResponseInterface $response): string
{
    /** @var string */
    return $response->getInfo('url');
}

function get_total_time(ResponseInterface $response): float
{
    /** @var float */
    return $response->getInfo('total_time');
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

/** @return array<string, mixed> */
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
