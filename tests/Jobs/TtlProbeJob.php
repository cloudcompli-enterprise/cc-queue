<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;

/** Records the TTL of its own status hash while it runs, then succeeds, or fails when its data says so. */
class TtlProbeJob extends BaseJob
{
    public static $ttlsDuringJob = array();

    public function handle()
    {
        $ttls = app('redis')->ttls;
        $key = 'cc-queue:jobs:' . $this->uuid;
        static::$ttlsDuringJob[$this->uuid] = isset($ttls[$key]) ? $ttls[$key] : null;

        if (!empty($this->data['fail'])) {
            throw new \RuntimeException('probe job failed on request');
        }
    }
}
