<?php

namespace CCQueue\Jobs;

use CCQueue\Services\UuidGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use CCQueue\Traits\JobInitializationTrait;

abstract class BaseJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels, JobInitializationTrait;

    public function __construct($data = [], $version = 'default', $uuid = null)
    {
        $this->initializeJobData($data, $version, $uuid);
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
            !empty($payload['version']) ? $payload['version'] : 'default', // Provide default value for version,
            !empty($payload['uuid']) ? $payload['uuid'] : null
        );

        return $instance;
    }

    // Enforce that extending classes must implement handle method
    abstract public function handle();
}