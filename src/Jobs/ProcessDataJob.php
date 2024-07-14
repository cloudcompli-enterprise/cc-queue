<?php

namespace CCQueue\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class ProcessDataJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $uuid;
    protected $data;

    // The version of the queue this job belongs to
    protected $version;

    public function __construct($data, $version)
    {
        // Generate a UUID using Str::uuid() if available, otherwise use Ramsey\Uuid
        if (method_exists(Str::class, 'uuid')) {
            $this->uuid = (string) Str::uuid();
        } else {
            $this->uuid = Uuid::uuid4()->toString();
        }

        $this->data = $data;
        $this->version = $version;
    }

    public function handle()
    {
        // Process the data here
        Log::info('Processing data: ', [
            'uuid' => $this->uuid,
            'data' => $this->data,
        ]);
    }

    public function toArray()
    {
        return [
            'uuid' => $this->uuid,
            'version' => $this->version,
            'data' => $this->data,
        ];
    }

    public static function fromArray(array $payload)
    {
        $instance = new self($payload['data'], $payload['version']);
        $instance->uuid = $payload['uuid'];
        return $instance;
    }
}