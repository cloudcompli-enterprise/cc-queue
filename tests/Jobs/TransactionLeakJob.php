<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;
use Illuminate\Support\Facades\DB;

/** Opens a transaction, has a statement fail inside it, and throws without rolling back. */
class TransactionLeakJob extends BaseJob
{
    public function handle()
    {
        DB::beginTransaction();
        app('db')->aborted = true;

        throw new \RuntimeException('statement failed inside a transaction');
    }
}
