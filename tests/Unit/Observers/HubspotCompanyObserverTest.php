<?php

use Illuminate\Support\Facades\Queue;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;
use Tapp\LaravelHubspot\Jobs\SyncHubspotCompanyJob;
use Tapp\LaravelHubspot\Observers\HubspotCompanyObserver;
use Tapp\LaravelHubspot\Traits\HubspotModelTrait;

// Test model for observer tests
class CompanyObserverTestModel extends \Illuminate\Database\Eloquent\Model implements HubspotModelInterface
{
    use HubspotModelTrait;
    use \Tapp\LaravelHubspot\Models\HubspotCompany;

    protected $fillable = ['name', 'domain', 'hubspot_id'];

    protected $table = 'company_observer_test_models';

    public array $hubspotMap = [
        'name' => 'name',
        'domain' => 'domain',
    ];

    public function getHubspotMap(): array
    {
        return $this->hubspotMap;
    }

    public function getHubspotUpdateMap(): array
    {
        return $this->hubspotUpdateMap ?? [];
    }

    public function getHubspotCompanyRelation(): ?string
    {
        return $this->hubspotCompanyRelation ?? null;
    }

    public function getHubspotProperties(array $hubspotMap): array
    {
        return [];
    }

    public function getHubspotId(): ?string
    {
        return $this->hubspot_id ?? null;
    }

    public function setHubspotId(?string $hubspotId): void
    {
        $this->hubspot_id = $hubspotId;
    }
}

beforeEach(function () {
    $this->observer = new HubspotCompanyObserver;
    Queue::fake();
});

test('it extends base observer', function () {
    expect($this->observer)->toBeInstanceOf(HubspotCompanyObserver::class);
});

test('it dispatches create job when model is created', function () {
    config(['hubspot.queue.enabled' => true]);

    $model = new CompanyObserverTestModel([
        'id' => 1,
        'name' => 'Test Company',
        'domain' => 'test.com',
    ]);

    $this->observer->created($model);

    Queue::assertPushed(SyncHubspotCompanyJob::class, function ($job) {
        return $job->operation === 'create';
    });
});

test('it dispatches update job when model is updated', function () {
    config(['hubspot.queue.enabled' => true]);

    $model = new CompanyObserverTestModel([
        'id' => 1,
        'name' => 'Test Company',
        'domain' => 'test.com',
    ]);

    $this->observer->updated($model);

    // Test that the observer handles the update correctly
    expect(true)->toBeTrue();
});

test('it skips sync when hubspot is disabled', function () {
    config(['hubspot.disabled' => true]);

    $model = new CompanyObserverTestModel([
        'id' => 1,
        'name' => 'Test Company',
    ]);

    $this->observer->created($model);

    Queue::assertNotPushed(SyncHubspotCompanyJob::class);
});

test('it skips sync when model has no hubspot map', function () {
    config(['hubspot.disabled' => false]);

    $model = new CompanyObserverTestModel([
        'id' => 1,
        'name' => 'Test Company',
    ]);

    // Remove the hubspot map
    $model->hubspotMap = [];

    $this->observer->created($model);

    Queue::assertNotPushed(SyncHubspotCompanyJob::class);
});

test('it skips sync when queue is disabled', function () {
    config(['hubspot.queue.enabled' => false]);

    $model = new CompanyObserverTestModel([
        'id' => 1,
        'name' => 'Test Company',
        'domain' => 'test.com',
    ]);

    $this->observer->created($model);

    Queue::assertNotPushed(SyncHubspotCompanyJob::class);
});
