<?php

use HubSpot\Client\Crm\Contacts\ApiException;
use HubSpot\Client\Crm\Contacts\Model\Error;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObject;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInputForCreate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;
use Tapp\LaravelHubspot\Facades\Hubspot;
use Tapp\LaravelHubspot\Models\HubspotContact;
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
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildPropertiesObject');
    $method->setAccessible(true);

    $properties = $method->invoke($this->service, $map, $data);

    expect($properties)->toBeInstanceOf(SimplePublicObjectInputForCreate::class);
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
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildPropertiesObject');
    $method->setAccessible(true);

    $properties = $method->invoke($this->service, $map, $data);

    expect($properties)->toBeInstanceOf(SimplePublicObjectInputForCreate::class);

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

    $mockSearchResponse = Mockery::mock();
    $mockSearchResponse->shouldReceive('getTotal')->andReturn(0);

    // Mock the HubSpot facade
    Hubspot::shouldReceive('crm->contacts->searchApi->doSearch')
        ->once()
        ->andReturn($mockSearchResponse);

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

test('it updates existing contact instead of creating when secondary mapped email already exists', function () {
    config(['hubspot.api_key' => 'dummy-key']);

    $existingContact = new SimplePublicObject;
    $existingContact->setId('99999');
    $existingContact->setProperties([
        'email' => 'login@example.com',
        'firstname' => 'Jane',
        'lastname' => 'Doe',
    ]);

    $mockSearchResponse = Mockery::mock();
    $mockSearchResponse->shouldReceive('getTotal')->andReturn(1);
    $mockSearchResponse->shouldReceive('getResults')->andReturn([$existingContact]);

    $mockUpdateResponse = new SimplePublicObject;
    $mockUpdateResponse->setId('99999');
    $mockUpdateResponse->setProperties([
        'email' => 'work@example.com',
        'firstname' => 'Jane',
        'lastname' => 'Doe',
    ]);

    Hubspot::shouldReceive('crm->contacts->searchApi->doSearch')
        ->once()
        ->andReturn($mockSearchResponse);

    Hubspot::shouldReceive('crm->contacts->basicApi->getById')
        ->with('99999')
        ->andReturn(['id' => '99999']);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with('99999', Mockery::any())
        ->andReturn($mockUpdateResponse);

    Hubspot::shouldReceive('crm->contacts->basicApi->create')->never();

    $data = [
        'id' => 1,
        'email' => 'login@example.com',
        'secondary_email' => 'work@example.com',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'hubspotMap' => [
            'email' => 'secondary_email',
            'secondary_email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ];

    $result = $this->service->createContact($data, 'TestModel');

    expect($result)->toBeArray();
    expect($result['id'])->toBe('99999');
});

test('it collects mapped email values from configured contact_email_properties', function () {
    $data = [
        'email' => 'login@example.com',
        'secondary_email' => 'work@example.com',
        'hubspotMap' => [
            'email' => 'secondary_email',
            'secondary_email' => 'email',
        ],
    ];

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('getMappedEmailValues');
    $method->setAccessible(true);

    $emails = $method->invoke($this->service, $data);

    expect($emails)->toContain('work@example.com')
        ->and($emails)->toContain('login@example.com')
        ->and($emails)->toHaveCount(2);
});

test('it collects custom email properties when configured', function () {
    config([
        'hubspot.contact_email_properties' => [
            'email',
            'work_email',
            'personal_email',
        ],
    ]);

    $data = [
        'login_email' => 'login@example.com',
        'work' => 'work@example.com',
        'personal' => 'personal@example.com',
        'hubspotMap' => [
            'email' => 'login_email',
            'work_email' => 'work',
            'personal_email' => 'personal',
            'secondary_email' => 'login_email',
        ],
    ];

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('getMappedEmailValues');
    $method->setAccessible(true);

    $emails = $method->invoke($this->service, $data);

    expect($emails)->toContain('login@example.com')
        ->and($emails)->toContain('work@example.com')
        ->and($emails)->toContain('personal@example.com')
        ->and($emails)->toHaveCount(3);
});

test('it ignores unconfigured email map keys when collecting emails', function () {
    config([
        'hubspot.contact_email_properties' => [
            'email',
        ],
    ]);

    $data = [
        'email' => 'login@example.com',
        'secondary_email' => 'work@example.com',
        'hubspotMap' => [
            'email' => 'email',
            'secondary_email' => 'secondary_email',
        ],
    ];

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('getMappedEmailValues');
    $method->setAccessible(true);

    $emails = $method->invoke($this->service, $data);

    expect($emails)->toBe(['login@example.com']);
});

test('it creates or finds a contact when update has no hubspot id', function () {
    config(['hubspot.api_key' => 'dummy-key']);

    $mockResponse = new SimplePublicObject;
    $mockResponse->setId('12345');
    $mockResponse->setProperties([
        'email' => 'test@example.com',
        'firstname' => 'John',
        'lastname' => 'Doe',
    ]);

    $mockSearchResponse = Mockery::mock();
    $mockSearchResponse->shouldReceive('getTotal')->andReturn(0);

    Hubspot::shouldReceive('crm->contacts->searchApi->doSearch')
        ->once()
        ->andReturn($mockSearchResponse);

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

    $result = $this->service->updateContact($data);

    expect($result)->toBeArray();
    expect($result['id'])->toBe('12345');
});

test('it updates an existing contact when update has no hubspot id but email matches', function () {
    config(['hubspot.api_key' => 'dummy-key']);

    $existingContact = new SimplePublicObject;
    $existingContact->setId('99999');
    $existingContact->setProperties([
        'email' => 'test@example.com',
    ]);

    $mockSearchResponse = Mockery::mock();
    $mockSearchResponse->shouldReceive('getTotal')->andReturn(1);
    $mockSearchResponse->shouldReceive('getResults')->andReturn([$existingContact]);

    $mockUpdateResponse = new SimplePublicObject;
    $mockUpdateResponse->setId('99999');
    $mockUpdateResponse->setProperties([
        'email' => 'test@example.com',
        'firstname' => 'John',
    ]);

    Hubspot::shouldReceive('crm->contacts->searchApi->doSearch')
        ->once()
        ->andReturn($mockSearchResponse);

    Hubspot::shouldReceive('crm->contacts->basicApi->getById')
        ->with('99999')
        ->andReturn(['id' => '99999']);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with('99999', Mockery::any())
        ->andReturn($mockUpdateResponse);

    Hubspot::shouldReceive('crm->contacts->basicApi->create')->never();

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

    $result = $this->service->updateContact($data);

    expect($result)->toBeArray();
    expect($result['id'])->toBe('99999');
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

test('it relinks and updates when email already belongs to another HubSpot contact (Error response)', function () {
    config(['hubspot.api_key' => 'dummy-key']);

    $orphanedId = '260844424983';
    $existingId = '254751827741';
    $email = 'sarah.roberts@ncchs.org';
    $conflictMessage = "Cannot set PropertyValueCoordinates{portalId=21668608, objectTypeId=ObjectTypeId{legacyObjectType=CONTACT}, propertyName=email, value={$email}} on {$orphanedId}. {$existingId} already has that value.";

    $conflictError = new Error([
        'message' => $conflictMessage,
        'category' => 'VALIDATION_ERROR',
    ]);

    $existingContact = new SimplePublicObject;
    $existingContact->setId($existingId);
    $existingContact->setProperties([
        'email' => $email,
        'firstname' => 'Sarah',
        'lastname' => 'Roberts',
    ]);

    $mockSearchResponse = Mockery::mock();
    $mockSearchResponse->shouldReceive('getTotal')->andReturn(1);
    $mockSearchResponse->shouldReceive('getResults')->andReturn([$existingContact]);

    $mockUpdateResponse = new SimplePublicObject;
    $mockUpdateResponse->setId($existingId);
    $mockUpdateResponse->setProperties([
        'email' => $email,
        'firstname' => 'Sarah',
        'lastname' => 'Roberts',
    ]);

    Hubspot::shouldReceive('crm->contacts->basicApi->getById')
        ->with($orphanedId)
        ->andReturn(['id' => $orphanedId]);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with($orphanedId, Mockery::any())
        ->andReturn($conflictError);

    Hubspot::shouldReceive('crm->contacts->searchApi->doSearch')
        ->once()
        ->andReturn($mockSearchResponse);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with($existingId, Mockery::on(function ($properties) use ($email) {
            $props = $properties->getProperties();

            // Email already matches the existing contact, so it should be omitted.
            return ! array_key_exists('email', $props)
                && ($props['firstname'] ?? null) === 'Sarah'
                && ($props['lastname'] ?? null) === 'Roberts'
                && ! str_contains(strtolower(json_encode($props) ?: ''), strtolower($email));
        }))
        ->andReturn($mockUpdateResponse);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($orphanedId, $existingId, $email) {
            return str_contains($message, 'does not merge or delete')
                && ($context['orphan_hubspot_id'] ?? null) === $orphanedId
                && ($context['survivor_hubspot_id'] ?? null) === $existingId
                && ($context['model_id'] ?? null) === 196
                && ($context['email'] ?? null) === $email;
        });

    $data = [
        'id' => 196,
        'hubspot_id' => $orphanedId,
        'email' => $email,
        'first_name' => 'Sarah',
        'last_name' => 'Roberts',
        'hubspotMap' => [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ];

    $result = $this->service->updateContact($data);

    expect($result)->toBeArray();
    expect($result['id'])->toBe($existingId);
});

test('it relinks and updates when email uniqueness conflict is thrown as ApiException', function () {
    config(['hubspot.api_key' => 'dummy-key']);

    $orphanedId = '111';
    $existingId = '222';
    $email = 'dup@example.com';
    $conflictMessage = "Cannot set PropertyValueCoordinates{portalId=1, objectTypeId=ObjectTypeId{legacyObjectType=CONTACT}, propertyName=email, value={$email}} on {$orphanedId}. {$existingId} already has that value.";

    $existingContact = new SimplePublicObject;
    $existingContact->setId($existingId);
    $existingContact->setProperties([
        'email' => $email,
        'firstname' => 'Dup',
        'lastname' => 'User',
    ]);

    $mockSearchResponse = Mockery::mock();
    $mockSearchResponse->shouldReceive('getTotal')->andReturn(1);
    $mockSearchResponse->shouldReceive('getResults')->andReturn([$existingContact]);

    $mockUpdateResponse = new SimplePublicObject;
    $mockUpdateResponse->setId($existingId);
    $mockUpdateResponse->setProperties([
        'email' => $email,
        'firstname' => 'Dup',
        'lastname' => 'User',
    ]);

    Hubspot::shouldReceive('crm->contacts->basicApi->getById')
        ->with($orphanedId)
        ->andReturn(['id' => $orphanedId]);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with($orphanedId, Mockery::any())
        ->andThrow(new ApiException($conflictMessage, 400));

    Hubspot::shouldReceive('crm->contacts->searchApi->doSearch')
        ->once()
        ->andReturn($mockSearchResponse);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with($existingId, Mockery::on(function ($properties) {
            $props = $properties->getProperties();

            return ! array_key_exists('email', $props)
                && ($props['firstname'] ?? null) === 'Dup';
        }))
        ->andReturn($mockUpdateResponse);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($orphanedId, $existingId) {
            return str_contains($message, 'Merge the orphan into the survivor manually')
                && ($context['orphan_hubspot_id'] ?? null) === $orphanedId
                && ($context['survivor_hubspot_id'] ?? null) === $existingId
                && ($context['model_id'] ?? null) === 2;
        });

    $data = [
        'id' => 2,
        'hubspot_id' => $orphanedId,
        'email' => $email,
        'first_name' => 'Dup',
        'last_name' => 'User',
        'hubspotMap' => [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ];

    $result = $this->service->updateContact($data);

    expect($result)->toBeArray();
    expect($result['id'])->toBe($existingId);
});

test('it uses parsed contact id when email search misses during uniqueness conflict', function () {
    config(['hubspot.api_key' => 'dummy-key']);

    $orphanedId = '333';
    $existingId = '444';
    $email = 'fallback@example.com';
    $conflictMessage = "Cannot set PropertyValueCoordinates{propertyName=email, value={$email}} on {$orphanedId}. {$existingId} already has that value.";

    $conflictError = new Error([
        'message' => $conflictMessage,
        'category' => 'CONFLICT',
    ]);

    $existingContact = new SimplePublicObject;
    $existingContact->setId($existingId);
    $existingContact->setProperties([
        'email' => $email,
        'firstname' => 'Fall',
        'lastname' => 'Back',
    ]);

    $emptySearchResponse = Mockery::mock();
    $emptySearchResponse->shouldReceive('getTotal')->andReturn(0);

    $mockUpdateResponse = new SimplePublicObject;
    $mockUpdateResponse->setId($existingId);
    $mockUpdateResponse->setProperties([
        'email' => $email,
        'firstname' => 'Fall',
        'lastname' => 'Back',
    ]);

    Hubspot::shouldReceive('crm->contacts->basicApi->getById')
        ->with($orphanedId)
        ->andReturn(['id' => $orphanedId]);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with($orphanedId, Mockery::any())
        ->andReturn($conflictError);

    Hubspot::shouldReceive('crm->contacts->searchApi->doSearch')
        ->once()
        ->andReturn($emptySearchResponse);

    Hubspot::shouldReceive('crm->contacts->basicApi->getById')
        ->once()
        ->with($existingId)
        ->andReturn($existingContact);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with($existingId, Mockery::any())
        ->andReturn($mockUpdateResponse);

    Log::shouldReceive('warning')->once()->withArgs(fn ($message, $context) => ($context['orphan_hubspot_id'] ?? null) === $orphanedId
        && ($context['survivor_hubspot_id'] ?? null) === $existingId
        && ($context['model_id'] ?? null) === 3);

    $data = [
        'id' => 3,
        'hubspot_id' => $orphanedId,
        'email' => $email,
        'first_name' => 'Fall',
        'last_name' => 'Back',
        'hubspotMap' => [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ];

    $result = $this->service->updateContact($data);

    expect($result['id'])->toBe($existingId);
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
        ->toThrow(Exception::class, 'HubSpot client not initialized. Please check your API key configuration.');
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
        ->toThrow(Exception::class, 'HubSpot client not initialized. Please check your API key configuration.');
});

test('it persists returned hubspot id when HubSpot redirects a merged contact on update', function () {
    config([
        'hubspot.api_key' => 'dummy-key',
        'database.default' => 'testing',
        'database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
    ]);

    Schema::create('hubspot_merge_users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('hubspot_id')->nullable();
    });

    $oldId = '260844424983';
    $newId = '260767299847';
    $email = 'merged@example.com';

    $user = HubspotMergeRedirectUser::query()->create([
        'email' => $email,
        'first_name' => 'Merged',
        'last_name' => 'User',
        'hubspot_id' => $oldId,
    ]);

    $mergedContact = new SimplePublicObject;
    $mergedContact->setId($newId);
    $mergedContact->setProperties([
        'email' => $email,
        'firstname' => 'Merged',
        'lastname' => 'User',
        'hs_merged_object_ids' => "{$oldId};254751827741",
    ]);

    // getById on the stale id returns the surviving merged contact
    Hubspot::shouldReceive('crm->contacts->basicApi->getById')
        ->with($oldId)
        ->andReturn($mergedContact);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with($newId, Mockery::any())
        ->andReturn($mergedContact);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($oldId, $newId, $user) {
            return str_contains($message, 'merged in HubSpot')
                && ($context['old_hubspot_id'] ?? null) === $oldId
                && ($context['new_hubspot_id'] ?? null) === $newId
                && ($context['model_id'] ?? null) === $user->id
                && ($context['reason'] ?? null) === 'merged in HubSpot';
        });

    $result = $this->service->updateContact([
        'id' => $user->id,
        'hubspot_id' => $oldId,
        'email' => $email,
        'first_name' => 'Merged',
        'last_name' => 'User',
        'modelClass' => HubspotMergeRedirectUser::class,
        'hubspotMap' => [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ]);

    $user->refresh();

    expect($result['id'])->toBe($newId)
        ->and($user->hubspot_id)->toBe($newId);
});

test('it does not change hubspot id when HubSpot returns the same id on update', function () {
    config([
        'hubspot.api_key' => 'dummy-key',
        'database.default' => 'testing',
        'database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
    ]);

    Schema::create('hubspot_merge_stable_users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('hubspot_id')->nullable();
    });

    $hubspotId = '111222333';
    $email = 'stable@example.com';

    $user = HubspotMergeStableUser::query()->create([
        'email' => $email,
        'first_name' => 'Stable',
        'last_name' => 'User',
        'hubspot_id' => $hubspotId,
    ]);

    $contact = new SimplePublicObject;
    $contact->setId($hubspotId);
    $contact->setProperties([
        'email' => $email,
        'firstname' => 'Stable',
        'lastname' => 'User',
    ]);

    Hubspot::shouldReceive('crm->contacts->basicApi->getById')
        ->with($hubspotId)
        ->andReturn($contact);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with($hubspotId, Mockery::any())
        ->andReturn($contact);

    Log::shouldReceive('warning')
        ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'merged in HubSpot'))
        ->never();

    $result = $this->service->updateContact([
        'id' => $user->id,
        'hubspot_id' => $hubspotId,
        'email' => $email,
        'first_name' => 'Stable',
        'last_name' => 'User',
        'modelClass' => HubspotMergeStableUser::class,
        'hubspotMap' => [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ]);

    $user->refresh();

    expect($result['id'])->toBe($hubspotId)
        ->and($user->hubspot_id)->toBe($hubspotId);
});

class HubspotMergeRedirectUser extends Model implements HubspotModelInterface
{
    use HubspotContact;

    protected $table = 'hubspot_merge_users';

    protected $guarded = [];

    public $timestamps = false;

    public array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];
}

class HubspotMergeStableUser extends Model implements HubspotModelInterface
{
    use HubspotContact;

    protected $table = 'hubspot_merge_stable_users';

    protected $guarded = [];

    public $timestamps = false;

    public array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];
}
