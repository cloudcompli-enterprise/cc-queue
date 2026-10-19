<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;

class TypeErrorJob extends BaseJob
{
    public function handle()
    {
        $this->requiresObject('not an object');
    }

    private function requiresObject(\stdClass $value)
    {
    }
}
