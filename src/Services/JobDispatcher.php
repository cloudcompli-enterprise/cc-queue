<?php

namespace CCQueue\Services;

use Illuminate\Support\Facades\Redis;
use CCQueue\Jobs\ProcessDataJob;
class JobDispatcher
{
    public function enqueueJob(array $payload)
    {
        Redis::lpush('cc-queue:default:tasks', json_encode($payload));
    }

    /**
     * Enqueue a job to a custom queue.
     *
     * @param array $data
     * @param string $version
     * @return void
     */
    public function enqueueCustomJob(array $payload, $version='default')
    {
        Redis::lpush('cc-queue:' . $version . ':tasks', json_encode($payload));
    }

    /**
     * Enqueue a job to the node queue.
     *
     * @param array $data
     * @return void
     */
    public function enqueueNodeJob(array $payload)
    {
        Redis::lpush('cc-queue:node:tasks', json_encode($payload));
    }

    /**
     * Enqueue a job to the legacy queue.
     *
     * @param array $data
     * @return void
     */
    public function enqueueLegacyJob(array $payload)
    {
        Redis::lpush('cc-queue:legacy:tasks', json_encode($payload));
    }
}