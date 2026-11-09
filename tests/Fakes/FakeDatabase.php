<?php

namespace CCQueue\Tests\Fakes;

/**
 * A database connection as the worker sees it through the DB facade. Like Postgres, a statement that fails
 * inside a transaction aborts it: every later insert is refused until a rollback.
 */
class FakeDatabase
{
    public $inserts = array();

    /** An exception to throw from every insert, as a refusing database would. */
    public $insertFailure;

    public $level = 0;
    public $aborted = false;
    public $pdo;

    private $table;

    public function __construct()
    {
        $this->pdo = new FakePdo($this);
    }

    public function connection($name = null)
    {
        return $this;
    }

    public function getPdo()
    {
        return $this->pdo;
    }

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
        if ($this->aborted) {
            throw new \RuntimeException('current transaction is aborted, commands ignored until end of transaction block');
        }
        $this->inserts[$this->table][] = $row;

        return true;
    }

    public function transactionLevel()
    {
        return $this->level;
    }

    public function beginTransaction()
    {
        $this->level++;
        if ($this->level === 1) {
            $this->pdo->open = true;
        }
    }

    /** Rolling back to a savepoint, or out of the transaction, clears an aborted state. */
    public function rollBack()
    {
        if ($this->level === 0) {
            return;
        }
        $this->level--;
        if ($this->level === 0) {
            $this->pdo->open = false;
        }
        $this->aborted = false;
    }
}
