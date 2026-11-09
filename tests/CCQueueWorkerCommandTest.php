<?php

namespace CCQueue\Tests;

use CCQueue\Console\Commands\CCQueueWorkerCommand;
use CCQueue\Services\JobDispatcher;
use CCQueue\Tests\Fakes\FakeConfig;
use CCQueue\Tests\Fakes\ErrorThrowingLogger;
use CCQueue\Tests\Fakes\FakeDatabase;
use CCQueue\Tests\Fakes\FakeRedis;
use CCQueue\Tests\Fakes\NullLogger;
use CCQueue\Tests\Fakes\StopWorker;
use CCQueue\Tests\Fakes\SyncBus;
use CCQueue\Tests\Fakes\TestContainer;
use CCQueue\Tests\Jobs\DatabaseProbeJob;
use CCQueue\Tests\Jobs\StopSignalJob;
use CCQueue\Tests\Jobs\SucceedingJob;
use CCQueue\Tests\Jobs\TransactionalJob;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CCQueueWorkerCommandTest extends TestCase
{
    private $container;
    private $redis;
    private $db;

    /**
     * @before
     */
    public function bootContainer()
    {
        $this->bootWithRetryLimit(1);
    }

    private function bootWithRetryLimit($retryLimit)
    {
        $this->container = new TestContainer();
        Container::setInstance($this->container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);

        $this->redis = new FakeRedis();
        $this->db = new FakeDatabase();
        $logger = new NullLogger();

        $this->container->instance('config', new FakeConfig(array(
            'cc-queue' => array(
                'default' => 'redis',
                'retry_limit' => $retryLimit,
                'handlers' => array(
                    'type-error' => array('default' => 'CCQueue\Tests\Jobs\TypeErrorJob'),
                    'exception' => array('default' => 'CCQueue\Tests\Jobs\ExceptionJob'),
                    'succeeds' => array('default' => 'CCQueue\Tests\Jobs\SucceedingJob'),
                    'stop-signal' => array('default' => 'CCQueue\Tests\Jobs\StopSignalJob'),
                    'non-utf8' => array('default' => 'CCQueue\Tests\Jobs\NonUtf8MessageJob'),
                    'transaction-leak' => array('default' => 'CCQueue\Tests\Jobs\TransactionLeakJob'),
                    'pdo-transaction-leak' => array('default' => 'CCQueue\Tests\Jobs\PdoTransactionLeakJob'),
                    'db-probe' => array('default' => 'CCQueue\Tests\Jobs\DatabaseProbeJob'),
                    'dead-connection' => array('default' => 'CCQueue\Tests\Jobs\DeadConnectionJob'),
                    'pdo-commit' => array('default' => 'CCQueue\Tests\Jobs\PdoCommitJob'),
                    'transactional' => array('default' => 'CCQueue\Tests\Jobs\TransactionalJob'),
                ),
            ),
        )));
        $this->container->instance('redis', $this->redis);
        $this->container->instance('db', $this->db);
        $this->container->instance('log', $logger);
        $this->container->instance('Psr\Log\LoggerInterface', $logger);
        $this->container->instance('Illuminate\Contracts\Bus\Dispatcher', new SyncBus());

        SucceedingJob::$handled = array();
        StopSignalJob::$handled = array();
        DatabaseProbeJob::$handled = array();
        TransactionalJob::$levelsAtStart = array();
    }

    private function enqueue($type, $version, array $data = array())
    {
        $dispatcher = new JobDispatcher();

        return $dispatcher->enqueueJob(array('type' => $type, 'data' => $data), $version);
    }

    private function runWorker($version)
    {
        $command = new CCQueueWorkerCommand();
        $command->setLaravel($this->container);
        $output = new BufferedOutput();

        try {
            $command->run(new ArrayInput(array('version' => $version)), $output);
        } catch (StopWorker $e) {
            return $output->fetch();
        }

        $this->fail('Worker loop exited without draining the queue.');
    }

    private function runWorkerUntilItStops($version)
    {
        $command = new CCQueueWorkerCommand();
        $command->setLaravel($this->container);
        $output = new BufferedOutput();

        try {
            $exitCode = $command->run(new ArrayInput(array('version' => $version)), $output);
        } catch (StopWorker $e) {
            $this->fail("Worker kept polling after a stop signal:\n" . $output->fetch());
        }

        $this->assertSame(0, $exitCode);

        return $output->fetch();
    }

    private function requireSignals()
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('needs the pcntl and posix extensions');
        }
    }

    private function status($uuid)
    {
        $record = $this->redis->hgetall('cc-queue:jobs:' . $uuid);

        return $record['status'];
    }

    private function failedJobUuids()
    {
        $uuids = array();
        if (isset($this->db->inserts['cc_queue_failed_jobs'])) {
            foreach ($this->db->inserts['cc_queue_failed_jobs'] as $row) {
                $uuids[] = $row['uuid'];
            }
        }

        return $uuids;
    }

    private function failedJobRow($uuid)
    {
        foreach ($this->db->inserts['cc_queue_failed_jobs'] as $row) {
            if ($row['uuid'] === $uuid) {
                return $row;
            }
        }

        $this->fail("No cc_queue_failed_jobs row for {$uuid}.");
    }

    private function assertOutputContains($needle, $output)
    {
        $this->assertTrue(strpos($output, $needle) !== false, "Worker output does not contain [{$needle}]:\n{$output}");
    }

    public function versionProvider()
    {
        return array(
            'legacy direct handle()' => array('legacy'),
            'default Bus::dispatchNow()' => array('default'),
        );
    }

    /**
     * @dataProvider versionProvider
     */
    public function testTypeErrorInJobMarksItFailedAndWorkerKeepsRunning($version)
    {
        $broken = $this->enqueue('type-error', $version);
        $healthy = $this->enqueue('succeeds', $version);

        $output = $this->runWorker($version);

        $this->assertSame(JobDispatcher::STATUS_FAILED, $this->status($broken));
        $this->assertSame(array($broken), $this->failedJobUuids());
        $this->assertOutputContains("Job {$broken} failed permanently after 1 attempts", $output);

        $this->assertSame(JobDispatcher::STATUS_COMPLETED, $this->status($healthy));
        $this->assertSame(array($healthy), SucceedingJob::$handled);
    }

    public function testTypeErrorInJobIsRetriedUpToTheRetryLimit()
    {
        $this->bootWithRetryLimit(3);
        $broken = $this->enqueue('type-error', 'default');

        $output = $this->runWorker('default');

        $record = $this->redis->hgetall('cc-queue:jobs:' . $broken);
        $this->assertSame(JobDispatcher::STATUS_FAILED, $record['status']);
        $this->assertSame(3, $record['attempts']);
        $this->assertSame(array($broken), $this->failedJobUuids());
        $this->assertOutputContains("Job {$broken} failed; requeued (attempt 1)", $output);
        $this->assertOutputContains("Job {$broken} failed; requeued (attempt 2)", $output);
        $this->assertOutputContains("Job {$broken} failed permanently after 3 attempts", $output);
    }

    /**
     * @dataProvider versionProvider
     */
    public function testExceptionInJobMarksItFailedAndWorkerKeepsRunning($version)
    {
        $broken = $this->enqueue('exception', $version);
        $healthy = $this->enqueue('succeeds', $version);

        $output = $this->runWorker($version);

        $this->assertSame(JobDispatcher::STATUS_FAILED, $this->status($broken));
        $this->assertSame(array($broken), $this->failedJobUuids());
        $this->assertOutputContains('job blew up', $output);
        $this->assertSame(JobDispatcher::STATUS_COMPLETED, $this->status($healthy));
    }

    public function testAPermanentFailureIsRecordedWithTheConfiguredConnection()
    {
        $broken = $this->enqueue('exception', 'default');

        $this->runWorker('default');

        $row = $this->failedJobRow($broken);
        $this->assertSame('redis', $row['connection']);
        $this->assertSame('cc-queue:default:tasks', $row['queue']);
        $payload = json_decode($row['payload'], true);
        $this->assertSame($broken, $payload['uuid']);
        $exception = json_decode($row['exception'], true);
        $this->assertSame('RuntimeException', $exception['class']);
        $this->assertSame('job blew up', $exception['message']);
        $this->assertTrue(is_string($exception['trace']));
    }

    /**
     * @dataProvider versionProvider
     */
    public function testAFailureThatCannotBeRecordedDoesNotEndTheWorker($version)
    {
        $this->db->insertFailure = new \RuntimeException('null value in column "connection" violates not-null constraint');
        $broken = $this->enqueue('exception', $version);
        $healthy = $this->enqueue('succeeds', $version);

        $output = $this->runWorker($version);

        $this->assertSame(JobDispatcher::STATUS_FAILED, $this->status($broken));
        $this->assertOutputContains("Could not record failed job {$broken}", $output);
        $this->assertOutputContains("Job {$broken} failed permanently after 1 attempts", $output);
        $this->assertSame(JobDispatcher::STATUS_COMPLETED, $this->status($healthy));
        $this->assertSame(array($healthy), SucceedingJob::$handled);
    }

    public function testAFailureWhoseMessageIsNotUtf8IsStillRecorded()
    {
        $broken = $this->enqueue('non-utf8', 'default');

        $this->runWorker('default');

        $exception = json_decode($this->failedJobRow($broken)['exception'], true);
        $this->assertTrue(is_array($exception), 'the exception column holds JSON');
        $this->assertSame('RuntimeException', $exception['class']);
    }

    /**
     * @dataProvider versionProvider
     */
    public function testAFinalFailureInsideAnOpenTransactionIsRolledBackSoItIsRecordedAndTheNextJobCanWrite($version)
    {
        $leaking = $this->enqueue('transaction-leak', $version);
        $probe = $this->enqueue('db-probe', $version);

        $this->runWorker($version);

        $this->assertSame(0, $this->db->level);
        $this->assertSame(array($leaking), $this->failedJobUuids());
        $this->assertSame(array($probe), DatabaseProbeJob::$handled);
        $this->assertSame(JobDispatcher::STATUS_COMPLETED, $this->status($probe));
    }

    public function testARetriedFailureInsideAnOpenTransactionDoesNotBreakTheJobsAfterIt()
    {
        $this->bootWithRetryLimit(3);
        $leaking = $this->enqueue('transaction-leak', 'default');
        $probe = $this->enqueue('db-probe', 'default');

        $this->runWorker('default');

        $this->assertSame(array($probe), DatabaseProbeJob::$handled);
        $this->assertSame(array($leaking), $this->failedJobUuids());
        $record = $this->redis->hgetall('cc-queue:jobs:' . $leaking);
        $this->assertSame(3, $record['attempts']);
        $this->assertSame(0, $this->db->level);
    }

    public function testATransactionOpenBeforeTheJobStartedIsLeftOpen()
    {
        $this->db->beginTransaction();
        $leaking = $this->enqueue('transaction-leak', 'default');

        $this->runWorker('default');

        $this->assertSame(1, $this->db->level);
        $this->assertTrue($this->db->pdo->inTransaction());
        $this->assertSame(0, $this->db->pdo->rollBacks);
        $this->assertSame(array($leaking), $this->failedJobUuids());
    }

    public function testATransactionOpenedOnThePdoDirectlyIsRolledBack()
    {
        $leaking = $this->enqueue('pdo-transaction-leak', 'default');
        $probe = $this->enqueue('db-probe', 'default');

        $this->runWorker('default');

        $this->assertFalse($this->db->pdo->inTransaction());
        $this->assertSame(array($leaking), $this->failedJobUuids());
        $this->assertSame(array($probe), DatabaseProbeJob::$handled);
    }

    public function pdoCommitProvider()
    {
        return array(
            'one level' => array(array()),
            'nested' => array(array('nested' => true)),
        );
    }

    /**
     * @dataProvider pdoCommitProvider
     */
    public function testAJobThatCommittedOnThePdoIsResynchronisedSoLaterTransactionsStartAtZero(array $data)
    {
        $broken = $this->enqueue('pdo-commit', 'default', $data);
        $later = $this->enqueue('transactional', 'default');

        $output = $this->runWorker('default');

        $this->assertSame(array($broken), $this->failedJobUuids());
        $this->assertSame(array(0), TransactionalJob::$levelsAtStart);
        $this->assertSame(JobDispatcher::STATUS_COMPLETED, $this->status($later));
        $this->assertSame(array(), $this->db->purges);
        $this->assertOutputContains('re-synchronised', $output);
    }

    /**
     * @dataProvider versionProvider
     */
    public function testADeadConnectionTheWorkerCannotRepairStopsItSoAFreshOneStarts($version)
    {
        $this->enqueue('dead-connection', $version);
        $waiting = $this->enqueue('succeeds', $version);

        $output = $this->runWorkerUntilItStops($version);

        $this->assertSame(array('pgsql'), $this->db->purges, 'a reset was tried');
        $this->assertSame(array(), SucceedingJob::$handled);
        $this->assertSame(JobDispatcher::STATUS_PENDING, $this->status($waiting));
        $this->assertOutputContains('the worker stops after this job', $output);
    }

    public function testWhereResettingTheConnectionClearsTheCountADeadConnectionIsReset()
    {
        $this->db->swapResetsTransactionCount = true;
        $this->enqueue('dead-connection', 'default');
        $later = $this->enqueue('transactional', 'default');

        $output = $this->runWorker('default');

        $this->assertSame(array('pgsql'), $this->db->purges);
        $this->assertSame(array(0), TransactionalJob::$levelsAtStart);
        $this->assertSame(JobDispatcher::STATUS_COMPLETED, $this->status($later));
        $this->assertOutputContains('the connection was reset', $output);
    }

    public function testARollbackThatThrowsInsideACallersTransactionLeavesTheConnectionAlone()
    {
        $this->db->beginTransaction();
        $this->enqueue('dead-connection', 'default');

        $output = $this->runWorker('default');

        $this->assertSame(array(), $this->db->purges);
        $this->assertSame(2, $this->db->level, "the caller's transaction and the job's are both still counted");
        $this->assertOutputContains("inside the caller's transaction", $output);
    }

    public function testALoggerThatFailsWhileReportingAnUnrecordedFailureDoesNotEndTheWorker()
    {
        $logger = new ErrorThrowingLogger();
        $this->container->instance('log', $logger);
        $this->container->instance('Psr\Log\LoggerInterface', $logger);
        Facade::clearResolvedInstances();
        $this->db->insertFailure = new \RuntimeException('insert refused');
        $broken = $this->enqueue('exception', 'default');
        $healthy = $this->enqueue('succeeds', 'default');

        $previousErrorLog = ini_set('error_log', '/dev/null');
        try {
            $this->runWorker('default');
        } finally {
            ini_set('error_log', $previousErrorLog);
        }

        $this->assertSame(JobDispatcher::STATUS_FAILED, $this->status($broken));
        $this->assertSame(JobDispatcher::STATUS_COMPLETED, $this->status($healthy));
        $this->assertSame(array($healthy), SucceedingJob::$handled);
    }

    /**
     * @dataProvider versionProvider
     */
    public function testStopSignalDuringAJobLetsItFinishAndTakesNoOther($version)
    {
        $this->requireSignals();
        $signalled = $this->enqueue('stop-signal', $version);
        $waiting = $this->enqueue('succeeds', $version);

        $output = $this->runWorkerUntilItStops($version);

        $this->assertSame(array($signalled), StopSignalJob::$handled);
        $this->assertSame(JobDispatcher::STATUS_COMPLETED, $this->status($signalled));
        $this->assertSame(array(), SucceedingJob::$handled);
        $this->assertSame(JobDispatcher::STATUS_PENDING, $this->status($waiting));
        $this->assertSame(1, count($this->redis->lists['cc-queue:' . $version . ':tasks']));
        $this->assertOutputContains('Worker stopped', $output);
    }

    public function testStopSignalWhileIdleStopsTheWorker()
    {
        $this->requireSignals();
        $this->redis->onEmptyPoll = function () {
            posix_kill(getmypid(), SIGTERM);
        };

        $output = $this->runWorkerUntilItStops('default');

        $this->assertOutputContains('Worker stopped', $output);
    }

    public function testStopSignalDuringAnEmptyHighPollLeavesTheNormalQueueAlone()
    {
        $this->requireSignals();
        $waiting = $this->enqueue('succeeds', 'default');
        $signalled = false;
        $this->redis->onEmptyPoll = function () use (&$signalled) {
            if (!$signalled) {
                $signalled = true;
                posix_kill(getmypid(), SIGTERM);
            }
        };

        $this->runWorkerUntilItStops('default');

        $this->assertSame(array(), SucceedingJob::$handled);
        $this->assertSame(JobDispatcher::STATUS_PENDING, $this->status($waiting));
    }
}
