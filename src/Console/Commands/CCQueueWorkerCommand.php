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
    protected $stopRequested = false;
    protected $handlesStopSignals = false;
    protected $transactionLevelAtJobStart;
    protected $startedAt;

    public function __construct()
    {
        parent::__construct();

        $this->jobHandlers = config('cc-queue.handlers', []);
        $this->retryLimit = config('cc-queue.retry_limit', 3);
    } 

    public function handle()
    {
        $this->startedAt = $this->now();
        Log::info('CCQueueWorkerCommand started');

        $version = $this->argument('version');

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

        $highCount = 0;
        $highLimit = 5; // Number of high-priority jobs before checking normal/low

        $this->listenForStopSignals();

        while (!$this->shouldStop()) {
            $queueItem = null;

            // Weighted Fair Scheduling: Up to 5 high, then 1 normal, then 1 low, then repeat.
            if ($highCount < $highLimit) {
                // Try high-priority queue first
                $queueItem = $redis->brpop([$queueHigh], 1);
                if ($queueItem) {
                    $highCount++;
                }
            }

            // If no high-priority job this round, try normal
            if (!$queueItem && !$this->shouldStop()) {
                $queueItem = $redis->brpop([$queueNormal], 1);
                if ($queueItem) {
                    $highCount = 0; // Reset high-priority counter
                }
            }

            // If still nothing, try low
            if (!$queueItem && !$this->shouldStop()) {
                $queueItem = $redis->brpop([$queueLow], 1);
                if ($queueItem) {
                    $highCount = 0; // Reset high-priority counter
                }
            }

            // If nothing was found in any queue, sleep briefly and retry
            if (!$queueItem) {
                usleep(500000); // 0.5 second sleep if no job
                continue;
            }
            
            $job = null;
            $payload = null;
            $this->transactionLevelAtJobStart = null;

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

                $this->transactionLevelAtJobStart = $this->transactionLevel();
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
                $this->handleFailedJob($job, $payload, $e, $dispatcher, $retryLimit);
            } catch (\Throwable $e) {
                // PHP 7+ Errors (TypeError etc.) are not Exceptions and would otherwise kill the worker
                // and strand the job in progress; on PHP 5.6 this clause parses but never matches.
                $this->handleFailedJob($job, $payload, $e, $dispatcher, $retryLimit);
            }

            $this->flushLogs();
        }

        $this->restoreDefaultStopSignals();
        $this->info('Worker stopped: no job in progress.');
        Log::info('CCQueueWorkerCommand stopped');
        $this->flushLogs();

        return 0;
    }

    /**
     * On SIGTERM, SIGINT or SIGQUIT the worker finishes the job it is running, takes no other, and exits,
     * so a deploy or scale-in doesn't leave a job in_progress with nothing to requeue it.
     */
    protected function listenForStopSignals()
    {
        if (!function_exists('pcntl_signal')) {
            Log::warning('CCQueueWorkerCommand: pcntl is not loaded, so a stop signal kills the job in progress.');

            return;
        }

        $worker = $this;
        foreach (array(SIGTERM, SIGINT, SIGQUIT) as $signal) {
            pcntl_signal($signal, function () use ($worker) {
                $worker->requestStop();
            });
        }
        $this->handlesStopSignals = true;
    }

    protected function restoreDefaultStopSignals()
    {
        if (!$this->handlesStopSignals) {
            return;
        }

        foreach (array(SIGTERM, SIGINT, SIGQUIT) as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
        $this->handlesStopSignals = false;
    }

    public function requestStop()
    {
        $this->stopRequested = true;
    }

    protected function shouldStop()
    {
        // PHP 5.6 has no async signals: a signal that arrived during a job or a poll is delivered here.
        if ($this->handlesStopSignals) {
            pcntl_signal_dispatch();
        }

        return $this->stopRequested;
    }

    protected function handleFailedJob($job, $payload, $exception, JobDispatcher $dispatcher, $retryLimit)
    {
        $this->rollBackWhatTheJobLeftOpen();

        if (!isset($payload['uuid'])) {
            return;
        }

        $jobUuid = $payload['uuid'];
        $retryAttempt = $dispatcher->retryJob($payload, $retryLimit);
        $currentAttempts = isset($payload['attempts']) ? (int)$payload['attempts'] : 0;
        $attempts = $currentAttempts + 1;

        if (!$retryAttempt) {
            $this->logFailedJob($job, $exception);
            $this->error("Job {$jobUuid} failed permanently after {$attempts} attempts. Error: " . $exception->getMessage());
        } else {
            $this->error("Job {$jobUuid} failed; requeued (attempt {$attempts}). Error: " . $exception->getMessage());
        }
    }

    /**
     * Runs inside the worker's failure handling, so it must not throw: an exception here would end
     * the worker with queued work waiting until supervisor restarts it.
     */
    protected function logFailedJob($job, $exception)
    {
        $jobData = json_decode($job, true);
        $uuid = isset($jobData['uuid']) ? $jobData['uuid'] : null;

        try {
            DB::table('cc_queue_failed_jobs')->insert([
                'uuid' => $uuid,
                'connection' => config('cc-queue.default', 'redis'),
                'queue' => 'cc-queue:' . $this->argument('version') . ':tasks',
                'payload' => $job,
                'exception' => $this->describeException($exception),
                'failed_at' => Carbon::now(),
            ]);
        } catch (\Exception $e) {
            $this->reportUnrecordedFailure($uuid, $e);
        } catch (\Throwable $e) {
            $this->reportUnrecordedFailure($uuid, $e);
        }
    }

    /**
     * getTrace() is avoided because its argument values can be objects that don't encode, or secrets.
     */
    protected function describeException($exception)
    {
        $description = json_encode([
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ], JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $description !== false ? $description : json_encode(['class' => get_class($exception)]);
    }

    protected function reportUnrecordedFailure($uuid, $error)
    {
        $this->reportSafely("Could not record failed job {$uuid}: " . $error->getMessage(), [
            'uuid' => $uuid,
            'error' => $error->getMessage(),
        ]);
    }

    /** Reports through the console and the log; falls back to error_log() if either throws. */
    protected function reportSafely($message, array $context = [])
    {
        try {
            $this->error($message);
            Log::error('CCQueueWorkerCommand: ' . $message, $context);
        } catch (\Exception $e) {
            error_log('CCQueueWorkerCommand: ' . $message);
        } catch (\Throwable $e) {
            error_log('CCQueueWorkerCommand: ' . $message);
        }
    }

    /**
     * A transaction a failed job leaves open makes every later query on the connection fail, the failure
     * record included. Only what the job opened is undone; a transaction its caller already held is kept.
     * Only a connection this process has already opened is looked at, and its PDO only when the connection's
     * own rollback fails, so a job that never used the database doesn't open a connection here.
     */
    protected function rollBackWhatTheJobLeftOpen()
    {
        $startLevel = $this->transactionLevelAtJobStart;
        $connection = $this->openedConnection();
        if ($startLevel === null || $connection === null) {
            return;
        }

        $failed = false;
        try {
            for ($i = 0; $i < 100 && $connection->transactionLevel() > $startLevel; $i++) {
                $connection->rollBack();
            }
        } catch (\Exception $e) {
            $failed = true;
        } catch (\Throwable $e) {
            $failed = true;
        }

        if (!$failed && $this->transactionLevel() === $startLevel) {
            return;
        }

        if ($startLevel > 0) {
            $this->reportSafely('Could not roll back what a failed job left open inside the caller\'s transaction; the connection is left as it is.');

            return;
        }

        // A rollback that throws (a job that ended its transaction on the PDO, or a dead connection) leaves the
        // connection's transaction count above the real state, and every later DB::transaction() would then
        // issue a SAVEPOINT outside a transaction. On Laravel 5.2 nothing public resets the count: disconnect()
        // and purge() refuse while it is above zero.
        if ($this->resyncTransactionCount($connection) === 0) {
            $this->reportSafely('A failed job left the database connection out of step; its transaction state was re-synchronised.');

            return;
        }

        try {
            DB::purge(DB::getDefaultConnection());
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
        if ($this->transactionLevel() === 0) {
            $this->reportSafely('A failed job left the database connection out of step; the connection was reset.');

            return;
        }

        $this->waitOutMinimumUptime();
        $this->requestStop();
        $this->reportSafely('A failed job left the database connection in a state the worker cannot repair; the worker stops after this job so a fresh one starts.');
    }

    /**
     * Brings the connection's transaction count back to zero through its own rollbacks: ends whatever real
     * transaction the job left, opens a fresh one with the savepoints the count implies, and rolls back.
     * Fails, leaving the count as it was, when the connection itself is gone.
     *
     * @return int|null the transaction level afterwards
     */
    protected function resyncTransactionCount($connection)
    {
        try {
            $pdo = $connection->getPdo();
            if ($pdo) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->beginTransaction();
                $grammar = $connection->getQueryGrammar();
                $level = $connection->transactionLevel();
                for ($n = 2; $n <= $level && $n <= 100; $n++) {
                    $pdo->exec($grammar->compileSavepoint('trans' . $n));
                }
                for ($i = 0; $i < 100 && $connection->transactionLevel() > 0; $i++) {
                    $connection->rollBack();
                }
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }

        return $this->transactionLevel();
    }

    /**
     * Supervisor marks a program FATAL, and stops restarting it, once it exits within startsecs a few times in
     * a row, so the worker stays up `cc-queue.min_uptime_before_stop` seconds before it stops itself. A stop
     * signal still ends the wait at once.
     */
    protected function waitOutMinimumUptime()
    {
        $minimum = (float) config('cc-queue.min_uptime_before_stop', 10);
        for ($i = 0; $i < 10000 && !$this->shouldStop(); $i++) {
            $remaining = $minimum - ($this->now() - $this->startedAt);
            if ($remaining <= 0) {
                return;
            }
            $this->pause(min(0.5, $remaining));
        }
    }

    protected function now()
    {
        return microtime(true);
    }

    protected function pause($seconds)
    {
        usleep((int) round($seconds * 1000000));
    }

    /** @return int|null the default connection's transaction level: 0 if this process hasn't opened it, null if unreadable */
    protected function transactionLevel()
    {
        try {
            $connection = $this->openedConnection();

            return $connection ? (int) $connection->transactionLevel() : 0;
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }

        return null;
    }

    /** The default connection if this process has already made it; never makes one. */
    protected function openedConnection()
    {
        try {
            $connections = DB::getConnections();
            $name = DB::getDefaultConnection();
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }

        return isset($connections[$name]) ? $connections[$name] : null;
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
        } catch (\Throwable $e) {
        }
    }
}