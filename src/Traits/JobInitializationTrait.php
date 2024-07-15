<?php

namespace CCQueue\Traits;

use CCQueue\Services\UuidGenerator;

trait JobInitializationTrait
{
    public $uuid;
    public $data;
    public $version;

    public function initializeJobData($data, $version = 'default')
    {
        $this->uuid = UuidGenerator::generate();
        $this->data = $data;
        $this->version = $version;
    }

}