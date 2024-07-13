<?php

namespace CloudCompli\CCQueue;

class CCQueueMessage
{
    protected $body;

    public function __construct($body)
    {
        $this->body = $body;
    }

    public function toArray()
    {
        return $this->body;
    }

    public function toJson()
    {
        return json_encode($this->body);
    }

}