<?php

use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Jobs\SyncHubspotCompanyJob;

test('it extends queue job', function () {
    $job = new SyncHubspotCompanyJob([], 'create', 'TestModel');

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('it has correct properties', function () {
    $modelData = ['id' => 1, 'name' => 'Test Company'];
    $job = new SyncHubspotCompanyJob($modelData, 'create', 'TestModel');

    expect($job->modelData)->toBe($modelData);
    expect($job->operation)->toBe('create');
    expect($job->modelClass)->toBe('TestModel');
});

test('it uses correct queue configuration', function () {
    config([
        'hubspot.queue.retry_attempts' => 5,
        'hubspot.queue.retry_delay' => 120,
        'hubspot.queue.queue' => 'hubspot-queue',
        'hubspot.queue.connection' => 'redis',
    ]);

    $modelData = ['id' => 1, 'name' => 'Test Company'];
    $job = new SyncHubspotCompanyJob($modelData, 'create', 'TestModel');

    expect($job->tries)->toBe(5);
    expect($job->backoff)->toBe(120);
});

test('it skips execution when hubspot is disabled', function () {
    config(['hubspot.disabled' => true]);

    $modelData = ['id' => 1, 'name' => 'Test Company'];
    $job = new SyncHubspotCompanyJob($modelData, 'create', 'TestModel');

    $job->handle();

    // Job should complete without doing anything
    expect(true)->toBeTrue();
});

test('it logs permanent failure', function () {
    // Create a more robust mock for the Log facade
    // This handles cases where Laravel internally calls channel() before logging
    $logMock = Mockery::mock('alias:Illuminate\Support\Facades\Log');

    // Mock channel() to return self (fluent interface)
    $logMock->shouldReceive('channel')
        ->andReturnSelf();

    // Mock the error call that we expect to happen
    $logMock->shouldReceive('error')
        ->once()
        ->with(
            'HubSpot company sync job failed permanently',
            Mockery::any()
        );

    // Allow other log methods that might be called
    $logMock->shouldReceive('info', 'warning', 'debug')
        ->andReturnSelf();

    $modelData = ['id' => 1, 'name' => 'Test Company'];
    $job = new SyncHubspotCompanyJob($modelData, 'create', 'TestModel');

    $exception = new \Exception('Test failure');
    $job->failed($exception);

    expect(true)->toBeTrue();
});
