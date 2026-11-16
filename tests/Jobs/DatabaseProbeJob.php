<?php

namespace CCQueue\Tests\Jobs;

use CCQueue\Jobs\BaseJob;
use Illuminate\Support\Facades\DB;

class DatabaseProbeJob extends BaseJob
{
    public static $handled = array();

    public function handle()
    {
        DB::table('probe')->insert(array('uuid' => $this->uuid));
        static::$handled[] = $this->uuid;
    }
}
