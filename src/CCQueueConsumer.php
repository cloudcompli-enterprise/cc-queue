<?php

namespace CloudCompli\CCQueue;

use Predis\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class CCQueueConsumer
{
    protected $redis;
    protected $queueName;
    protected $logger;

    public function __construct(Client $redis, $queueName, LoggerInterface $logger = null)
    {
        $this->redis = $redis;
        $this->queueName = $queueName;
        $this->logger = $logger ?: new NullLogger();

        $this->logger->info('Consumer started', ['queue' => $queueName]);
    }

    public function consume()
    {
        $message = $this->redis->rpop($this->queueName);

        if ($message) {
            $this->handleMessage($message);
        } else {
            sleep(1); // No message, sleep for a while
        }
    }

    protected function handleMessage($message)
    {
        try {
            // Process the message
            echo "Processing message: " . $message['body'] . "\n";
            $this->logger->info('Message processed', $message);

            // Simulate processing
            sleep(2); // Simulate time-consuming task

        } catch (\Exception $e) {
            // Handle failure and retry logic
            $this->logger->error('Message processing failed', [
                'message' => $message,
                'exception' => $e->getMessage()
            ]);

            // Optionally requeue the message or move it to a dead-letter queue
            $this->retryMessage($message);
        }
    }

    protected function retryMessage($message)
    {
        // Requeue the message with some backoff strategy
        $retryAfter = 10; // Retry after 10 seconds
        sleep($retryAfter);
        $this->redis->lpush($this->queueName, $message);
        $this->logger->info('Message requeued', ['message' => $message]);
    }
}