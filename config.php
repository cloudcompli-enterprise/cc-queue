<?php

use Predis\Client;

return [
    'redis' => new Client([
        'scheme' => 'tcp',
        'host'   => getenv( 'REDIS_HOST' ) ?: '127.0.0.1',
        'port' => getenv('REDIS_PORT') ?: 6379,
        'database' => getenv('REDIS_DB') ?: 0,
    ]),
    'queue' => 'cc_queue',
];