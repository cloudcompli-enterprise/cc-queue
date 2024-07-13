<?php

namespace CloudCompli\CCQueue;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class LoggerFactory
{
    public static function createConsoleLogger()
    {
        $logger = new Logger('console');
        $logger->pushHandler(new StreamHandler('php://stdout'));

        return $logger;
    }

    public static function createProductionLogger()
    {
        $logger = new Logger('production');
        $filename = __DIR__ . '/logs/production.log';
        $handler = new RotatingFileHandler($filename, 7, Logger::INFO);
        $logger->pushHandler($handler);
        return $logger;
    }
}