<?php

namespace CCQueue\Tests;

use CCQueue\Console\Commands\CCQueueWorkerCommand;
use CCQueue\Services\JobDispatcher;
use CCQueue\Tests\Fakes\FakeConfig;
use CCQueue\Tests\Fakes\ClockedWorkerCommand;
use CCQueue\Tests\Fakes\ErrorThrowingLogger;
use CCQueue\Tests\Fakes\FakeDatabase;
use CCQueue\Tests\Fakes\FakeQueryException;
use CCQueue\Tests\Fakes\FakeRedis;
use CCQueue\Tests\Fakes\NullLogger;
use CCQueue\Tests\Fakes\RecordingLogger;
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
                'min_uptime_before_stop' => 0,
                'handlers' => array(
                    'type-error' => array('default' => 'CCQueue\Tests\Jobs\TypeErrorJob'),
                    'exception' => array('default' => 'CCQueue\Tests\Jobs\ExceptionJob'),
                    'succeeds' => array('default' => 'CCQueue\Tests\Jobs\SucceedingJob'),
                    'stop-signal' => array('default' => 'CCQueue\Tests\Jobs\StopSignalJob'),
                    'non-utf8' => array('default' => 'CCQueue\Tests\Jobs\NonUtf8MessageJob'),
                    'transaction-leak' => array('default' => 'CCQueue\Tests\Jobs\TransactionLeakJob'),
                    'dead-db-transaction' => array('default' => 'CCQueue\Tests\Jobs\DeadDatabaseTransactionJob'),
                    'db-probe' => array('default' => 'CCQueue\Tests\Jobs\DatabaseProbeJob'),
                    'dead-connection' => array('default' => 'CCQueue\Tests\Jobs\DeadConnectionJob'),
                    'pdo-commit' => array('default' => 'CCQueue\Tests\Jobs\PdoCommitJob'),
                    'transactional' => array('default' => 'CCQueue\Tests\Jobs\TransactionalJob'),
                    'nul-message' => array('default' => 'CCQueue\Tests\Jobs\NulMessageJob'),
                    'transaction-leak-return' => array('default' => 'CCQueue\Tests\Jobs\TransactionLeakReturnJob'),
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

    private function runWorker($version, CCQueueWorkerCommand $command = null)
    {
        $command = $command ?: new CCQueueWorkerCommand();
        $command->setLaravel($this->container);
        $output = new BufferedOutput();

        try {
            $command->run(new ArrayInput(array('version' => $version)), $output);
        } catch (StopWorker $e) {
            return $output->fetch();
        }

        $this->fail('Worker loop exited without draining the queue.');
    }

    private function runWorkerUntilItStops($version, CCQueueWorkerCommand $command = null)
    {
        $command = $command ?: new CCQueueWorkerCommand();
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

    private function useRecordingLogger()
    {
        $logger = new RecordingLogger();
        $this->container->instance('log', $logger);
        $this->container->instance('Psr\Log\LoggerInterface', $logger);
        Facade::clearResolvedInstances();

        return $logger;
    }

    private function assertStrandedJobReport(RecordingLogger $logger, $uuid, $redisMessage)
    {
        $this->assertCount(1, $logger->errors);
        $report = $logger->errors[0];
        $this->assertTrue(strpos($report['message'], $uuid) !== false, 'the report names the job');
        $this->assertTrue(strpos($report['message'], 'may be stranded') !== false);
        $this->assertSame($uuid, $report['context']['uuid']);
        $payload = json_decode($report['context']['payload'], true);
        $this->assertSame($uuid, $payload['uuid']);
        $this->assertSame($redisMessage, $report['context']['error']);
    }

    private function requireSignals()
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('needs the pcntl and posix extensions');
        }
    }

    private function pushedTo($queue)
    {
        return isset($this->redis->pushes[$queue]) ? $this->redis->pushes[$queue] : 0;
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

    public function testALegacyWorkerRetriesAJobOnTheLegacyQueue()
    {
        $this->bootWithRetryLimit(3);
        $broken = $this->enqueue('exception', 'legacy');

        $output = $this->runWorker('legacy');

        $record = $this->redis->hgetall('cc-queue:jobs:' . $broken);
        $this->assertSame(JobDispatcher::STATUS_FAILED, $record['status']);
        $this->assertSame(3, $record['attempts']);
        $this->assertSame(array($broken), $this->failedJobUuids());
        $this->assertOutputContains("Job {$broken} failed; requeued (attempt 2)", $output);
        $this->assertSame(3, $this->pushedTo('cc-queue:legacy:tasks'), 'the job and its two retries');
        $this->assertSame(0, $this->pushedTo('cc-queue:default:tasks'));
    }

    public function testARetryStaysOnTheWorkersFamilyWhenThePayloadNamesAnother()
    {
        $this->bootWithRetryLimit(3);
        $dispatcher = new JobDispatcher();
        $broken = $dispatcher->enqueueJob(array('type' => 'exception', 'data' => array(), 'version' => 'default'), 'legacy');

        $output = $this->runWorker('legacy');

        $record = $this->redis->hgetall('cc-queue:jobs:' . $broken);
        $this->assertSame(JobDispatcher::STATUS_FAILED, $record['status']);
        $this->assertSame(3, $record['attempts']);
        $this->assertSame(array($broken), $this->failedJobUuids());
        $this->assertOutputContains("Job {$broken} failed permanently after 3 attempts", $output);
        $this->assertSame(3, $this->pushedTo('cc-queue:legacy:tasks'), 'the job and its two retries');
        $this->assertSame(0, $this->pushedTo('cc-queue:default:tasks'));
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

    public function connectionFailureProvider()
    {
        $refused = 'connection to server at "db", port 5432 failed: Connection refused';

        return array(
            'Laravel 5.2: the connector throws a bare PDOException' => array(
                new \PDOException('SQLSTATE[08006] [7] could not connect to server: Connection refused', 7),
            ),
            'a bare PDOException without a SQLSTATE in the message' => array(
                new \PDOException('could not translate host name "db" to address', 7),
            ),
            'Laravel 10: the first connect fails inside the query (08006)' => array(
                new FakeQueryException('SQLSTATE[08006] [7] ' . $refused, '08006'),
            ),
            'a connection exception of another 08 kind' => array(
                new FakeQueryException('SQLSTATE[08003]: connection does not exist', '08003'),
            ),
            'the server shuts down (57P01)' => array(
                new FakeQueryException('SQLSTATE[57P01]: terminating connection due to administrator command', '57P01'),
            ),
            'the server crashes (57P02)' => array(
                new FakeQueryException('SQLSTATE[57P02]: terminating connection because of crash of another server process', '57P02'),
            ),
            'the server is starting up (57P03)' => array(
                new FakeQueryException('SQLSTATE[57P03]: the database system is starting up', '57P03'),
            ),
            'an established connection drops (HY000 with a lost-connection message)' => array(
                new FakeQueryException('SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly', 'HY000'),
            ),
        );
    }

    /**
     * @dataProvider connectionFailureProvider
     */
    public function testAnUnreachableDatabaseWhileRecordingAFailureStopsTheWorkerOnceItHasBeenUpTheMinimumTime($insertFailure)
    {
        $logger = $this->useRecordingLogger();
        $this->container->make('config')->set('cc-queue.min_uptime_before_stop', 10);
        $this->db->insertFailure = $insertFailure;
        $broken = $this->enqueue('exception', 'default');
        $waiting = $this->enqueue('succeeds', 'default');
        $worker = new ClockedWorkerCommand();
        $reportsWhenTheWaitBegan = null;
        $worker->onPause = function () use ($logger, &$reportsWhenTheWaitBegan) {
            if ($reportsWhenTheWaitBegan === null) {
                $reportsWhenTheWaitBegan = count($logger->errors);
            }
        };

        $output = $this->runWorkerUntilItStops('default', $worker);

        $this->assertSame(JobDispatcher::STATUS_FAILED, $this->status($broken));
        $this->assertSame(JobDispatcher::STATUS_PENDING, $this->status($waiting), 'the next job was not claimed');
        $this->assertSame(array(), SucceedingJob::$handled);
        $this->assertEquals(10.0, array_sum($worker->pauses), 'waited out the minimum uptime', 0.0001);
        $this->assertSame(1, $reportsWhenTheWaitBegan, 'the unrecorded job was reported before the wait');
        $this->assertSame($broken, $logger->errors[0]['context']['uuid']);
        $payload = json_decode($logger->errors[0]['context']['payload'], true);
        $this->assertSame($broken, $payload['uuid']);
        $this->assertOutputContains("Could not record failed job {$broken}", $output);
        $this->assertOutputContains('the worker stops after this job', $output);
    }

    public function dataErrorProvider()
    {
        return array(
            'not-null violation' => array(new FakeQueryException('SQLSTATE[23502]: Not null violation: 7 null value in column "payload"', '23502', 7)),
            'unique violation' => array(new FakeQueryException('SQLSTATE[23505]: Unique violation: 7 duplicate key value violates unique constraint', '23505', 7)),
            'a query error whose message only mentions a connection' => array(new FakeQueryException('SQLSTATE[22P05]: untranslatable character in the connection encoding', '22P05', 7)),
        );
    }

    /**
     * @dataProvider dataErrorProvider
     */
    public function testARefusedRowIsReportedAndTheWorkerTakesTheNextJob($insertFailure)
    {
        $logger = $this->useRecordingLogger();
        $this->container->make('config')->set('cc-queue.min_uptime_before_stop', 10);
        $this->db->insertFailure = $insertFailure;
        $broken = $this->enqueue('exception', 'default');
        $healthy = $this->enqueue('succeeds', 'default');
        $worker = new ClockedWorkerCommand();

        $output = $this->runWorker('default', $worker);

        $this->assertSame(array(), $worker->pauses, 'no wait');
        $this->assertCount(1, $logger->errors);
        $this->assertSame($broken, $logger->errors[0]['context']['uuid']);
        $this->assertOutputContains("Could not record failed job {$broken}", $output);
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

    public function testADeadDatabaseStopsTheWorkerOnlyOnceItHasBeenUpTheMinimumTime()
    {
        $this->container->make('config')->set('cc-queue.min_uptime_before_stop', 10);
        $broken = $this->enqueue('dead-db-transaction', 'legacy');
        $waiting = $this->enqueue('succeeds', 'legacy');
        $worker = new ClockedWorkerCommand();
        $statusWhenTheWaitBegan = null;
        $worker->onPause = function () use ($broken, &$statusWhenTheWaitBegan) {
            if ($statusWhenTheWaitBegan === null) {
                $statusWhenTheWaitBegan = $this->status($broken);
            }
        };

        $output = $this->runWorkerUntilItStops('legacy', $worker);

        $this->assertSame(JobDispatcher::STATUS_FAILED, $statusWhenTheWaitBegan, 'recorded as failed before the wait');
        $this->assertEquals(10.0, array_sum($worker->pauses), 'waited out the minimum uptime', 0.0001);
        $this->assertLessThanOrEqual(0.5, max($worker->pauses), 'in short sleeps');
        $this->assertSame(JobDispatcher::STATUS_PENDING, $this->status($waiting));
        $this->assertOutputContains('the worker stops after this job', $output);
    }

    public function testAJobWithRetriesLeftIsRequeuedBeforeTheMinimumUptimeWait()
    {
        $this->bootWithRetryLimit(2);
        $this->container->make('config')->set('cc-queue.min_uptime_before_stop', 10);
        $broken = $this->enqueue('dead-db-transaction', 'default');
        $worker = new ClockedWorkerCommand();
        $whenTheWaitBegan = null;
        $worker->onPause = function () use ($broken, &$whenTheWaitBegan) {
            if ($whenTheWaitBegan === null) {
                $whenTheWaitBegan = array(
                    'status' => $this->status($broken),
                    'queued' => count($this->redis->lists['cc-queue:default:tasks']),
                );
            }
        };

        $this->runWorkerUntilItStops('default', $worker);

        $this->assertSame(array('status' => JobDispatcher::STATUS_PENDING, 'queued' => 1), $whenTheWaitBegan, 'requeued before the wait');
        $this->assertNotEmpty($worker->pauses);
    }

    public function testAStopSignalEndsTheMinimumUptimeWaitAtOnce()
    {
        $this->container->make('config')->set('cc-queue.min_uptime_before_stop', 10);
        $this->enqueue('dead-db-transaction', 'legacy');
        $worker = new ClockedWorkerCommand();
        $worker->onPause = function ($worker) {
            $worker->requestStop();
        };

        $this->runWorkerUntilItStops('legacy', $worker);

        $this->assertCount(1, $worker->pauses);
    }

    public function testAFailingJobThatNeverUsedTheDatabaseLeavesItsConnectionAlone()
    {
        $broken = $this->enqueue('exception', 'default');

        $this->runWorker('default');

        $this->assertSame(array(), $this->db->calls, 'no connection made, no PDO read, no transaction level asked');
        $this->assertSame(array($broken), $this->failedJobUuids());
    }

    public function testAPdoThatIsGoneWhileResynchronisingDoesNotEndTheRun()
    {
        $this->enqueue('pdo-commit', 'default');
        $waiting = $this->enqueue('succeeds', 'default');
        $this->db->pdoSequence = array(null);

        $output = $this->runWorkerUntilItStops('default');

        $this->assertSame(JobDispatcher::STATUS_PENDING, $this->status($waiting));
        $this->assertOutputContains('the worker stops after this job', $output);
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

    public function testARedisFailureWhileRequeuingAJobIsReportedAndStopsTheWorkerCleanlyOnceItHasBeenUpTheMinimumTime()
    {
        $this->bootWithRetryLimit(2);
        $logger = $this->useRecordingLogger();
        $this->container->make('config')->set('cc-queue.min_uptime_before_stop', 10);
        $stranded = $this->enqueue('exception', 'default');
        $waiting = $this->enqueue('succeeds', 'default');
        $this->redis->failOnCall('hmset', 2, 'OOM command not allowed when used memory > maxmemory');
        $worker = new ClockedWorkerCommand();
        $reportsWhenTheWaitBegan = null;
        $worker->onPause = function () use ($logger, &$reportsWhenTheWaitBegan) {
            if ($reportsWhenTheWaitBegan === null) {
                $reportsWhenTheWaitBegan = count($logger->errors);
            }
        };

        $output = $this->runWorkerUntilItStops('default', $worker);

        $this->assertStrandedJobReport($logger, $stranded, 'OOM command not allowed when used memory > maxmemory');
        $this->assertSame(1, $reportsWhenTheWaitBegan, 'reported before the wait');
        $this->assertEquals(10.0, array_sum($worker->pauses), 'waited out the minimum uptime', 0.0001);
        $this->assertOutputContains("Job {$stranded} may be stranded", $output);
        $this->assertSame(JobDispatcher::STATUS_PENDING, $this->status($waiting));
        $this->assertSame(array(), SucceedingJob::$handled);
    }

    public function testARedisFailureWhileWritingAPermanentFailureIsReportedAndStopsTheWorkerCleanly()
    {
        $logger = $this->useRecordingLogger();
        $stranded = $this->enqueue('exception', 'default');
        $waiting = $this->enqueue('succeeds', 'default');
        $this->redis->failOnCall('hmset', 2);

        $output = $this->runWorkerUntilItStops('default');

        $this->assertStrandedJobReport($logger, $stranded, 'LOADING Redis is loading the dataset in memory');
        $this->assertSame(JobDispatcher::STATUS_IN_PROGRESS, $this->status($stranded));
        $this->assertSame(JobDispatcher::STATUS_PENDING, $this->status($waiting));
        $this->assertSame(array(), SucceedingJob::$handled);
        $this->assertOutputContains('may be stranded', $output);
    }

    public function testARedisFailureWhileMarkingASuccessfulJobCompletedIsReportedAndStopsTheWorkerCleanly()
    {
        $logger = $this->useRecordingLogger();
        $ran = $this->enqueue('succeeds', 'default');
        $waiting = $this->enqueue('succeeds', 'default');
        $this->redis->failOnCall('hmset', 2, 'LOADING Redis is loading the dataset in memory', true);

        $output = $this->runWorkerUntilItStops('default');

        $this->assertStrandedJobReport($logger, $ran, 'LOADING Redis is loading the dataset in memory');
        $this->assertSame(array($ran), SucceedingJob::$handled, 'the job ran once and the next one was not taken');
        $this->assertSame(JobDispatcher::STATUS_PENDING, $this->status($waiting));
        $this->assertOutputContains('may be stranded', $output);
    }

    public function testAHealthyRedisRequeuesAFailedJobWithoutStoppingTheWorker()
    {
        $this->bootWithRetryLimit(2);
        $logger = $this->useRecordingLogger();
        $broken = $this->enqueue('exception', 'default');

        $output = $this->runWorker('default');

        $this->assertSame(array(), $logger->errors);
        $this->assertSame(JobDispatcher::STATUS_FAILED, $this->status($broken));
        $this->assertSame(array($broken), $this->failedJobUuids());
        $this->assertOutputContains("Job {$broken} failed; requeued (attempt 1)", $output);
        $this->assertTrue(strpos($output, 'stranded') === false, 'nothing reported as stranded');
    }

    public function testARefusedFailureRecordIsReportedWithThePayloadAndTheException()
    {
        $logger = $this->useRecordingLogger();
        $this->db->insertFailure = new \RuntimeException('insert refused');
        $broken = $this->enqueue('exception', 'default');

        $this->runWorker('default');

        $this->assertCount(1, $logger->errors);
        $context = $logger->errors[0]['context'];
        $this->assertSame($broken, $context['uuid']);
        $payload = json_decode($context['payload'], true);
        $this->assertSame($broken, $payload['uuid']);
        $this->assertSame('exception', $payload['type']);
        $this->assertSame('RuntimeException', $context['exception']['class']);
        $this->assertSame('job blew up', $context['exception']['message']);
        $this->assertTrue(strpos($context['exception']['file'], 'ExceptionJob.php') !== false);
        $this->assertSame('insert refused', $context['error']);
    }

    public function testANulByteInAnExceptionMessageDoesNotMakeTheFailureRecordRefused()
    {
        $broken = $this->enqueue('nul-message', 'default');

        $this->runWorker('default');

        $this->assertSame(array($broken), $this->failedJobUuids());
        $exception = json_decode($this->failedJobRow($broken)['exception'], true);
        $this->assertSame('upstream answered  and hung up', $exception['message']);
    }

    public function testAJobThatReturnsWithATransactionOpenIsRolledBackRetriedAndRecordedAsFailed()
    {
        $this->bootWithRetryLimit(2);
        $leaking = $this->enqueue('transaction-leak-return', 'default');
        $next = $this->enqueue('transactional', 'default');

        $output = $this->runWorker('default');

        $record = $this->redis->hgetall('cc-queue:jobs:' . $leaking);
        $this->assertSame(JobDispatcher::STATUS_FAILED, $record['status']);
        $this->assertSame(2, $record['attempts']);
        $this->assertSame(array($leaking), $this->failedJobUuids());
        $this->assertSame(0, $this->db->level);
        $this->assertSame(array(0), TransactionalJob::$levelsAtStart, 'the next job starts at the original level');
        $this->assertSame(JobDispatcher::STATUS_COMPLETED, $this->status($next));
        $this->assertOutputContains("Job {$leaking} failed; requeued (attempt 1)", $output);
        $this->assertOutputContains('The job returned with a transaction left open.', $output);
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
