<?php

use Tapp\LaravelHubspot\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('.');

/*
|--------------------------------------------------------------------------
| Test Organization
|--------------------------------------------------------------------------
|
| Organize tests by type and ensure proper setup for each test category
|
*/

// Unit tests - these should be fast and not require external services
uses()->group('unit')->in('Unit');

// Integration tests - these may require external services like HubSpot API
uses()->group('integration')->in('Integration');

// Feature tests - these test the full application flow
uses()->group('feature')->in('Feature');

/*
|--------------------------------------------------------------------------
| Test Environment Setup
|--------------------------------------------------------------------------
|
| Configure test environment based on test type
|
*/

beforeEach(function () {
    // Skip integration tests if no HubSpot API key is configured
    if (str_contains($this->getTestName(), 'Integration') &&
        (! config('hubspot.api_key') || config('hubspot.disabled'))) {
        $this->markTestSkipped('HubSpot API key not configured or disabled for integration tests');
    }
});

/*
|--------------------------------------------------------------------------
| Test Mode Configuration
|--------------------------------------------------------------------------
|
| Configure test mode based on environment variables
|
*/

// Add test mode information to output
echo "\n🧪 Running tests with MOCKED HubSpot API calls\n";
echo "   Set HUBSPOT_USE_REAL_API=true to use real API calls\n\n";
