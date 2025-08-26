<?php

namespace Tapp\LaravelHubspot;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tapp\LaravelHubspot\Commands\SyncHubspotContacts;
use Tapp\LaravelHubspot\Commands\SyncHubspotProperties;

use Tapp\LaravelHubspot\Commands\DebugHubspotData;
use Tapp\LaravelHubspot\Observers\HubspotContactObserver;
use Tapp\LaravelHubspot\Observers\HubspotCompanyObserver;
use Tapp\LaravelHubspot\Services\HubspotContactService;

class LaravelHubspotServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-hubspot')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('add_hubspot_id_to_users_table')
            ->hasCommand(SyncHubspotProperties::class)
            ->hasCommand(SyncHubspotContacts::class)
            ->hasCommand(DebugHubspotData::class);
    }

    public function bootingPackage()
    {
        $this->app->bind(LaravelHubspot::class, function ($app) {

            $stack = new HandlerStack;
            $stack->setHandler(Utils::chooseHandler());

            $stack->push(Middleware::mapRequest(function (RequestInterface $r) {
                if (config('hubspot.log_requests')) {
                    \Illuminate\Support\Facades\Log::info('Hubspot Request: '.$r->getMethod().' '.$r->getUri());
                }

                return $r;
            }));

            $client = new Client(['handler' => $stack]);

            return LaravelHubspot::createWithAccessToken(config('hubspot.api_key'), $client);
        });

        // Register services
        $this->app->singleton(HubspotContactService::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Register observers for models that use HubSpot traits
        $this->registerObservers();
    }

    protected function registerObservers(): void
    {
        // This will be called by the consuming application
        // Users can register observers in their AppServiceProvider
    }
}
