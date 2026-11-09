<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;
use Illuminate\Support\Facades\DB;

/** Opens a transaction (nested when its data asks), commits on the PDO behind the connection's back, and throws. */
class PdoCommitJob extends BaseJob
{
    public function handle()
    {
        DB::beginTransaction();
        if (!empty($this->data['nested'])) {
            DB::beginTransaction();
        }
        app('db')->getPdo()->commit();

        throw new \RuntimeException('failed after committing on the PDO');
    }
}
