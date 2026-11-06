<?php

use HubSpot\Client\Crm\Companies\Model\SimplePublicObject;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;
use Tapp\LaravelHubspot\Facades\Hubspot;
use Tapp\LaravelHubspot\Jobs\SyncHubspotCompanyJob;
use Tapp\LaravelHubspot\Models\HubspotCompany;

test('it extends queue job', function () {
    $job = new SyncHubspotCompanyJob([], 'create', 'TestModel');

    expect($job)->toBeInstanceOf(ShouldQueue::class);
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
    // Override the global Log mock to add specific expectation for error logging
    Log::shouldReceive('error')->once()->with(
        'HubSpot company sync job failed permanently',
        Mockery::any()
    );

    $modelData = ['id' => 1, 'name' => 'Test Company'];
    $job = new SyncHubspotCompanyJob($modelData, 'create', 'TestModel');

    $exception = new Exception('Test failure');
    $job->failed($exception);

    expect(true)->toBeTrue();
});

test('company update persists returned hubspot id when HubSpot redirects a merged company', function () {
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

    Schema::create('hubspot_merge_companies', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('hubspot_id')->nullable();
    });

    $oldId = '1001';
    $newId = '2002';

    $company = HubspotMergeRedirectCompany::query()->create([
        'name' => 'Merged Co',
        'hubspot_id' => $oldId,
    ]);

    $response = new SimplePublicObject;
    $response->setId($newId);
    $response->setProperties(['name' => 'Merged Co']);

    Hubspot::shouldReceive('crm->companies->basicApi->update')
        ->once()
        ->with($oldId, Mockery::any())
        ->andReturn($response);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($oldId, $newId, $company) {
            return str_contains($message, 'merged in HubSpot')
                && ($context['old_hubspot_id'] ?? null) === $oldId
                && ($context['new_hubspot_id'] ?? null) === $newId
                && ($context['model_id'] ?? null) === $company->id;
        });

    $job = new SyncHubspotCompanyJob([
        'id' => $company->id,
        'name' => 'Merged Co',
        'hubspot_id' => $oldId,
        'hubspotMap' => [
            'name' => 'name',
        ],
    ], 'update', HubspotMergeRedirectCompany::class);

    $job->handle();

    $company->refresh();

    expect($company->hubspot_id)->toBe($newId)
        ->and($job->modelData['hubspot_id'])->toBe($newId);
});

class HubspotMergeRedirectCompany extends Model implements HubspotModelInterface
{
    use HubspotCompany;

    protected $table = 'hubspot_merge_companies';

    protected $guarded = [];

    public $timestamps = false;

    public array $hubspotMap = [
        'name' => 'name',
    ];
}
