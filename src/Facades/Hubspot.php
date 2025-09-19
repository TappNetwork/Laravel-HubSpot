<?php

namespace Tapp\LaravelHubspot\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Tapp\LaravelHubspot\LaravelHubspot
 *
 * @method static \HubSpot\Discovery\Crm\Discovery crm()
 */
class Hubspot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Tapp\LaravelHubspot\LaravelHubspot::class;
    }
}
