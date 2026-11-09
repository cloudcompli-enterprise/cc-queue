<?php

namespace CCQueue\Tests\Fakes;

class FakeDatabase
{
    public $inserts = array();

    /** An exception to throw from every insert, as a refusing database would. */
    public $insertFailure;

    private $table;

    public function table($table)
    {
        $this->table = $table;

        return $this;
    }

    public function insert(array $row)
    {
        if ($this->insertFailure) {
            throw $this->insertFailure;
        }
        $this->inserts[$this->table][] = $row;

        return true;
    }
}
