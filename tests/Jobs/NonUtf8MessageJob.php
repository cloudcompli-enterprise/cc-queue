<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;

class NonUtf8MessageJob extends BaseJob
{
    public function handle()
    {
        throw new \RuntimeException("upstream answered \xB1\xB2");
    }
}
