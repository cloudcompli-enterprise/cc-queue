<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateFailedCcQueueJobsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cc_queue_failed_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            // NOTE: Using jsonb column for legacy (postgres support), newer versions of Laravel support this natively (->json('payload'))
            $table->jsonb('payload');
            $table->jsonb('exception'); // Using jsonb for the exception
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
        Schema::dropIfExists('cc_queue_failed_jobs');
    }
}
