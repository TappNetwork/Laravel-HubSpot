<?php

use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;
use Tapp\LaravelHubspot\Models\HubspotContact;
use Tapp\LaravelHubspot\Services\HubspotContactService;

// Create a test model that uses the trait
class TestUserModel implements HubspotModelInterface
{
    use HubspotContact;

    public $id = 1;

    public $email = 'test@example.com';

    public $first_name = 'John';

    public $last_name = 'Doe';

    public $hubspot_id = null;

    public array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];

    public function getKey()
    {
        return $this->id;
    }

    public function getHubspotMap(): array
    {
        return $this->hubspotMap;
    }

    public function getHubspotUpdateMap(): array
    {
        return [];
    }

    public function getHubspotCompanyRelation(): ?string
    {
        return null;
    }

    public function getHubspotProperties(array $hubspotMap): array
    {
        return [];
    }

    public function getHubspotId(): ?string
    {
        return $this->hubspot_id;
    }

    public function setHubspotId(?string $hubspotId): void
    {
        $this->hubspot_id = $hubspotId;
    }

    public function getRelationValue(string $relation)
    {
        return null;
    }
}

beforeEach(function () {
    // Mock the Log facade to prevent "Call to a member function __call() on null" errors
    Log::shouldReceive('info', 'warning', 'error', 'debug')
        ->andReturnSelf();

    $this->model = new TestUserModel;
});

test('updateOrCreateHubspotContact calls createContact when no hubspot_id exists', function () {
    $mockService = Mockery::mock(HubspotContactService::class);
    $mockService->shouldReceive('createContact')
        ->once()
        ->with(Mockery::type('array'), TestUserModel::class)
        ->andReturn(['id' => '12345', 'properties' => ['email' => 'test@example.com']]);

    app()->instance(HubspotContactService::class, $mockService);

    $result = $this->model->updateOrCreateHubspotContact();

    expect($result)->toBeArray();
    expect($result['id'])->toBe('12345');
});

test('updateOrCreateHubspotContact calls updateContact when hubspot_id exists', function () {
    // Set hubspot_id on the model
    $this->model->setHubspotId('12345');

    $mockService = Mockery::mock(HubspotContactService::class);
    $mockService->shouldReceive('updateContact')
        ->once()
        ->with(Mockery::type('array'))
        ->andReturn(['id' => '12345', 'properties' => ['email' => 'test@example.com']]);

    app()->instance(HubspotContactService::class, $mockService);

    $result = $this->model->updateOrCreateHubspotContact();

    expect($result)->toBeArray();
    expect($result['id'])->toBe('12345');
});

test('updateOrCreateHubspotContact falls back to createContact when updateContact fails', function () {
    // Set hubspot_id on the model
    $this->model->setHubspotId('12345');

    $mockService = Mockery::mock(HubspotContactService::class);
    $mockService->shouldReceive('updateContact')
        ->once()
        ->with(Mockery::type('array'))
        ->andThrow(new \Exception('Update failed'));

    $mockService->shouldReceive('createContact')
        ->once()
        ->with(Mockery::type('array'), TestUserModel::class)
        ->andReturn(['id' => '67890', 'properties' => ['email' => 'test@example.com']]);

    app()->instance(HubspotContactService::class, $mockService);

    $result = $this->model->updateOrCreateHubspotContact();

    expect($result)->toBeArray();
    expect($result['id'])->toBe('67890');
});

test('updateOrCreateHubspotContact includes all required data', function () {
    $mockService = Mockery::mock(HubspotContactService::class);
    $mockService->shouldReceive('createContact')
        ->once()
        ->with(Mockery::type('array'), TestUserModel::class)
        ->andReturn(['id' => '12345', 'properties' => []]);

    app()->instance(HubspotContactService::class, $mockService);

    $result = $this->model->updateOrCreateHubspotContact();

    expect($result)->toBeArray();
    expect($result['id'])->toBe('12345');
});
