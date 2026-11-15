<?php

namespace CCQueue\Tests\Fakes;

class FakeRedis
{
    public $lists = array();
    public $hashes = array();
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

    public function expire($key, $seconds)
    {
        $this->track('expire');
        return true;
    }
}
