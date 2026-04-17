<?php

declare(strict_types=1);

use Shoxcie\BatchHttpClient\Tests\TestCase;

pest()->use(TestCase::class)
    ->in('Unit', 'Feature', 'Arch');
