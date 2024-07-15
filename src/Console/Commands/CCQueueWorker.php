<?php

namespace CCQueue\Console\Commands;

use Carbon\Carbon;
use CCQueue\Jobs\ProcessDataJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

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
        return ProcessDataJob::fromArray($payload);
    }

    protected function logFailedJob($job, $exception)
    {
        $jobData = json_decode($job, true);
        $failedJob = [
            'uuid' => json_decode($job, true)['uuid'],
            'connection' => config('cc_queue.default'),
            'queue' => 'cc-queue:' . $this->argument('version') . ':tasks',
            'payload' => json_encode($jobData),
            'exception' => (string) $exception,
            'failed_at' => Carbon::now(),
        ];

        DB::connection(config('cc_queue.failed.database'))
            ->table(config('cc_queue.failed.table'))
            ->insert($failedJob);
    }
}