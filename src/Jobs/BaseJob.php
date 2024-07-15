<?php

namespace CCQueue\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use CCQueue\Traits\JobInitializationTrait;

abstract class BaseJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels, JobInitializationTrait;

    public function __construct($data = [], $version = 'default')
    {
        $this->initializeJobData($data, $version);
    }

    public function toArray()
    {
        return [
            'type' => static::class,
            'uuid' => $this->uuid,
            'version' => $this->version,
            'data' => $this->data,
        ];
    }

    public static function fromArray(array $payload)
    {
        $instance = new static(
            !empty($payload['data']) ? $payload['data'] : [],
            !empty($payload['version']) ? $payload['version'] : 'default' // Provide default value for version
        );
        $instance->uuid = $payload['uuid'];
        return $instance;
    }

    // Enforce that extending classes must implement handle method
    abstract public function handle();
}