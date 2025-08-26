<?php

namespace Tapp\LaravelHubspot;

class MockHubspotClient
{
    public function crm()
    {
        throw new \Exception('HubSpot client not initialized. Please check your API key configuration.');
    }
}
