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

        /*
        $migration = include __DIR__.'/../database/migrations/create_laravel-hubspot_table.php.stub';
        $migration->up();
        */
    }
}
