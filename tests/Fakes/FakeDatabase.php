<?php

namespace CCQueue\Tests\Fakes;

class FakeDatabase
{
    public $inserts = array();
    private $table;

    public function table($table)
    {
        $this->table = $table;

        return $this;
    }

    public function insert(array $row)
    {
        $this->inserts[$this->table][] = $row;

        return true;
    }
}
