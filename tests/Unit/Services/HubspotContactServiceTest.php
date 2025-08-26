<?php

use Tapp\LaravelHubspot\Services\HubspotContactService;

beforeEach(function () {
    $this->service = new HubspotContactService;
});

test('it builds properties object correctly', function () {
    $map = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];

    $data = [
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ];

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildPropertiesObject');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $map, $data);

    expect($result)->toBeInstanceOf(\HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput::class);
    $properties = $result->getProperties();

    expect($properties['email'])->toBe('test@example.com');
    expect($properties['firstname'])->toBe('John');
    expect($properties['lastname'])->toBe('Doe');
});

test('it builds properties array correctly', function () {
    $map = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];

    $data = [
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ];

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildPropertiesArray');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $map, $data);

    expect($result)->toBe([
        'email' => 'test@example.com',
        'firstname' => 'John',
        'lastname' => 'Doe',
    ]);
});

test('it validates contact exists', function () {
    test()->skipIfNoRealApi();

    // Test with a real contact ID (this will fail but we can test the method)
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('validateHubspotContactExists');
    $method->setAccessible(true);

    // This should return false for a non-existent contact
    $result = $method->invoke($this->service, '999999999');
    expect($result)->toBeFalse();
});

test('it finds contact by email', function () {
    test()->skipIfNoRealApi();

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('findContactByEmail');
    $method->setAccessible(true);

    // Test with non-existent email
    $result = $method->invoke($this->service, 'nonexistent@example.com');
    expect($result)->toBeNull();
});

test('it handles empty data array', function () {
    $map = [
        'email' => 'email',
        'firstname' => 'first_name',
    ];

    $data = [];

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildPropertiesArray');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $map, $data);

    expect($result)->toBe([]);
});

test('it handles empty map array', function () {
    $map = [];

    $data = [
        'email' => 'test@example.com',
        'first_name' => 'John',
    ];

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildPropertiesArray');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, $map, $data);

    expect($result)->toBe([]);
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
