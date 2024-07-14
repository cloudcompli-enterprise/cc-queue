<?php

namespace CCQueue\Providers;

use CCQueue\Services\JobDispatcher;
use Illuminate\Support\ServiceProvider;

class CCQueueServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Register the job dispatcher service
        $this->app->singleton(JobDispatcher::class, function ($app) {
            return new JobDispatcher();
        });

        // Merge the package configuration file with the application's published copy.
        $this->mergeConfigFrom(__DIR__.'/../config/cc-queue.php', 'cc-queue');
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if (!function_exists('config_path')) {
            function config_path($path = '')
            {
                return base_path('config') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
            }
        }
        // Define database_path if it doesn't exist
        if (!function_exists('database_path')) {
            function database_path($path = '')
            {
                return base_path('database') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
            }
        }

        // Publish the configuration file
        $this->publishes([
            __DIR__.'/../config/cc-queue.php' => config_path( 'cc-queue.php')
        ], 'config');

        // Publish the migration
        if (!class_exists('CreateFailedCCQueueJobsTable')) {
            $this->publishes([
                __DIR__.'/../database/migrations/2024_01_01_000000_create_failed_cc_queue_jobs_table.php' => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_failed_cc-queue_jobs_table.php'),
            ], 'migrations');
        }

        // Register the commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \CCQueue\Console\Commands\CCQueueWorker::class,
            ]);
        }
    }
}