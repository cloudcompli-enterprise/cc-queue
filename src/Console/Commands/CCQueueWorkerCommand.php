<?php

namespace CCQueue\Console\Commands;

use Carbon\Carbon;
use CCQueue\Services\JobDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CCQueueWorkerCommand extends Command
{
    protected $signature = 'cc-queue:worker {version=default}';
    protected $description = 'Process jobs from the CC Queue with priority and retry limit support';

    /**
     * Job handlers mapping
     * @var array
     */
    protected $jobHandlers = [];
    protected $retryLimit;
    protected $useBusDispatch = true;

    public function __construct()
    {
        parent::__construct();

        $this->jobHandlers = config('cc-queue.handlers', []);
        $this->retryLimit = config('cc-queue.retry_limit', 3);
    } 

    public function handle()
    {
        Log::info('CCQueueWorkerCommand started(LEGACY)');

        $version = $this->argument('version');


        $this->info(json_encode([
            'env' => getenv('APP_ENV'),
            'log' => getenv('APP_LOG'),
            'log_level' => getenv('APP_LOG_LEVEL'),
            // 'user' => get_current_user(),
            'cwd' => getcwd(),
            'php' => phpversion(),
        ], JSON_PRETTY_PRINT));

        /**
         * If the version is default, we use Bus::dispatchNow() - Laravel 6+
         * If the version is legacy, we use direct handle() - Laravel 5.2
         */
        $this->useBusDispatch = strtolower($this->argument('version')) !== 'legacy';
        $redis = Redis::connection();

        // Define Redis keys for different priority queues.
        $queueHigh = 'cc-queue:' . $version . ':tasks:high';
        $queueNormal = 'cc-queue:' . $version . ':tasks';
        $queueLow = 'cc-queue:' . $version . ':tasks:low';

        $dispatcher = new JobDispatcher();
        $retryLimit = config('cc-queue.retry_limit', 3);

        $this->info("Worker started on queues: [$queueHigh, $queueNormal, $queueLow]");

        // Make sure logs are flushed before starting the worker
        $this->flushLogs();

        while (true) {
            $queueItem = $redis->brpop([$queueHigh, $queueNormal, $queueLow], 5);

            try {
                $job = $queueItem[1]; // This is related to brpop array index.
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
                $jobStatusKey  = 'cc-queue:jobs:' . $jobUuid;

                // Retrieve the job record.
                $jobRecord = $redis->hgetall($jobStatusKey);
                if (!$jobRecord || empty($jobRecord['uuid'])) {
                    $this->error("Job record not found for UUID: {$jobUuid}");
                    continue;
                }

                $this->info('Updating job status to in progress: ' . $jobUuid);
                $dispatcher->updateJobStatus(
                    $jobUuid,
                    JobDispatcher::STATUS_IN_PROGRESS,
                    $jobRecord['attempts'] ? (int)$jobRecord['attempts'] : 0
                );

                $this->processJob($payload);

                // If processing succeeds, mark the job as "completed".
                $this->info('Marking job as completed: ' . $jobUuid);
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

            $this->flushLogs();
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
        $jobUuid = $payload['uuid'] ? $payload['uuid'] : null;
        if (!$jobUuid) {
            throw new \Exception("Job UUID not found in payload.");
        }

        $type = isset($payload['type']) ? $payload['type'] : null;
        $action = isset($payload['action']) ? $payload['action'] : 'default';
        $version = isset($payload['version']) ? $payload['version'] : $this->argument('version');

        $this->info('Processing job: ' . $jobUuid);

        if (isset($this->jobHandlers[$type][$action])) {
            $jobClass = $this->jobHandlers[$type][$action];
            $jobInstance = new $jobClass($payload['data'], $version, $jobUuid);
            $this->info('Executing job: ' . $jobUuid);
            // Dispatch the job immediately.
            if ($this->useBusDispatch) {
                Bus::dispatchNow($jobInstance);
            } else {
                $jobInstance->handle();
            }
        } else {
            throw new \Exception("Unknown job type or action: {$type} / {$action}");
        }
    }

    /**
     * Force flush logs to disk immediately
     */
    private function flushLogs()
    {
        try {
            // Flush Laravel's log
            if (app()->bound('log')) {
                $logger = app('log');
                if (method_exists($logger, 'getMonolog')) {
                    $monolog = $logger->getMonolog();
                    if (method_exists($monolog, 'close')) {
                        $monolog->close();
                    }
                }
            }

            // Also flush PHP's error log buffer
            error_log('', 4);
        } catch (\Exception $e) {
            // Silently handle any flushing errors
        }
    }
}