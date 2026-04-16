<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\Response\MockResponse;

function describe(string $_label, Closure $tests): void
{
    $tests();
}

class JsonMockResponse extends MockResponse
{
    public function __construct(array $body, array $info = [])
    {
        $info['response_headers']['content-type'] ??= 'application/json';

        parent::__construct(json_encode($body, JSON_THROW_ON_ERROR), $info);
    }
}
