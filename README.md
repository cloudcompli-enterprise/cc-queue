# CC Queue

## Description
CC Queue is designed to be a simple wrapper around a redis queue, similar to the default laravel queue system. 
The CC Queue is different because its payload is platform-agnostic.
In this library the payload is a simple JSON object that can be used to pass to any "consumer" that can process the payload.

The redis queue will have the following keys:
- cc-queue:version - the version of the queue (Example: Legacy, Default, Node, etc)
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

### Publish the migration only
```
 php artisan vendor:publish --provider="CCQueue\Providers\CCQueueServiceProvider" --tag=ccqueue-migrations
```

If add the migration database, run the following command:
```
    php artisan migrate
```


## Configuration (Optional)
```
    CC_QUEUE_REDIS_HOST=redis # This is optional
    CC_QUEUE_CONNECTION=redis # Use this to specify the connection to use; Default is redis;
    CC_QUEUE_REDIS_QUEUE=cc-queue # This is optional; (Recommended if you want a unique redis queue name); Default is cc-queue
    CC_FAILED_JOBS_TABLE=cc_queue_failed_jobs # This is optional; 
```
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
