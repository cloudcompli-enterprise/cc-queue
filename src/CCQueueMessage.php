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
        return ['body' => $this->body];
    }

    public function toJson()
    {
        return json_encode(['body' => $this->body]);
    }

}