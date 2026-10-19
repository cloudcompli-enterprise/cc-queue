<?php

use Illuminate\Container\Container;

require __DIR__ . '/../vendor/autoload.php';

// Laravel defines these in illuminate/foundation, which the package does not depend on.
if (!function_exists('app')) {
    function app($abstract = null)
    {
        $container = Container::getInstance();

        return $abstract === null ? $container : $container->make($abstract);
    }
}

if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        return app('config')->get($key, $default);
    }
}
