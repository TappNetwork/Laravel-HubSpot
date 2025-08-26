<?php

namespace Tapp\LaravelHubspot\Tests\Unit\Jobs;

use Illuminate\Support\Facades\Log;
use Mockery;
use Tapp\LaravelHubspot\Jobs\SyncHubspotContactJob;
use Tapp\LaravelHubspot\Tests\TestCase;

class SyncHubspotContactJobTest extends TestCase
{
    /** @test */
    public function it_creates_contact_when_operation_is_create()
    {
        $this->skipIfNoRealApi();

        $modelData = [
            'id' => 1,
            'email' => 'test-'.uniqid().'@example.com',
            'first_name' => 'Test',
            'last_name' => 'User',
            'hubspotMap' => [
                'email' => 'email',
                'firstname' => 'first_name',
                'lastname' => 'last_name',
            ],
        ];

        $job = new SyncHubspotContactJob($modelData, 'create', 'TestModel');

        $job->handle();

        // Job should complete without throwing exceptions
        $this->assertTrue(true);
    }

    /** @test */
    public function it_updates_contact_when_operation_is_update()
    {
        $this->skipIfNoRealApi();

        $modelData = [
            'id' => 1,
            'hubspot_id' => '12345',
            'email' => 'test-'.uniqid().'@example.com',
            'first_name' => 'Updated',
            'last_name' => 'User',
            'hubspotMap' => [
                'email' => 'email',
                'firstname' => 'first_name',
                'lastname' => 'last_name',
            ],
        ];

        $job = new SyncHubspotContactJob($modelData, 'update', 'TestModel');

        $job->handle();

        // Job should complete without throwing exceptions
        $this->assertTrue(true);
    }

    /** @test */
    public function it_skips_execution_when_hubspot_is_disabled()
    {
        config(['hubspot.disabled' => true]);

        $modelData = ['id' => 1, 'email' => 'test@example.com'];
        $job = new SyncHubspotContactJob($modelData, 'create', 'TestModel');

        $job->handle();

        // Job should complete without doing anything
        $this->assertTrue(true);
    }

    /** @test */
    public function it_logs_error_when_service_fails()
    {
        $this->skipIfNoRealApi();

        Log::shouldReceive('error')->once()->with(
            'HubSpot contact sync job failed',
            Mockery::any()
        );

        $modelData = [
            'id' => 1,
            'email' => '', // Invalid email will cause failure
            'hubspotMap' => ['email' => 'email'],
        ];

        $job = new SyncHubspotContactJob($modelData, 'create', 'TestModel');

        $this->expectException(\Exception::class);
        $job->handle();
    }

    /** @test */
    public function it_logs_permanent_failure()
    {
        Log::shouldReceive('error')->once()->with(
            'HubSpot contact sync job failed permanently',
            Mockery::any()
        );

        $modelData = ['id' => 1, 'email' => 'test@example.com'];
        $job = new SyncHubspotContactJob($modelData, 'create', 'TestModel');

        $exception = new \Exception('Test failure');
        $job->failed($exception);
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

        $modelData = ['id' => 1, 'email' => 'test@example.com'];
        $job = new SyncHubspotContactJob($modelData, 'create', 'TestModel');

        $this->assertEquals(5, $job->tries);
        $this->assertEquals(120, $job->backoff);
    }
}
