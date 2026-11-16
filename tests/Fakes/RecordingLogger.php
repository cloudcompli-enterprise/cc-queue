<?php

namespace CCQueue\Tests\Fakes;

class RecordingLogger extends NullLogger
{
    public $errors = array();

    public function error($message, array $context = array())
    {
        $this->errors[] = array('message' => $message, 'context' => $context);
    }
}
