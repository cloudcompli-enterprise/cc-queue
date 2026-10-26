<?php

namespace CCQueue\Tests\Fakes;

class FakeRedis
{
    public $lists = array();
    public $hashes = array();
    public $onEmptyPoll;
    private $emptyPolls = 0;

    public function connection()
    {
        return $this;
    }

    public function lpush($key, $value)
    {
        if (!isset($this->lists[$key])) {
            $this->lists[$key] = array();
        }
        array_unshift($this->lists[$key], $value);

        return count($this->lists[$key]);
    }

    public function brpop(array $keys, $timeout)
    {
        foreach ($keys as $key) {
            if (!empty($this->lists[$key])) {
                $this->emptyPolls = 0;

                return array($key, array_pop($this->lists[$key]));
            }
        }

        if ($this->onEmptyPoll) {
            call_user_func($this->onEmptyPoll);
        }

        // The worker polls high, normal and low in turn; three empty polls in a row means
        // every queue it listens on is drained.
        if (++$this->emptyPolls >= 3) {
            throw new StopWorker('queues drained');
        }

        return null;
    }

    public function hmset($key, array $fields)
    {
        $existing = isset($this->hashes[$key]) ? $this->hashes[$key] : array();
        $this->hashes[$key] = array_merge($existing, $fields);

        return true;
    }

    public function hgetall($key)
    {
        return isset($this->hashes[$key]) ? $this->hashes[$key] : array();
    }

    public function expire($key, $seconds)
    {
        return true;
    }
}
