<?php

namespace CCQueue\Tests\Fakes;

class NullLogger
{
    public function __call($method, $arguments)
    {
    }
}
