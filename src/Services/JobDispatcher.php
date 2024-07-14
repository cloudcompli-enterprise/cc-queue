<?php

namespace CCQueue\Services;

use Illuminate\Support\Facades\Redis;
use CCQueue\Jobs\ProcessDataJob;
class JobDispatcher
{
    public function enqueueJob(array $data, $version='default')
    {
        $job = new ProcessDataJob($data, $version);
        $payload = json_encode($job->toArray());
        Redis::lpush('cc-queue:' . $version . ':tasks', $payload);
    }

    public function enqueueNodeJob(array $data)
    {
        $job = new ProcessDataJob($data, 'node');
        $payload = json_encode([
            'system' => 'node',
            'data' => $job->toArray()
        ]);
        Redis::publish('cc-queue:node:tasks', $payload);
    }

    public function enqueueLegacyJob(array $data)
    {
        $job = new ProcessDataJob($data, 'legacy');
        $payload = json_encode([
            'system' => 'legacy',
            'data' => $job->toArray()
        ]);
        Redis::publish('cc-queue:legacy:tasks', $payload);
    }
}