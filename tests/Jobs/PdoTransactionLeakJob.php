<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;

/** Begins a transaction on the PDO directly, outside the connection's counter, and throws inside it. */
class PdoTransactionLeakJob extends BaseJob
{
    public function handle()
    {
        app('db')->getPdo()->open = true;
        app('db')->aborted = true;

        throw new \RuntimeException('statement failed inside a PDO transaction');
    }
}
