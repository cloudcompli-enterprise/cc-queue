<?php

namespace CCQueue\Tests;

use CCQueue\Console\Commands\CCQueueWorkerCommand;
use CCQueue\Services\JobDispatcher;
use CCQueue\Tests\Fakes\FakeConfig;
use CCQueue\Tests\Fakes\FakeDatabase;
use CCQueue\Tests\Fakes\FakeRedis;
use CCQueue\Tests\Fakes\NullLogger;
use CCQueue\Tests\Fakes\StopWorker;
use CCQueue\Tests\Fakes\SyncBus;
use CCQueue\Tests\Fakes\TestContainer;
use CCQueue\Tests\Jobs\SucceedingJob;
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
                'retry_limit' => $retryLimit,
                'handlers' => array(
                    'type-error' => array('default' => 'CCQueue\Tests\Jobs\TypeErrorJob'),
                    'exception' => array('default' => 'CCQueue\Tests\Jobs\ExceptionJob'),
                    'succeeds' => array('default' => 'CCQueue\Tests\Jobs\SucceedingJob'),
                ),
            ),
        )));
        $this->container->instance('redis', $this->redis);
        $this->container->instance('db', $this->db);
        $this->container->instance('log', $logger);
        $this->container->instance('Psr\Log\LoggerInterface', $logger);
        $this->container->instance('Illuminate\Contracts\Bus\Dispatcher', new SyncBus());

        SucceedingJob::$handled = array();
    }

    private function enqueue($type, $version)
    {
        $dispatcher = new JobDispatcher();

        return $dispatcher->enqueueJob(array('type' => $type, 'data' => array()), $version);
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
}
