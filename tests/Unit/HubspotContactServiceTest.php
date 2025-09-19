<?php

use HubSpot\Client\Crm\Contacts\Model\SimplePublicObject;
use Tapp\LaravelHubspot\Facades\Hubspot;
use Tapp\LaravelHubspot\Services\HubspotContactService;

beforeEach(function () {
    $this->service = app(HubspotContactService::class);
});

test('it builds properties object correctly', function () {
    $data = [
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ];

    $map = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];

    // Use reflection to test protected method
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('buildPropertiesObject');
    $method->setAccessible(true);

    $properties = $method->invoke($this->service, $map, $data);

    expect($properties)->toBeInstanceOf(\HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInputForCreate::class);
    expect($properties->getProperties()['email'])->toBe('test@example.com');
    expect($properties->getProperties()['firstname'])->toBe('John');
    expect($properties->getProperties()['lastname'])->toBe('Doe');
});

test('it handles dynamic properties in data array', function () {
    $data = [
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
        // Dynamic properties in separate array
        'dynamicProperties' => [
            'course_progress' => '75%',
            'courses_completed' => '3',
            'last_course_access' => '2024-01-15',
        ],
    ];

    $map = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];

    // Use reflection to test protected method
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('buildPropertiesObject');
    $method->setAccessible(true);

    $properties = $method->invoke($this->service, $map, $data);

    expect($properties)->toBeInstanceOf(\HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInputForCreate::class);

    // Check mapped properties
    expect($properties->getProperties()['email'])->toBe('test@example.com');
    expect($properties->getProperties()['firstname'])->toBe('John');
    expect($properties->getProperties()['lastname'])->toBe('Doe');

    // Check dynamic properties
    expect($properties->getProperties()['course_progress'])->toBe('75%');
    expect($properties->getProperties()['courses_completed'])->toBe('3');
    expect($properties->getProperties()['last_course_access'])->toBe('2024-01-15');
});

test('it creates contact successfully', function () {
    // Set a dummy API key to prevent initialization error
    config(['hubspot.api_key' => 'dummy-key']);

    // Mock the HubSpot API response
    $mockResponse = new SimplePublicObject;
    $mockResponse->setId('12345');
    $mockResponse->setProperties([
        'email' => 'test@example.com',
        'firstname' => 'John',
        'lastname' => 'Doe',
    ]);

    // Mock the HubSpot facade
    Hubspot::shouldReceive('crm->contacts->basicApi->create')
        ->once()
        ->andReturn($mockResponse);

    $data = [
        'id' => 1,
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'hubspotMap' => [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ];

    $result = $this->service->createContact($data, 'TestModel');

    expect($result)->toBeArray();
    expect($result['id'])->toBe('12345');
    expect($result['properties']['email'])->toBe('test@example.com');
});

test('it updates contact successfully', function () {
    // Set a dummy API key to prevent initialization error
    config(['hubspot.api_key' => 'dummy-key']);

    // Mock the HubSpot API response
    $mockResponse = new SimplePublicObject;
    $mockResponse->setId('12345');
    $mockResponse->setProperties([
        'email' => 'test@example.com',
        'firstname' => 'John',
        'lastname' => 'Doe',
    ]);

    // Mock the HubSpot facade
    Hubspot::shouldReceive('crm->contacts->basicApi->getById')
        ->with('12345')
        ->andReturn(['id' => '12345']);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with('12345', Mockery::any())
        ->andReturn($mockResponse);

    $data = [
        'id' => 1,
        'hubspot_id' => '12345',
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'hubspotMap' => [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ];

    $result = $this->service->updateContact($data);

    expect($result)->toBeArray();
    expect($result['id'])->toBe('12345');
    expect($result['properties']['email'])->toBe('test@example.com');
});

test('it skips execution when hubspot is disabled', function () {
    config(['hubspot.disabled' => true]);

    $data = [
        'id' => 1,
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'hubspotMap' => [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ];

    expect(fn () => $this->service->createContact($data, 'TestModel'))
        ->toThrow(\Exception::class, 'HubSpot client not initialized. Please check your API key configuration.');
});

test('it skips execution when no api key is configured', function () {
    config(['hubspot.api_key' => null]);

    $data = [
        'id' => 1,
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'hubspotMap' => [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ];

    expect(fn () => $this->service->createContact($data, 'TestModel'))
        ->toThrow(\Exception::class, 'HubSpot client not initialized. Please check your API key configuration.');
});
