<?php

namespace Tapp\LaravelHubspot\Tests\Unit\Jobs;

use Illuminate\Support\Facades\Log;
use Mockery;
use Tapp\LaravelHubspot\Jobs\SyncHubspotCompanyJob;
use Tapp\LaravelHubspot\Tests\TestCase;

class SyncHubspotCompanyJobTest extends TestCase
{
    /** @test */
    public function it_extends_queue_job()
    {
        $job = new SyncHubspotCompanyJob([], 'create', 'TestModel');

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
    }

    /** @test */
    public function it_has_correct_properties()
    {
        $modelData = ['id' => 1, 'name' => 'Test Company'];
        $job = new SyncHubspotCompanyJob($modelData, 'create', 'TestModel');

        $this->assertEquals($modelData, $job->modelData);
        $this->assertEquals('create', $job->operation);
        $this->assertEquals('TestModel', $job->modelClass);
    }

    /** @test */
    public function it_uses_correct_queue_configuration()
    {
        config([
            'hubspot.queue.retry_attempts' => 5,
            'hubspot.queue.retry_delay' => 120,
            'hubspot.queue.queue' => 'hubspot-queue',
            'hubspot.queue.connection' => 'redis',
        ]);

        $modelData = ['id' => 1, 'name' => 'Test Company'];
        $job = new SyncHubspotCompanyJob($modelData, 'create', 'TestModel');

        $this->assertEquals(5, $job->tries);
        $this->assertEquals(120, $job->backoff);
    }

    /** @test */
    public function it_skips_execution_when_hubspot_is_disabled()
    {
        config(['hubspot.disabled' => true]);

        $modelData = ['id' => 1, 'name' => 'Test Company'];
        $job = new SyncHubspotCompanyJob($modelData, 'create', 'TestModel');

        $job->handle();

        // Job should complete without doing anything
        $this->assertTrue(true);
    }

    /** @test */
    public function it_logs_permanent_failure()
    {
        Log::shouldReceive('error')->once()->with(
            'HubSpot company sync job failed permanently',
            Mockery::any()
        );

        $modelData = ['id' => 1, 'name' => 'Test Company'];
        $job = new SyncHubspotCompanyJob($modelData, 'create', 'TestModel');

        $exception = new \Exception('Test failure');
        $job->failed($exception);
    }
}
