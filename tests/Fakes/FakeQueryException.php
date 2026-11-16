<?php

namespace CCQueue\Tests\Fakes;

/** What Laravel's QueryException carries: the PDO driver code and errorInfo of the exception it wraps. */
class FakeQueryException extends \PDOException
{
    public function __construct($message, $sqlState, $driverCode = 7)
    {
        parent::__construct($message, $driverCode);
        $this->errorInfo = array($sqlState, $driverCode, $message);
    }

    public function getSql()
    {
        return 'insert into "cc_queue_failed_jobs" ("uuid") values (?)';
    }
}
