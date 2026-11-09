<?php

namespace CCQueue\Tests\Fakes;

use CCQueue\Console\Commands\CCQueueWorkerCommand;

/** The real worker on a clock the test controls: pauses advance it instead of sleeping. */
class ClockedWorkerCommand extends CCQueueWorkerCommand
{
    public $clock = 1000.0;
    public $pauses = array();

    /** @var callable|null called with the command after each pause */
    public $onPause;

    protected function now()
    {
        return $this->clock;
    }

    protected function pause($seconds)
    {
        $this->pauses[] = $seconds;
        $this->clock += $seconds;
        if ($this->onPause) {
            call_user_func($this->onPause, $this);
        }
    }
}
