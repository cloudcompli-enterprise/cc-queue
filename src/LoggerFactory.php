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
        $logDir ='/var/logs/cc-queue';

        // Ensure the logs directory exists
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logger = new Logger('production');
        $handler = new RotatingFileHandler($logDir . '/production.log', 7, Logger::INFO);
        $logger->pushHandler($handler);

        return $logger;
    }
}