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
        $container->instance('config', new FakeConfig(array('cc-queue' => array('retry_limit' => 3))));
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
}
