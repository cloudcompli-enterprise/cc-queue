<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;

class ExceptionJob extends BaseJob
{
    public function handle()
    {
        throw new \RuntimeException('job blew up');
    }
}
