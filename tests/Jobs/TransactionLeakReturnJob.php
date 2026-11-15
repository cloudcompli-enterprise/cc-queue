<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;
use Illuminate\Support\Facades\DB;

/** Opens a transaction and returns normally without committing it. */
class TransactionLeakReturnJob extends BaseJob
{
    public function handle()
    {
        DB::beginTransaction();
    }
}
