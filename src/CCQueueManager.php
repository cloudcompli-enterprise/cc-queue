<?php

namespace CloudCompli\CCQueue;

use Predis\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class CCQueueManager
{
    protected $redis;
    protected $queueName;

    protected $logger;

    public function __construct(Client $redis, $queueName, LoggerInterface $logger = null)
    {
        $this->redis = $redis;
        $this->queueName = $queueName;
        $this->logger = $logger ?: new NullLogger();
    }

    public function getConsumer()
    {
        return new CCQueueConsumer($this->redis, $this->queueName);
    }

    public function push(CCQueueMessage $message)
    {
        $this->redis->lpush($this->queueName, $message->toJson());
        $this->logger->info('Message pushed to queue', [
            'message' => $message->toArray(),
        ]);
    }

    public function pop()
    {
        $message = $this->redis->rpop($this->queueName);

        if ($message) {
            $message = json_decode($message, true);
            $this->logger->info('Message popped from the queue', ['message' => $message]);
            return $message;
        } else {
            $this->logger->info('No message in the queue to pop');
            return null;
        }
    }

    public function clear()
    {
        $this->redis->del($this->queueName);
        $this->logger->info('Queue cleared');
    }

    public function count()
    {
        $count = $this->redis->llen($this->queueName);
        $this->logger->info('Counted items in the queue', ['count' => $count]);
        return $count;
    }
}

