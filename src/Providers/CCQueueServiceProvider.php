<?php

namespace CCQueue\Providers;

use CCQueue\Services\JobDispatcher;
use Illuminate\Foundation\Application as LaravelApplication;
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

        $this->setupConfig($this->app);
        $this->setupMigrations($this->app);

        // Register the commands
        if ($this->app->runningInConsole()) {
            $this->commands([]);
        }
    }

    /**
     * Setup the configuration.
     */
    protected function setupConfig($app)
    {
        $source = realpath(__DIR__.'/../config/cc-queue.php');

        if ($app->runningInConsole()) {
            $this->publishes([
                $source => config_path('cc-queue.php')
            ], 'config');
        }

        $this->mergeConfigFrom($source, 'cc-queue');
    }

    /**
     * Setup the migrations.
     */
    protected function setupMigrations($app)
    {
        $originalFileName = '2024_07_12_000000_create_failed_cc_queue_jobs_table.php';
        $source = realpath(__DIR__.'/../database/migrations/'.$originalFileName);

        if ($app->runningInConsole()) {
            $migrationFile = database_path('migrations' . '/' . $originalFileName);
            if (!file_exists($migrationFile)) {
                $this->publishes([
                    $source => database_path('migrations' . '/' . $originalFileName)
                ], 'migrations');
            }
        }
    }

}