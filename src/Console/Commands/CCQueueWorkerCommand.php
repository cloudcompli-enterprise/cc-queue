<?php

namespace CCQueue\Console\Commands;

use Carbon\Carbon;
use CCQueue\Services\JobDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CCQueueWorkerCommand extends Command
{
    protected $signature = 'cc-queue:worker {--version=default}';
    protected $description = 'Process jobs from the CC Queue with priority and retry limit support';

    public function handle()
    {
        $version = $this->argument('version');
        $redis = Redis::connection();

        // Define Redis keys for different priority queues.
        $queueHigh = 'cc-queue:' . $version . ':tasks:high';
        $queueNormal = 'cc-queue:' . $version . ':tasks';
        $queueLow = 'cc-queue:' . $version . ':tasks:low';

        $dispatcher = new JobDispatcher();
        $retryLimit = config('cc-queue.retry_limit', 3);

        $this->info("Worker started on queues: [$queueHigh, $queueNormal, $queueLow]");

        while (true) {
            $job = $redis->brpop([$queueHigh, $queueNormal, $queueLow], 5);

            try {
                if (!$job) {
                    usleep(500000); // 0.5 second sleep if no job
                    continue;
                }

                $payload = json_decode($job, true);

                if (!$payload || !isset($payload['uuid'])) {
                    $this->error("Invalid job payload received.");
                    continue;
                }

                $jobUuid = $payload['uuid'];
                $jobStatusKey  = 'cc-queue:job:' . $jobUuid;

                // Retrieve the job record.
                $jobRecord = $redis->hgetall($jobStatusKey);
                if (!$jobRecord || empty($jobRecord['uuid'])) {
                    $this->error("Job record not found for UUID: {$jobUuid}");
                    continue;
                }

                $dispatcher->updateJobStatus(
                    $jobUuid,
                    JobDispatcher::STATUS_IN_PROGRESS,
                    $jobRecord['attempts'] ? (int)$jobRecord['attempts'] : 0
                );

                $this->processJob($payload);

                // If processing succeeds, mark the job as "completed".
                $dispatcher->updateJobStatus(
                    $jobUuid,
                    JobDispatcher::STATUS_COMPLETED,
                    $jobRecord['attempts'] ? (int)$jobRecord['attempts'] : 0
                );

                $redis->expire($jobStatusKey, 86400); // expire after 24 hours
            } catch (\Exception $e) {
                // If an exception occurs, attempt to handle retries.
                if (isset($payload['uuid'])) {
                    $jobUuid = $payload['uuid'];
                    $retryAttempt = $dispatcher->retryJob($payload, $retryLimit);
                    $currentAttempts = isset($payload['attempts']) ? (int)$payload['attempts'] : 0;
                    $attempts = $currentAttempts + 1;

                    if (!$retryAttempt) {
                        $this->logFailedJob($job, $e);
                        $this->error("Job {$jobUuid} failed permanently after {$attempts} attempts. Error: " . $e->getMessage());
                    } else {
                        $this->error("Job {$jobUuid} failed; requeued (attempt {$attempts}). Error: " . $e->getMessage());
                    }
                }
            }
        }
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

    /**
     * Process the job based on its type.
     * Extend this method with actual handlers.
     *
     * @param array $job
     * @throws \Exception
     */
    protected function processJob(array $payload)
    {
        $jobUuid = $payload['uuid'] ?? null;
        if (!$jobUuid) {
            throw new \Exception("Job UUID not found in payload.");
        }

        $type = $payload['type'] ?? null;
        $action = $payload['action'] ?? 'default';
        $version = $payload['version'] ?? 'default';

        if (isset($this->jobHandlers[$type][$action])) {
            $jobClass = $this->jobHandlers[$type][$action];
            $jobInstance = new $jobClass($payload['data'], $version, $jobUuid);
            // Dispatch the job immediately.
            Bus::dispatchNow($jobInstance);
        } else {
            throw new \Exception("Unknown job type or action: {$type} / {$action}");
        }
    }
}