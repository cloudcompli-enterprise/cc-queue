<?php

namespace CCQueue\Tests\Fakes;

/**
 * What Laravel's QueryException carries: the wrapped PDOException with its driver code (7) and errorInfo,
 * and a message that appends the SQL with its bindings.
 */
class FakeQueryException extends \PDOException
{
    public function __construct($message, $sqlState, $bindings = '')
    {
        $driver = new \PDOException($message, 7);
        $driver->errorInfo = array($sqlState, 7, $message);
        parent::__construct($message . ' (SQL: insert into "cc_queue_failed_jobs" ("exception") values (' . $bindings . '))', 7, $driver);
        $this->errorInfo = $driver->errorInfo;
    }

    public function getSql()
    {
        return 'insert into "cc_queue_failed_jobs" ("exception") values (?)';
    }
}
