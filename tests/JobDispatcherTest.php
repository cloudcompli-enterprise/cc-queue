<?php

namespace CCQueue\Tests;

use CCQueue\Services\JobDispatcher;
use CCQueue\Tests\Fakes\FakeConfig;
use CCQueue\Tests\Fakes\FakeRedis;
use CCQueue\Tests\Fakes\TestContainer;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class JobDispatcherTest extends TestCase
{
    private $redis;

    /**
     * @before
     */
    public function bootContainer()
    {
        $container = new TestContainer();
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $this->redis = new FakeRedis();
        $container->instance('redis', $this->redis);
        $this->configure(array('retry_limit' => 3));
    }

    private function configure(array $ccQueue)
    {
        Container::getInstance()->instance('config', new FakeConfig(array('cc-queue' => $ccQueue)));
    }

    private function ttl($uuid)
    {
        $key = 'cc-queue:jobs:' . $uuid;

        return isset($this->redis->ttls[$key]) ? $this->redis->ttls[$key] : null;
    }

    private function enqueue($expire = null)
    {
        $dispatcher = new JobDispatcher();

        return $dispatcher->enqueueJob(array('type' => 'succeeds', 'data' => array()), 'default', $expire);
    }

    private function retry(array $payload, $version = null)
    {
        $dispatcher = new JobDispatcher();

        return $dispatcher->retryJob(array_merge(array('uuid' => 'job-1', 'attempts' => 0), $payload), 3, $version);
    }

    private function queued($queue)
    {
        return isset($this->redis->lists[$queue]) ? count($this->redis->lists[$queue]) : 0;
    }

    public function testARetryGoesToTheQueueFamilyItWasClaimedFrom()
    {
        $this->assertTrue($this->retry(array('version' => 'default'), 'legacy'));

        $this->assertSame(1, $this->queued('cc-queue:legacy:tasks'));
        $this->assertSame(0, $this->queued('cc-queue:default:tasks'));
    }

    public function testWithoutAClaimedFamilyARetryFollowsThePayloadsVersion()
    {
        $this->retry(array('version' => 'legacy'));

        $this->assertSame(1, $this->queued('cc-queue:legacy:tasks'));
    }

    public function testWithNeitherARetryGoesToTheDefaultFamily()
    {
        $this->retry(array());

        $this->assertSame(1, $this->queued('cc-queue:default:tasks'));
    }

    public function testARetryKeepsItsPriority()
    {
        $this->retry(array('priority' => 'high'), 'legacy');

        $this->assertSame(1, $this->queued('cc-queue:legacy:tasks:high'));
    }

    public function testEnqueueHonoursAnExplicitExpiry()
    {
        $uuid = $this->enqueue(600);

        $this->assertSame(600, $this->ttl($uuid));
    }

    public function testEnqueueTakesTheConfiguredJobExpiry()
    {
        $this->configure(array('retry_limit' => 3, 'expire_jobs' => 7200));

        $uuid = $this->enqueue();

        $this->assertSame(7200, $this->ttl($uuid));
    }

    /** A worker drops a job whose hash is gone, so a queued job's hash gets no TTL unless one is configured. */
    public function testWithNoJobExpiryConfiguredAQueuedJobsHashGetsNoTtl()
    {
        $uuid = $this->enqueue();

        $this->assertNull($this->ttl($uuid));
    }

    public function testAFinishedJobsHashExpiresAfterADayByDefault()
    {
        $dispatcher = new JobDispatcher();

        $dispatcher->updateJobStatus('done', JobDispatcher::STATUS_COMPLETED, 0);
        $dispatcher->updateJobStatus('broken', JobDispatcher::STATUS_FAILED, 3);

        $this->assertSame(86400, $this->ttl('done'));
        $this->assertSame(86400, $this->ttl('broken'));
    }

    public function testAFinishedJobsHashTakesTheConfiguredStatusExpiry()
    {
        $this->configure(array('retry_limit' => 3, 'expire_status' => 3600));
        $dispatcher = new JobDispatcher();

        $dispatcher->updateJobStatus('broken', JobDispatcher::STATUS_FAILED, 3);

        $this->assertSame(3600, $this->ttl('broken'));
    }

    public function testAPendingOrInProgressUpdateSetsNoTtl()
    {
        $dispatcher = new JobDispatcher();

        $dispatcher->updateJobStatus('waiting', JobDispatcher::STATUS_PENDING, 1);
        $dispatcher->updateJobStatus('running', JobDispatcher::STATUS_IN_PROGRESS, 0);

        $this->assertNull($this->ttl('waiting'));
        $this->assertNull($this->ttl('running'));
    }

    public function testUpdateHonoursAnExplicitExpiry()
    {
        $dispatcher = new JobDispatcher();

        $dispatcher->updateJobStatus('waiting', JobDispatcher::STATUS_PENDING, 1, 300);

        $this->assertSame(300, $this->ttl('waiting'));
    }

    public function testANullRetryLimitFallsBackToTheConfiguredOne()
    {
        $dispatcher = new JobDispatcher();

        $requeued = $dispatcher->retryJob(array('uuid' => 'job-1', 'attempts' => 0), null, 'legacy');

        $this->assertTrue($requeued);
        $this->assertSame(1, $this->queued('cc-queue:legacy:tasks'));
    }

    public function testAnExplicitRetryLimitIsUsedAsGiven()
    {
        $dispatcher = new JobDispatcher();

        $requeued = $dispatcher->retryJob(array('uuid' => 'job-1', 'attempts' => 1), 2, 'legacy');

        $this->assertFalse($requeued);
        $this->assertSame(0, $this->queued('cc-queue:legacy:tasks'));
    }
}
