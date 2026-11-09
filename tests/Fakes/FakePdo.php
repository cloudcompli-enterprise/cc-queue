<?php

namespace CCQueue\Tests\Fakes;

class FakePdo
{
    public $open = false;
    public $rollBacks = 0;

    private $database;

    public function __construct(FakeDatabase $database)
    {
        $this->database = $database;
    }

    public function inTransaction()
    {
        return $this->open;
    }

    public function rollBack()
    {
        $this->open = false;
        $this->rollBacks++;
        $this->database->aborted = false;

        return true;
    }
}
