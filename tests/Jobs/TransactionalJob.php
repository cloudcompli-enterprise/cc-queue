<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;
use Illuminate\Support\Facades\DB;

class TransactionalJob extends BaseJob
{
    public static $levelsAtStart = array();

    public function handle()
    {
        static::$levelsAtStart[] = DB::transactionLevel();
        DB::transaction(function () {
            DB::table('probe')->insert(array('uuid' => $this->uuid));
        });
    }
}
