<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;
use Illuminate\Support\Facades\DB;

/** Runs DB::transaction() while the database is down: beginTransaction() counts 1, then the PDO call throws. */
class DeadDatabaseTransactionJob extends BaseJob
{
    public function handle()
    {
        app('db')->pdo->dead = true;

        DB::transaction(function () {
            DB::table('probe')->insert(array('uuid' => $this->uuid));
        });
    }
}
