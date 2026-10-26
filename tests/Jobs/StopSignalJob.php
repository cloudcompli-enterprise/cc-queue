<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;

class StopSignalJob extends BaseJob
{
    public static $handled = array();

    public function handle()
    {
        posix_kill(getmypid(), SIGTERM);
        static::$handled[] = $this->uuid;
    }
}
