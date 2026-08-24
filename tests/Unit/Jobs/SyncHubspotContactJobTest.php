<?php

use HubSpot\Client\Crm\Contacts\Model\SimplePublicObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;
use Tapp\LaravelHubspot\Facades\Hubspot;
use Tapp\LaravelHubspot\Jobs\SyncHubspotContactJob;
use Tapp\LaravelHubspot\Models\HubspotContact;

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

    expect(fn () => $job->handle())->toThrow(Exception::class);
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

    expect(fn () => $job->failed(new Exception('Test failure')))->not->toThrow();
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

    expect(fn () => $job->handle())->toThrow(Exception::class, 'HubSpot client not initialized. Please check your API key configuration.');
});

test('it rebuilds the payload from the live model and treats a stored id as update', function () {
    config([
        'hubspot.api_key' => 'dummy-key',
        'hubspot.disabled' => false,
        'database.default' => 'testing',
        'database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
    ]);

    Schema::create('hubspot_job_refresh_users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('hubspot_id')->nullable();
    });

    $user = HubspotJobRefreshUser::query()->create([
        'email' => 'fresh@example.com',
        'first_name' => 'Fresh',
        'last_name' => 'User',
        'hubspot_id' => '99999',
    ]);

    $mockResponse = new SimplePublicObject;
    $mockResponse->setId('99999');
    $mockResponse->setProperties([
        'email' => 'fresh@example.com',
        'firstname' => 'Fresh',
        'lastname' => 'User',
    ]);

    Hubspot::shouldReceive('crm->contacts->basicApi->getById')
        ->with('99999')
        ->andReturn(['id' => '99999']);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with('99999', Mockery::any())
        ->andReturn($mockResponse);

    Hubspot::shouldReceive('crm->contacts->basicApi->create')->never();

    $job = new SyncHubspotContactJob([
        'id' => $user->id,
        'hubspot_id' => null,
        'email' => 'stale@example.com',
        'first_name' => 'Stale',
        'last_name' => 'User',
        'hubspotMap' => [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ], 'update', HubspotJobRefreshUser::class);

    $job->handle();

    expect($job->operation)->toBe('update')
        ->and($job->modelData['hubspot_id'])->toBe('99999')
        ->and($job->modelData['first_name'])->toBe('Fresh')
        ->and($job->modelData['email'])->toBe('fresh@example.com');
});

class HubspotJobRefreshUser extends Model implements HubspotModelInterface
{
    use HubspotContact;

    protected $table = 'hubspot_job_refresh_users';

    protected $guarded = [];

    public $timestamps = false;

    public array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];
}
