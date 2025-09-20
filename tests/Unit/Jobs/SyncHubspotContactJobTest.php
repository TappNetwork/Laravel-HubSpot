<?php

use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Jobs\SyncHubspotContactJob;

test('it creates contact when operation is create', function () {
    test()->skipIfNoRealApi();

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
    expect(true)->toBeTrue();
});

test('it updates contact when operation is update', function () {
    test()->skipIfNoRealApi();

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
    expect(true)->toBeTrue();
});

test('it skips execution when hubspot is disabled', function () {
    config(['hubspot.disabled' => true]);

    $modelData = ['id' => 1, 'email' => 'test@example.com'];
    $job = new SyncHubspotContactJob($modelData, 'create', 'TestModel');

    $job->handle();

    // Job should complete without doing anything
    expect(true)->toBeTrue();
});

test('it logs error when service fails', function () {
    test()->skipIfNoRealApi();

    // Override the global Log mock to add specific expectation for error logging
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

    expect(fn () => $job->handle())->toThrow(\Exception::class);
});

test('it logs permanent failure', function () {
    test()->skipIfNoRealApi();

    // Override the global Log mock to add specific expectation for error logging
    Log::shouldReceive('error')->once()->with(
        'HubSpot contact sync job failed permanently',
        Mockery::any()
    );

    $modelData = [
        'id' => 1,
        'email' => '', // Invalid email will cause failure
        'hubspotMap' => ['email' => 'email'],
    ];

    $job = new SyncHubspotContactJob($modelData, 'create', 'TestModel');

    expect(fn () => $job->failed(new \Exception('Test failure')))->not->toThrow();
});

test('it handles delete operation', function () {
    test()->skipIfNoRealApi();

    $modelData = [
        'id' => 1,
        'hubspot_id' => '12345',
        'email' => 'test@example.com',
    ];

    $job = new SyncHubspotContactJob($modelData, 'delete', 'TestModel');

    $job->handle();

    // Job should complete without throwing exceptions
    expect(true)->toBeTrue();
});

test('it skips execution when no api key is configured', function () {
    config(['hubspot.api_key' => null]);

    $modelData = ['id' => 1, 'email' => 'test@example.com'];
    $job = new SyncHubspotContactJob($modelData, 'create', 'TestModel');

    expect(fn () => $job->handle())->toThrow(\Exception::class, 'HubSpot client not initialized. Please check your API key configuration.');
});
