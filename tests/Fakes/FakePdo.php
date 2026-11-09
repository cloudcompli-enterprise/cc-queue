<?php

namespace CCQueue\Tests\Fakes;

class FakePdo
{
    public $open = false;
    public $dead = false;
    public $savepoints = array();
    public $rollBacks = 0;

    private $database;

    public function __construct(FakeDatabase $database)
    {
        $this->database = $database;
    }

    public function assertAlive()
    {
        if ($this->dead) {
            throw new \RuntimeException('server closed the connection unexpectedly');
        }
    }

    public function inTransaction()
    {
        return $this->open;
    }

    public function beginTransaction()
    {
        $this->assertAlive();
        if ($this->open) {
            throw new \PDOException('There is already an active transaction');
        }
        $this->open = true;

        return true;
    }

    public function commit()
    {
        $this->assertAlive();
        if (!$this->open) {
            throw new \PDOException('There is no active transaction');
        }
        $this->open = false;
        $this->savepoints = array();

        return true;
    }

    public function rollBack()
    {
        $this->assertAlive();
        if (!$this->open) {
            throw new \PDOException('There is no active transaction');
        }
        $this->open = false;
        $this->savepoints = array();
        $this->rollBacks++;
        $this->database->aborted = false;

        return true;
    }

    public function exec($sql)
    {
        $this->assertAlive();
        if (preg_match('/^SAVEPOINT (\w+)$/', $sql, $m)) {
            if (!$this->open) {
                throw new \PDOException('SAVEPOINT can only be used in transaction blocks');
            }
            $this->savepoints[] = $m[1];

            return 0;
        }
        if (preg_match('/^ROLLBACK TO SAVEPOINT (\w+)$/', $sql, $m)) {
            if (!$this->open) {
                throw new \PDOException('ROLLBACK TO SAVEPOINT can only be used in transaction blocks');
            }
            if (!in_array($m[1], $this->savepoints, true)) {
                throw new \PDOException("savepoint \"{$m[1]}\" does not exist");
            }
            $this->database->aborted = false;

            return 0;
        }

        return 0;
    }
}
