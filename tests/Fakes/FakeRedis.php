<?php

namespace CCQueue\Tests\Fakes;

class FakeRedis
{
    public $lists = array();
    public $hashes = array();

    /** @var array seconds to live of each key that has a TTL */
    public $ttls = array();

    /** @var array how many times each list key has been pushed to, whether or not it has been drained since */
    public $pushes = array();

    public $onEmptyPoll;
    private $emptyPolls = 0;
    private $failures = array();

    /**
     * Makes the nth call from now to $method throw, and with $andAfter every call after it too.
     */
    public function failOnCall($method, $nth, $message = 'LOADING Redis is loading the dataset in memory', $andAfter = false)
    {
        $this->failures[$method] = array('remaining' => $nth, 'message' => $message, 'andAfter' => $andAfter);
    }

    private function track($method)
    {
        if (!isset($this->failures[$method])) {
            return;
        }
        $failure = $this->failures[$method];
        if ($failure['remaining'] > 1) {
            $this->failures[$method]['remaining']--;

            return;
        }
        if ($failure['andAfter']) {
            $this->failures[$method]['remaining'] = 1;
        } else {
            unset($this->failures[$method]);
        }

        throw new \RuntimeException($failure['message']);
    }

    public function connection()
    {
        return $this;
    }

    public function lpush($key, $value)
    {
        $this->track('lpush');
        $this->pushes[$key] = (isset($this->pushes[$key]) ? $this->pushes[$key] : 0) + 1;
        if (!isset($this->lists[$key])) {
            $this->lists[$key] = array();
        }
        array_unshift($this->lists[$key], $value);

        return count($this->lists[$key]);
    }

    public function brpop(array $keys, $timeout)
    {
        $this->track('brpop');
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
        $this->track('hmset');
        $existing = isset($this->hashes[$key]) ? $this->hashes[$key] : array();
        $this->hashes[$key] = array_merge($existing, $fields);

        return true;
    }

    public function hgetall($key)
    {
        $this->track('hgetall');
        return isset($this->hashes[$key]) ? $this->hashes[$key] : array();
    }

    /** As in Redis: a key that doesn't exist takes no TTL, and a TTL of zero or less deletes the key. */
    public function expire($key, $seconds)
    {
        $this->track('expire');
        if (!$this->exists($key)) {
            return false;
        }
        if ($seconds <= 0) {
            unset($this->hashes[$key], $this->lists[$key], $this->ttls[$key]);

            return true;
        }
        $this->ttls[$key] = $seconds;

        return true;
    }

    public function persist($key)
    {
        $this->track('persist');
        if (!$this->exists($key) || !isset($this->ttls[$key])) {
            return false;
        }
        unset($this->ttls[$key]);

        return true;
    }

    private function exists($key)
    {
        return isset($this->hashes[$key]) || !empty($this->lists[$key]);
    }
}
