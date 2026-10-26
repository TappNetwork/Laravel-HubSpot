<?php

use HubSpot\Client\Crm\Contacts\ApiException;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObject;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInputForCreate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
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
        ->twice()
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

    $user = createEmptyIdContactUser([
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'hubspot_id' => null,
    ]);

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
        'id' => $user->id,
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'modelClass' => HubspotEmptyIdContactUser::class,
        'hubspotMap' => [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ];

    $result = $this->service->updateContact($data);

    expect($result)->toBeArray();
    expect($result['id'])->toBe('99999');
    expect($user->fresh()->hubspot_id)->toBe('99999');
});

test('it throws when update has no hubspot id and no mapped email', function () {
    config(['hubspot.api_key' => 'dummy-key']);

    Hubspot::shouldReceive('crm->contacts->searchApi->doSearch')->never();
    Hubspot::shouldReceive('crm->contacts->basicApi->create')->never();
    Hubspot::shouldReceive('crm->contacts->basicApi->update')->never();

    $data = [
        'id' => 1,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'hubspotMap' => [
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ],
    ];

    expect(fn () => $this->service->updateContact($data))
        ->toThrow(Exception::class, 'HubSpot ID missing in model. Cannot update contact');
});

test('it finds by email and updates when hubspot id is invalid', function () {
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

    Hubspot::shouldReceive('crm->contacts->basicApi->getById')
        ->with('invalid-id')
        ->andThrow(new ApiException('Not Found', 404));

    Hubspot::shouldReceive('crm->contacts->searchApi->doSearch')
        ->once()
        ->andReturn($mockSearchResponse);

    Hubspot::shouldReceive('crm->contacts->basicApi->update')
        ->once()
        ->with('99999', Mockery::any())
        ->andReturn($mockUpdateResponse);

    Hubspot::shouldReceive('crm->contacts->basicApi->create')->never();

    $data = [
        'id' => 1,
        'hubspot_id' => 'invalid-id',
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

/**
 * @param  array<string, mixed>  $attributes
 */
function createEmptyIdContactUser(array $attributes): HubspotEmptyIdContactUser
{
    config([
        'database.default' => 'testing',
        'database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
    ]);

    if (! Schema::hasTable('hubspot_empty_id_contact_users')) {
        Schema::create('hubspot_empty_id_contact_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('hubspot_id')->nullable();
        });
    }

    return HubspotEmptyIdContactUser::query()->create($attributes);
}

class HubspotEmptyIdContactUser extends Model implements HubspotModelInterface
{
    use HubspotContact;

    protected $table = 'hubspot_empty_id_contact_users';

    protected $guarded = [];

    public $timestamps = false;

    public array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];
}
