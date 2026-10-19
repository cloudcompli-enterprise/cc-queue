<?php

namespace CCQueue\Tests\Fakes;

use Illuminate\Support\Arr;

class FakeConfig
{
    private $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function get($key, $default = null)
    {
        return Arr::get($this->items, $key, $default);
    }
}
