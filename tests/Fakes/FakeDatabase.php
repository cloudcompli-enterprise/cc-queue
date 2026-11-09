<?php

namespace CCQueue\Tests\Fakes;

/**
 * The database manager and its default connection, as the worker sees them through the DB facade, modelled
 * on Laravel 5.2 over Postgres:
 * - a statement that fails inside a transaction aborts it, and every later insert is refused until a rollback;
 * - beginTransaction() past level 1 creates savepoint trans{level}, and rollBack() returns to it;
 * - rollBack() decrements the transaction count only after the PDO call returns, so a rollback that throws
 *   (a dead connection, or no active transaction because the job committed on the PDO) leaves it stale;
 * - a SAVEPOINT, or a rollback to one, outside a real transaction is refused;
 * - purge() disconnects first, which is refused while the transaction count is above zero; with the default
 *   connection's name it then gives a fresh connection;
 * - getConnections() lists the default connection only once something has used it.
 */
class FakeDatabase
{
    public $inserts = array();

    /** An exception to throw from every insert, as a refusing database would. */
    public $insertFailure;

    public $level = 0;
    public $aborted = false;
    public $pdo;
    public $purges = array();
    public $opened = false;
    public $pdoReads = 0;

    /** @var array the connection-level calls made, in order: connection, getPdo, transactionLevel */
    public $calls = array();

    /** What the next getPdo() calls give instead of the PDO: an exception to throw, or null. */
    public $pdoSequence = array();

    /** Laravel 10's setPdo() resets the transaction count, so disconnecting is never refused. */
    public $swapResetsTransactionCount = false;

    private $table;

    public function __construct()
    {
        $this->pdo = new FakePdo($this);
    }

    public function connection($name = null)
    {
        $this->calls[] = 'connection';
        $this->opened = true;

        return $this;
    }

    public function getConnections()
    {
        return $this->opened ? array($this->getDefaultConnection() => $this) : array();
    }

    public function getPdo()
    {
        $this->calls[] = 'getPdo';
        $this->opened = true;
        $this->pdoReads++;
        if ($this->pdoSequence) {
            $next = array_shift($this->pdoSequence);
            if ($next instanceof \Exception) {
                throw $next;
            }
            if ($next === null) {
                return null;
            }
        }

        return $this->pdo;
    }

    public function getQueryGrammar()
    {
        return new FakeGrammar();
    }

    public function getDefaultConnection()
    {
        return 'pgsql';
    }

    public function purge($name = null)
    {
        $this->purges[] = $name;
        if ($this->level >= 1 && !$this->swapResetsTransactionCount) {
            throw new \RuntimeException("Can't swap PDO instance while within transaction.");
        }
        $this->level = 0;
        if ($name !== $this->getDefaultConnection()) {
            return;
        }
        $this->aborted = false;
        $this->opened = false;
        $this->pdo = new FakePdo($this);
    }

    public function table($table)
    {
        $this->opened = true;
        $this->table = $table;

        return $this;
    }

    public function insert(array $row)
    {
        if ($this->insertFailure) {
            throw $this->insertFailure;
        }
        $this->pdo->assertAlive();
        if ($this->aborted) {
            throw new \RuntimeException('current transaction is aborted, commands ignored until end of transaction block');
        }
        $this->inserts[$this->table][] = $row;

        return true;
    }

    public function transactionLevel()
    {
        $this->calls[] = 'transactionLevel';

        return $this->level;
    }

    /** Like Laravel 5.2, the count goes up before the PDO call, so a dead database leaves it at 1. */
    public function beginTransaction()
    {
        $this->opened = true;
        $this->level++;
        if ($this->level === 1) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT trans' . $this->level);
        }
    }

    public function transaction(\Closure $callback)
    {
        $this->beginTransaction();
        $result = $callback($this);
        $this->commit();

        return $result;
    }

    public function commit()
    {
        if ($this->level === 1) {
            $this->pdo->commit();
        }
        $this->level--;
    }

    /** Rolling back to a savepoint, or out of the transaction, clears an aborted state. */
    public function rollBack()
    {
        if ($this->level === 0) {
            return;
        }
        if ($this->level === 1) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT trans' . $this->level);
        }
        $this->level--;
        $this->aborted = false;
    }
}
