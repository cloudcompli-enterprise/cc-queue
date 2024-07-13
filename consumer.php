<?php

require __DIR__ . '/vendor/autoload.php';

use CloudCompli\CCQueue\CCQueueManager;
use CloudCompli\CCQueue\LoggerFactory;

// Create a production logger instance
$logger = LoggerFactory::createProductionLogger();
$logger->info('Logger initialized successfully');

// Load Configuration
$config = require __DIR__ . '/config.php';

// Initialize the queue manager
$queueManager = new CCQueueManager($config['redis'], $config['queue'], $logger);
$consumer = $queueManager->getConsumer();

$logger->info('Initialized Queue');
while (true) {
    $message = $consumer->consume();
    if ($message) {
        $logger->info('Consumed message', $message);
    } else {
        sleep(1); // Sleep for a while if no message
    }
}