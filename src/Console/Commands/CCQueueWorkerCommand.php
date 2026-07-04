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
        Log::info('CCQueueWorkerCommand started');

        $version = $this->argument('version');

        /**
         * If the version is default, we use Bus::dispatchNow() - Laravel 6+
         * If the version is legacy, we use direct handle() - Laravel 5.2
         */
        $this->useBusDispatch = strtolower($this->argument('version')) !== 'legacy';

        // Define Redis keys for different priority queues.
        $queueHigh = 'cc-queue:' . $version . ':tasks:high';
        $queueNormal = 'cc-queue:' . $version . ':tasks';
        $queueLow = 'cc-queue:' . $version . ':tasks:low';

        $dispatcher = new JobDispatcher();
        $retryLimit = config('cc-queue.retry_limit', 3);
        $workerId = $this->buildWorkerId();
        $heartbeatTtl = 300;
        $heartbeatKey = $this->getHeartbeatKey($version, $workerId);
        $processingQueues = [
            'high' => $this->getProcessingQueueKey($version, 'high', $workerId),
            'normal' => $this->getProcessingQueueKey($version, 'normal', $workerId),
            'low' => $this->getProcessingQueueKey($version, 'low', $workerId),
        ];
        $queueMap = [
            'high' => $queueHigh,
            'normal' => $queueNormal,
            'low' => $queueLow,
        ];

        $this->startHeartbeatRefresher($heartbeatKey, $heartbeatTtl);
        $redis = Redis::connection();

        $this->info("Worker started on queues: [$queueHigh, $queueNormal, $queueLow]");

        // Make sure logs are flushed before starting the worker
        $this->flushLogs();

        $this->refreshHeartbeat($redis, $heartbeatKey, $heartbeatTtl);
        $this->recoverOrphanedProcessingJobs($redis, $dispatcher, $version);

        /*
         * Jobs are atomically moved from the source queue into a per-worker
         * processing list before execution. A forked refresher keeps the heartbeat
         * alive during long handlers; startup and periodic recovery only requeue
         * processing lists whose owner heartbeat has expired.
         */
        $prioritySchedule = ['high', 'high', 'high', 'high', 'high', 'normal', 'low'];
        $priorityScheduleIndex = 0;
        $recoveryInterval = 30;
        $lastRecoveryAt = time();

        while (true) {
            $this->refreshHeartbeat($redis, $heartbeatKey, $heartbeatTtl);

            if (time() - $lastRecoveryAt >= $recoveryInterval) {
                $this->recoverOrphanedProcessingJobs($redis, $dispatcher, $version);
                $lastRecoveryAt = time();
            }

            $scheduledPriority = $prioritySchedule[$priorityScheduleIndex];
            $queueItem = $this->popNextJob(
                $redis,
                $queueMap,
                $processingQueues,
                $scheduledPriority
            );

            // If nothing was found in any queue, sleep briefly and retry
            if (!$queueItem) {
                usleep(500000); // 0.5 second sleep if no job
                continue;
            }

            $priorityScheduleIndex++;
            if ($priorityScheduleIndex >= count($prioritySchedule)) {
                $priorityScheduleIndex = 0;
            }
            
            $job = null;
            $payload = null;
            $processingQueueKey = $queueItem['processing_queue'];

            try {
                $job = $queueItem['job'];
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
            } finally {
                if ($processingQueueKey && $job) {
                    $redis->lrem($processingQueueKey, 1, $job);
                }
            }

            $this->flushLogs();
        }
    }

    protected function buildWorkerId()
    {
        $host = function_exists('gethostname') ? gethostname() : 'unknown-host';
        $host = preg_replace('/[^A-Za-z0-9_.-]/', '-', $host);

        return $host . '-' . getmypid() . '-' . str_replace('.', '-', uniqid('', true));
    }

    protected function refreshHeartbeat($redis, $heartbeatKey, $heartbeatTtl)
    {
        $redis->setex($heartbeatKey, $heartbeatTtl, (string)time());
    }

    protected function startHeartbeatRefresher($heartbeatKey, $heartbeatTtl)
    {
        if (!function_exists('pcntl_fork')) {
            return null;
        }

        $parentPid = getmypid();
        $pid = pcntl_fork();
        if ($pid === -1) {
            return null;
        }

        if ($pid > 0) {
            return $pid;
        }

        $interval = max(1, (int)floor($heartbeatTtl / 3));
        while (true) {
            if (function_exists('posix_kill') && !posix_kill($parentPid, 0)) {
                exit(0);
            }

            try {
                $redis = Redis::connection();
                $this->refreshHeartbeat($redis, $heartbeatKey, $heartbeatTtl);
            } catch (\Exception $e) {
                // Heartbeat refresh failures are retried on the next interval.
            }

            sleep($interval);
        }
    }

    protected function getHeartbeatKey($version, $workerId)
    {
        return 'cc-queue:' . $version . ':workers:' . $workerId . ':heartbeat';
    }

    protected function getProcessingQueueKey($version, $priority, $workerId)
    {
        return 'cc-queue:' . $version . ':processing:' . $priority . ':' . $workerId;
    }

    protected function popNextJob($redis, array $queueMap, array $processingQueues, $scheduledPriority)
    {
        $priorities = $this->getPriorityOrder($scheduledPriority);

        foreach ($priorities as $priority) {
            $job = $redis->rpoplpush($queueMap[$priority], $processingQueues[$priority]);
            if ($job) {
                return [
                    'job' => $job,
                    'processing_queue' => $processingQueues[$priority],
                ];
            }
        }

        return null;
    }

    protected function getPriorityOrder($scheduledPriority)
    {
        $priorities = [$scheduledPriority];

        foreach (['high', 'normal', 'low'] as $priority) {
            if ($priority !== $scheduledPriority) {
                $priorities[] = $priority;
            }
        }

        return $priorities;
    }

    protected function recoverOrphanedProcessingJobs($redis, JobDispatcher $dispatcher, $version)
    {
        $prefix = 'cc-queue:' . $version . ':processing:';
        $processingKeys = $redis->keys($prefix . '*');

        foreach ($processingKeys as $processingKey) {
            $metadata = $this->parseProcessingQueueKey($processingKey, $prefix);
            if (!$metadata) {
                continue;
            }

            $heartbeatKey = $this->getHeartbeatKey($version, $metadata['worker_id']);
            if ($redis->exists($heartbeatKey)) {
                continue;
            }

            $queueKey = $dispatcher->getQueueKey($version, $metadata['priority']);
            while ($redis->rpoplpush($processingKey, $queueKey)) {
                // Move every orphaned entry back to its source priority queue.
            }
        }
    }

    protected function parseProcessingQueueKey($processingKey, $prefix)
    {
        if (strpos($processingKey, $prefix) !== 0) {
            return null;
        }

        $remainder = substr($processingKey, strlen($prefix));
        $parts = explode(':', $remainder, 2);
        if (count($parts) !== 2) {
            return null;
        }

        if (!in_array($parts[0], ['high', 'normal', 'low'])) {
            return null;
        }

        return [
            'priority' => $parts[0],
            'worker_id' => $parts[1],
        ];
    }

    protected function logFailedJob($job, $exception)
    {
        // Bookkeeping only - a logging failure must never kill the worker loop.
        try {
            $jobData = json_decode($job, true);
            $failedJob = [
                'uuid' => json_decode($job, true)['uuid'],
                'connection' => config('cc_queue.default'),
                'queue' => 'cc-queue:' . $this->argument('version') . ':tasks',
                'payload' => json_encode($jobData),
                // getTraceAsString(): raw getTrace() args can hold objects and
                // closures that json_encode cannot serialize.
                'exception' => json_encode([
                    'message' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString()
                ]),
                'failed_at' => Carbon::now(),
            ];

            DB::table('cc_queue_failed_jobs')->insert($failedJob);
        } catch (\Exception $logError) {
            $this->error('Failed to record failed job: ' . $logError->getMessage());
        }
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