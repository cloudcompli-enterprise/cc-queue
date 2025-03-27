<?php

namespace CCQueue\Services;

use CCQueue\Jobs\ProcessDataJob;
use Illuminate\Support\Facades\Redis;

class JobDispatcher
{
    protected $defaultVersion = 'default';

    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /**
     * Enqueue a job
     *
     * The $payload must include a 'type' and 'data' key.
     *
     * @param array $payload
     * @param string|null $version
     * @param int|null $expire The number of seconds until the job expires.
     * @return void
     */
    public function enqueueJob(array $payload, $version = null, $expire = null)
    {
        $version = $version ?: $this->defaultVersion;

        // Set the default expire status to 24 hours. (To prevent memory leaks)
        $expireLength= is_null($expire) ? $expire : config('cc-queue.expire_jobs', 86400);

        if (empty($payload['uuid'])) {
            $payload['uuid'] = (string)UuidGenerator::generate();
        }

        $priority = !empty($payload['priority']) ? strtolower($payload['priority']) : 'normal';

        $job = [
            'type' => $payload['type'] ? $payload['type'] : '',
            'uuid' => $payload['uuid'],
            'version' => $version,
            'data' => json_encode(!empty($payload) ? $payload['data'] : []),
            'status' => self::STATUS_PENDING, // pending, in_progress, completed, failed
            'attempts' => 0,
            'priority' => $priority,
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $jobKey = 'cc-queue:jobs:' . $payload['uuid'];
        Redis::hmset($jobKey, $job);
        // Help prevent memory leaks by expiring the job after a certain amount of time.
        if ($expireLength > 0) {
            Redis::expire($key, $expireLength);
        }
        Redis::lpush($this->getQueueKey($version, $priority), json_encode($payload));

        return $payload['uuid'];
    }

    private function getQueueKey($version, $priority = 'normal')
    {
        $version = $version ? $version : $this->defaultVersion;

        switch ($priority) {
            case 'high':
                return 'cc-queue:' . $version . ':tasks:high';
            case 'low':
                return 'cc-queue:' . $version . ':tasks:low';
            default:
                return 'cc-queue:' . $version . ':tasks';
        }
    }

    /**
     * Enqueue a job to a custom queue.
     *
     * @param array $data
     * @param string $version
     * @return void
     */
    public function enqueueCustomJob(array $payload, $version = 'default')
    {
        return $this->enqueueJob($payload, $version);
    }

    /**
     * Enqueue a job to the node queue.
     *
     * @param array $data
     * @return void
     */
    public function enqueueNodeJob(array $payload)
    {
        return $this->enqueueJob($payload, 'node');
    }

    /**
     * Enqueue a job to the legacy queue.
     *
     * @param array $data
     * @return void
     */
    public function enqueueLegacyJob(array $payload)
    {
        return $this->enqueueJob($payload, 'legacy');
    }

    public function getJobStatus($uuid)
    {
        $key = 'cc-queue:jobs:' . $uuid;
        return Redis::hgetall($key);
    }

    /**
     * Update the status (and optionally the attempt count) of a job.
     *
     * @param string $uuid
     * @param string $status
     * @param int|null $attempts
     * @param int|null $expire The number of seconds until the job expires.
     */
    public function updateJobStatus($uuid, $status, $attempts = null, $expire = null)
    {
        // Set the default expire status to 24 hours.
        $expireLength= is_null($expire) ? $expire : config('cc-queue.expire_status', 86400);

        $key = 'cc-queue:jobs:' . $uuid;
        $fields = [
            'status' => $status,
            'updated_at' => date('c')
        ];
        if (!is_null($attempts)) {
            $fields['attempts'] = $attempts;
        }

        Redis::hmset($key, $fields);

        // Help prevent memory leaks by expiring the job after a certain amount of time.
        if ($expireLength > 0) {
            Redis::expire($key, $expireLength);
        }
    }

    /**
     * Retry the job based on its current payload and retry limit.
     *
     * This method increments the attempt count and requeues the job on the appropriate priority queue.
     * Returns true if job was successful or false if the job has reached the retry limit.
     *
     * @param array $payload
     * @param int|null $retryLimit
     * @return bool
     */
    public function retryJob(array $payload, $retryLimit = null)
    {
        $retryLimit = $retryLimit ? config('cc-queue.retry_limit', 3) : $retryLimit;
        $jobUuid = $payload['uuid'];

        $currentAttempts = isset($payload['attempts']) ? (int)$payload['attempts'] : 0;
        $attempts = $currentAttempts + 1;
        if ($attempts < $retryLimit) {
            // Update status to pending with incremented attempts.
            $this->updateJobStatus($jobUuid, self::STATUS_PENDING, $attempts);
            $updatedPayload = array_merge($payload, ['attempts' => $attempts]);
            $priority = isset($payload['priority']) ? strtolower($payload['priority']) : 'normal';
            $version = isset($payload['version']) ? $payload['version'] : $this->defaultVersion;
            $queueKey = $this->getQueueKey($version, $priority);
            Redis::lpush($queueKey, json_encode($updatedPayload));
            return true; // job requeued
        } else {
            $this->updateJobStatus($jobUuid, self::STATUS_FAILED, $attempts);
            return false; // job permanently failed
        }
    }
}