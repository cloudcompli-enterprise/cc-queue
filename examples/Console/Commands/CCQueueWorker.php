<?php

namespace CCQueue\Examples\Console\Commands;

use Carbon\Carbon;
use CCQueue\Jobs\ProcessDataJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class CCQueueWorker extends Command
{
    protected $signature = 'cc-queue:worker {version}';
    protected $description = 'Custom queue worker for handling jobs from Redis';

    public function handle()
    {
        $version = $this->argument('version');
        $redis = Redis::connection();

        while (true) {
            $job = $redis->rpop('cc-queue:' . $version . ':tasks');
            if ($job) {
                $payload = json_decode($job, true);
                $jobInstance = $this->deserializeJob($payload);
                try {
                    Bus::dispatch($jobInstance);
                } catch (\Exception $e) {
                    // Log the failed job to the database
                    $this->logFailedJob($job, $e);

                    // Handle the exception and re-enqueue the job if necessary
                    $redis->lpush('cc-queue:' . $version . ':failed', $job);
                }
            } else {
                sleep(1); // Sleep for a while if no job is available
            }
        }
    }

    protected function deserializeJob(array $payload)
    {
        $type = $payload['type'] ? $payload['type'] : null;
        $action = $payload['action'] ? $payload['action'] : null;
        $version = $payload['version'] ? $payload['version'] : 'default';

        if (isset($this->jobHandlers[$type][$action])) {
            $jobClass = $this->jobHandlers[$type][$action];
            return new $jobClass($payload['data'], $version);
        }

        throw new \Exception("Unknown job type or action: {$type} / {$action}");
    }

    protected function logFailedJob($job, $exception)
    {
        $jobData = json_decode($job, true);
        $failedJob = [
            'uuid' => json_decode($job, true)['uuid'],
            'connection' => config('cc_queue.default'),
            'queue' => 'cc-queue:' . $this->argument('version') . ':tasks',
            'payload' => json_encode($jobData),
            'exception' => json_encode([
                'message' => $exception->getMessage(),
                'trace' => $exception->getTrace()
            ]), // Store exception as JSON string
            'failed_at' => Carbon::now(),
        ];

        DB::table('cc_queue_failed_jobs')->insert($failedJob);
    }
}