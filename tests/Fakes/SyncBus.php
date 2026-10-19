<?php

namespace CCQueue\Tests\Fakes;

class SyncBus
{
    public function dispatchNow($job)
    {
        return $job->handle();
    }
}
