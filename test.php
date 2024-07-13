<?php

require __DIR__ . '/vendor/autoload.php';

use CloudCompli\CCQueue\CCQueueManager;
use CloudCompli\CCQueue\CCQueueMessage;
use CloudCompli\CCQueue\LoggerFactory;

$config = require __DIR__ . '/config.php';

$logger = LoggerFactory::createConsoleLogger();
$logger->info('Initialized Logger');

$queueManager = new CCQueueManager($config['redis'], $config['queue'], $logger);
$logger->info('Initialized Queue Manager');

// Pop a message from empty queue
$logger->info('--------Set Up Test--------');
$queueManager->clear();
$logger->info('Verify Cleared', [
    'count' => $queueManager->count(),
    'expected' => 0,
]);


$logger->info('--------Add Message--------');
// Push a message to the queue
$message = new CCQueueMessage([
    'body' => 'Hello, World!',
]);
$queueManager->push($message);

$logger->info('Verify', [
    'count' => $queueManager->count(),
    'expected' => 1,
]);

$logger->info('--------Pop Message--------');
// Pop a message from the queue
$poppedMessage = $queueManager->pop();
$logger->info('Verify', [
    'count' => $queueManager->count(),
    'expected' => 0,
]);

$logger->info('--------Pop Message From Empty Queue--------');
$poppedMessage = $queueManager->pop();
$logger->info('Verify', [
    'count' => $poppedMessage,
    'expected' => null,
]);

// Count the number of messages in the queue
$logger->info('--------Count Messages--------');
$queueManager->push(new CCQueueMessage(['body' => 'Message 1']));
$queueManager->push(new CCQueueMessage(['body' => 'Message 2']));
$queueManager->push(new CCQueueMessage(['body' => 'Message 3']));

$logger->info('Count Messages', [
    'count' => $queueManager->count(),
    'expected' => 3,
]);

// Pop messages from the queue
$poppedMessage = $queueManager->pop();
$logger->info('Verify Pop', [
    'message' => $poppedMessage,
    'expected' => ['body' => 'Message 3'],
    'count' => $queueManager->count(),
    'expectedCount' => 2,
]);


$logger->info('--------Shutdown Test--------');
// Clear the queue
$queueManager->clear();
$logger->info('Queue cleared',[
    'count' => $queueManager->count(),
    'expected' => 0,
]);
$logger->info('--------------------------');
