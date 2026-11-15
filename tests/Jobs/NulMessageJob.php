<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;

class NulMessageJob extends BaseJob
{
    public function handle()
    {
        throw new \RuntimeException("upstream answered \0 and hung up");
    }
}
