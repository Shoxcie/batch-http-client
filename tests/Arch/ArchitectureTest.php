<?php

declare(strict_types=1);

arch('no debugging functions')
    ->expect('Shoxcie\HttpClient')
    ->not->toUse(['dd', 'dump', 'var_dump', 'die', 'exit']);

arch('all classes are final')
    ->expect('Shoxcie\HttpClient')
    ->classes()
    ->toBeFinal();
