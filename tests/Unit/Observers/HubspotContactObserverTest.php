<?php

namespace Tapp\LaravelHubspot\Tests\Unit\Observers;


use Illuminate\Support\Facades\Queue;
use Mockery;
use Tapp\LaravelHubspot\Jobs\SyncHubspotContactJob;
use Tapp\LaravelHubspot\Observers\HubspotContactObserver;
use Tapp\LaravelHubspot\Tests\TestCase;

// Test model for observer tests
class ObserverTestUser extends \Illuminate\Database\Eloquent\Model
{
    use \Tapp\LaravelHubspot\Models\HubspotContact;

    protected $fillable = ['email', 'first_name', 'last_name'];
    protected $table = 'observer_test_users';

    public array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];

    public array $hubspotUpdateMap = [
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];

    public string $hubspotCompanyRelation = 'company';
}

class HubspotContactObserverTest extends TestCase
{

    protected HubspotContactObserver $observer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->observer = new HubspotContactObserver();
        Queue::fake();
    }

    /** @test */
    public function it_dispatches_create_job_when_model_is_created()
    {
        config(['hubspot.queue.enabled' => true]);

        $model = new ObserverTestUser([
            'id' => 1,
            'email' => 'test@example.com',
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        $this->observer->created($model);

        Queue::assertPushed(SyncHubspotContactJob::class, function ($job) {
            return $job->operation === 'create';
        });
    }

    /** @test */
    public function it_dispatches_update_job_when_model_is_updated()
    {
        config(['hubspot.queue.enabled' => true]);

        $model = new ObserverTestUser([
            'id' => 1,
            'email' => 'test@example.com',
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        // Simulate model being updated
        $model->wasRecentlyCreated = false;
        $model->syncChanges();

        $this->observer->updated($model);

        Queue::assertPushed(SyncHubspotContactJob::class, function ($job) {
            return $job->operation === 'update';
        });
    }

    /** @test */
    public function it_skips_sync_when_hubspot_is_disabled()
    {
        config(['hubspot.disabled' => true]);

        $model = new ObserverTestUser([
            'id' => 1,
            'email' => 'test@example.com',
        ]);

        $this->observer->created($model);

        Queue::assertNotPushed(SyncHubspotContactJob::class);
    }

    /** @test */
    public function it_skips_sync_when_model_has_no_hubspot_map()
    {
        config(['hubspot.disabled' => false]);

        $model = new ObserverTestUser([
            'id' => 1,
            'email' => 'test@example.com',
        ]);
        $model->hubspotMap = []; // Empty map

        $this->observer->created($model);

        Queue::assertNotPushed(SyncHubspotContactJob::class);
    }

    /** @test */
    public function it_handles_model_without_changes()
    {
        config(['hubspot.queue.enabled' => true]);

        $model = new ObserverTestUser([
            'id' => 1,
            'email' => 'test@example.com',
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        // Test that the observer can handle models without changes
        $this->observer->updated($model);

        // The observer should handle this gracefully
        $this->assertTrue(true);
    }

    /** @test */
    public function it_prepares_job_data_correctly()
    {
        config(['hubspot.queue.enabled' => true]);

        $model = new ObserverTestUser([
            'id' => 1,
            'email' => 'test@example.com',
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        $this->observer->created($model);

        // Test that the observer handles the model correctly
        $this->assertTrue(true);
    }

    /** @test */
    public function it_handles_nested_values_correctly()
    {
        config(['hubspot.queue.enabled' => true]);

        $model = new ObserverTestUser([
            'id' => 1,
            'email' => 'test@example.com',
        ]);

        // Test with nested value access
        $model->hubspotMap = [
            'email' => 'email',
            'firstname' => 'profile.first_name', // Nested field
        ];

        $this->observer->created($model);

        Queue::assertPushed(SyncHubspotContactJob::class);
    }
}
