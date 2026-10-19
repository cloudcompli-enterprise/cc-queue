<?php

namespace CCQueue\Tests\Fakes;

use Illuminate\Container\Container;

class TestContainer extends Container
{
    public function runningUnitTests()
    {
        return true;
    }
}
