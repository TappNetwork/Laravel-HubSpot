<?php

namespace Tapp\LaravelHubspot\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use Tapp\LaravelHubspot\LaravelHubspotServiceProvider;

class TestCase extends Orchestra
{
    /**
     * @var \Illuminate\Testing\TestResponse|null
     */
    public static $latestResponse;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Tapp\\LaravelHubspot\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelHubspotServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        // Set up HubSpot configuration for testing
        config()->set('hubspot.api_key', env('HUBSPOT_TEST_API_KEY'));
        config()->set('hubspot.disabled', env('HUBSPOT_DISABLED', false));
        config()->set('hubspot.log_requests', env('HUBSPOT_LOG_REQUESTS', false));
        config()->set('hubspot.property_group', env('HUBSPOT_PROPERTY_GROUP', 'test_property_group'));
        config()->set('hubspot.property_group_label', env('HUBSPOT_PROPERTY_GROUP_LABEL', 'Test Property Group'));

        // Queue configuration for testing
        config()->set('hubspot.queue.enabled', env('HUBSPOT_QUEUE_ENABLED', false));
        config()->set('hubspot.queue.connection', 'sync');
        config()->set('hubspot.queue.queue', 'default');
        config()->set('hubspot.queue.retry_attempts', 1);
        config()->set('hubspot.queue.retry_delay', 1);

        /*
        $migration = include __DIR__.'/../database/migrations/create_laravel-hubspot_table.php.stub';
        $migration->up();
        */
    }

    /**
     * Check if we should use real API calls instead of mocks
     */
    protected function useRealApi(): bool
    {
        return env('HUBSPOT_USE_REAL_API', false) &&
               config('hubspot.api_key') &&
               ! config('hubspot.disabled');
    }

    /**
     * Skip test if real API is not available
     */
    protected function skipIfNoRealApi(): void
    {
        if (! $this->useRealApi()) {
            $this->markTestSkipped('Real API testing disabled. Set HUBSPOT_USE_REAL_API=true and provide HUBSPOT_TEST_API_KEY to enable.');
        }
    }

    /**
     * Skip test if real API is available (for mocked tests)
     */
    protected function skipIfRealApi(): void
    {
        if ($this->useRealApi()) {
            $this->markTestSkipped('Real API testing enabled. This test uses mocks and should be skipped when using real API.');
        }
    }
}
