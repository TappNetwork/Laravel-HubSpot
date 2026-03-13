<?php

namespace Tapp\LaravelHubspot\Facades;

use Illuminate\Support\Facades\Facade;
use Tapp\LaravelHubspot\LaravelHubspot;

/**
 * @see LaravelHubspot
 *
 * @method static \HubSpot\Discovery\Crm\Discovery crm()
 */
class Hubspot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LaravelHubspot::class;
    }
}
