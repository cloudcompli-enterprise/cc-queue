<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default CC Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may define the default connection that should be used by the
    | CC Queue library. This allows you to manage different connections
    | and switch between them easily.
    |
    */

    'default' => env('CC_QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    */

    'connections' => [

        'redis' => [
            'driver' => 'redis',
            'connection' => env('CC_QUEUE_REDIS_CONNECTION', 'default'),
            'queue' => env('CC_QUEUE_REDIS_QUEUE', 'cc-queue'),
            'retry_after' => 90,
            'block_for' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'database' => env('DB_CONNECTION', 'mysql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Handlers
    |--------------------------------------------------------------------------
    |
    | These mappings define the relationship between job types, actions, and
    | their corresponding job classes. This allows you to dynamically
    | instantiate job classes based on the job type and action.
    |
    */

    'handlers' => [
        'Event' => [
            'action' => 'job class',
        ],
        // Add more job types as needed
    ],

    'retry_limit' => env('CC_QUEUE_RETRY_LIMIT', 3), // Add retry limit
];