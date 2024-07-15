<?php

namespace CCQueue\Facades;

use Illuminate\Support\Facades\Facade;

class CCQueueDispatcher extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \CCQueue\Services\JobDispatcher::class;
    }
}