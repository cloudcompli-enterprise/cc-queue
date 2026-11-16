# CC Queue

## Description
CC Queue is designed to be a simple wrapper around a redis queue, similar to the default laravel queue system. 
The CC Queue is different because its payload is platform-agnostic.
In this library the payload is a simple JSON object that can be used to pass to any "consumer" that can process the payload.

The redis queue will have the following keys:
- cc-queue:version - the version of the queue (Example: Legacy, Default, Node, etc)

Each queue version has a high/default/low priority queue.The json payload priority flag will determine which queue the payload will be placed in.

```
    cc-queue:version
    cc-queue:version:tasks - is the list of the tasks for that version
```


## Installation
Add this package to the laravel project by running the following command:
```
    composer require cloudcompli/cc-queue
```

This package includes a migration that creates the failed jobs table.
To install this package run one of the following commands, which ever is appropriate for your project:

### Publish the package (includes migration and config file)
```
    php artisan vendor:publish --provider="CCQueue\Providers\CCQueueServiceProvider"
```

### Publish only the config file
```
    php artisan vendor:publish --provider="CCQueue\Providers\CCQueueServiceProvider" --tag=config
```

### Publish only the migration file
```
 php artisan vendor:publish --provider="CCQueue\Providers\CCQueueServiceProvider" --tag=migrations
```

### Publish only the command file
```
 php artisan vendor:publish --provider="CCQueue\Providers\CCQueueServiceProvider" --tag=commands
```
NOTE: Command file is required to run the queue worker. (Note the Queue worker can be modified to specific needs)

If add the migration database, run the following command:
```
    php artisan migrate
```

## Configuration (Required)
Set the event handlers in the config file so that the CC Queue knows what to do with the payload.
```
  'handlers' => [
        'Event' => [
            'action' => 'job class',
        ],
        // Add more job types as needed
    ],
  ]
```

## Configuration (Optional Environmental Variables)
```
    CC_QUEUE_REDIS_HOST=redis # This is optional
    CC_QUEUE_CONNECTION=redis # Use this to specify the connection to use; Default is redis;
    CC_QUEUE_REDIS_QUEUE=cc-queue # This is optional; (Recommended if you want a unique redis queue name); Default is cc-queue
    CC_FAILED_JOBS_TABLE=cc_queue_failed_jobs # This is optional;
    CC_QUEUE_RETRY_LIMIT=3 # This is optional; Default is 3; The number or retry attempts before the job is marked as failed.
    CC_QUEUE_MIN_UPTIME_BEFORE_STOP=10 # This is optional; Default is 10; Seconds a worker stays up before it stops itself.
```

`min_uptime_before_stop` applies when the worker stops itself after a failure it cannot repair, such as a database connection left in a broken state, a Redis failure while it was recording a job's failure, or a database it cannot reach while writing a failed job to `cc_queue_failed_jobs`. The worker first stays up this many seconds, so a process supervisor counts the exit as a normal one rather than a crash loop, and then exits so that a fresh worker starts. A job that failed while the database was unreachable is left failed in Redis and is named, with its payload, in the log. A failed-job row the database refuses for any other reason is logged the same way and the worker carries on.


---
# Deployment
Package should be included in a laravel package and deployed with the laravel project. 

Verify the following:
Make sure the migration is run to create the failed jobs table.

Verify the following environment variables are set:
```
    CC_QUEUE_REDIS_HOST= # This is optional
    CC_QUEUE_REDIS_QUEUE= # This is optional; Recommended if you want a unique redis queue name.
```
