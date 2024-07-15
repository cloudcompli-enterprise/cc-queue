<?php

namespace CCQueue\Services;

use Illuminate\Support\Facades\Redis;
use CCQueue\Jobs\ProcessDataJob;
class JobDispatcher
{
    /**
     * Enqueue a job to the default queue.
     *
     * @param array $data
     * @return void
     */
    public function enqueueJob(array $data)
    {
        $job = new ProcessDataJob($data, 'default');
        $payload = json_encode($job->toArray());
        Redis::lpush('cc-queue:default:tasks', $payload);
    }

    /**
     * Enqueue a job to a custom queue.
     *
     * @param array $data
     * @param string $version
     * @return void
     */
    public function enqueueCustomJob(array $data, $version='default')
    {
        $job = new ProcessDataJob($data, $version);
        $payload = json_encode($job->toArray());
        Redis::lpush('cc-queue:' . $version . ':tasks', $payload);
    }

    /**
     * Enqueue a job to the node queue.
     *
     * @param array $data
     * @return void
     */
    public function enqueueNodeJob(array $data)
    {
        $job = new ProcessDataJob($data, 'node');
        $payload = json_encode($job->toArray());
        Redis::lpush('cc-queue:node:tasks', $payload);
    }

    /**
     * Enqueue a job to the legacy queue.
     *
     * @param array $data
     * @return void
     */
    public function enqueueLegacyJob(array $data)
    {
        $job = new ProcessDataJob($data, 'legacy');
        $payload = json_encode($job->toArray());
        Redis::lpush('cc-queue:legacy:tasks', $payload);
    }
}