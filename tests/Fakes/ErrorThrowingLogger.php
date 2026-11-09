<?php

namespace CCQueue\Tests\Fakes;

/** A logger whose error() fails, as one writing to an unwritable file or a full disk does. */
class ErrorThrowingLogger extends NullLogger
{
    public function error($message, array $context = array())
    {
        throw new \RuntimeException('log stream is not writable');
    }
}
