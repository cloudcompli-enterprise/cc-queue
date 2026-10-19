<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;

class SucceedingJob extends BaseJob
{
    public static $handled = array();

    public function handle()
    {
        static::$handled[] = $this->uuid;
    }
}
