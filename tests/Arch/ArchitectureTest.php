<?php

declare(strict_types=1);

arch('no debugging functions')
    ->expect('Shoxcie\BatchHttpClient')
    ->not->toUse(['dd', 'dump', 'var_dump', 'die', 'exit']);

arch('all classes are final')
    ->expect('Shoxcie\BatchHttpClient')
    ->classes()
    ->toBeFinal();
