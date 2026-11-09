<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;
use Illuminate\Support\Facades\DB;

/** Opens a transaction and throws after the connection has died, so rolling it back throws too. */
class DeadConnectionJob extends BaseJob
{
    public function handle()
    {
        DB::beginTransaction();
        app('db')->getPdo()->dead = true;

        throw new \RuntimeException('lost the database mid-job');
    }
}
