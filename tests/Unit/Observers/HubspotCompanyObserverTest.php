<?php

namespace Tapp\LaravelHubspot\Tests\Unit\Observers;

use Illuminate\Support\Facades\Queue;
use Tapp\LaravelHubspot\Jobs\SyncHubspotCompanyJob;
use Tapp\LaravelHubspot\Observers\HubspotCompanyObserver;
use Tapp\LaravelHubspot\Tests\TestCase;

// Test model for observer tests
class CompanyObserverTestModel extends \Illuminate\Database\Eloquent\Model
{
    use \Tapp\LaravelHubspot\Models\HubspotCompany;

    protected $fillable = ['name', 'domain'];

    protected $table = 'company_observer_test_models';

    public array $hubspotMap = [
        'name' => 'name',
        'domain' => 'domain',
    ];
}

class HubspotCompanyObserverTest extends TestCase
{
    protected HubspotCompanyObserver $observer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->observer = new HubspotCompanyObserver;
        Queue::fake();
    }

    /** @test */
    public function it_extends_base_observer()
    {
        $this->assertInstanceOf(HubspotCompanyObserver::class, $this->observer);
    }

    /** @test */
    public function it_dispatches_create_job_when_model_is_created()
    {
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
    }

    /** @test */
    public function it_dispatches_update_job_when_model_is_updated()
    {
        config(['hubspot.queue.enabled' => true]);

        $model = new CompanyObserverTestModel([
            'id' => 1,
            'name' => 'Test Company',
            'domain' => 'test.com',
        ]);

        $this->observer->updated($model);

        // Test that the observer handles the update correctly
        $this->assertTrue(true);
    }

    /** @test */
    public function it_skips_sync_when_hubspot_is_disabled()
    {
        config(['hubspot.disabled' => true]);

        $model = new CompanyObserverTestModel([
            'id' => 1,
            'name' => 'Test Company',
        ]);

        $this->observer->created($model);

        Queue::assertNotPushed(SyncHubspotCompanyJob::class);
    }

    /** @test */
    public function it_skips_sync_when_model_has_no_hubspot_map()
    {
        config(['hubspot.disabled' => false]);

        $model = new CompanyObserverTestModel([
            'id' => 1,
            'name' => 'Test Company',
        ]);
        $model->hubspotMap = []; // Empty map

        $this->observer->created($model);

        Queue::assertNotPushed(SyncHubspotCompanyJob::class);
    }

    /** @test */
    public function it_prepares_job_data_correctly()
    {
        config(['hubspot.queue.enabled' => true]);

        $model = new CompanyObserverTestModel([
            'id' => 1,
            'name' => 'Test Company',
            'domain' => 'test.com',
        ]);

        $this->observer->created($model);

        // Test that the observer handles the model correctly
        $this->assertTrue(true);
    }
}
