<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateFailedCCQueueJobsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $connection = config('cc_queue.failed.database');

        Schema::connection($connection)->create(config('cc_queue.failed.table'), function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->jsonb('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->index('payload')->using('gin')->class('jsonb_path_ops');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $connection = config('cc_queue.failed.database');

        Schema::connection($connection)->dropIfExists(config('cc_queue.failed.table'));
    }
}
