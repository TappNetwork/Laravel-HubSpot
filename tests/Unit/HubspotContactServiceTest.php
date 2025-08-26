<?php

namespace Tapp\LaravelHubspot\Tests\Unit;

use HubSpot\Client\Crm\Contacts\ApiException;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObject;
use Mockery;
use Tapp\LaravelHubspot\Facades\Hubspot;
use Tapp\LaravelHubspot\Services\HubspotContactService;
use Tapp\LaravelHubspot\Tests\TestCase;

class HubspotContactServiceTest extends TestCase
{
    protected HubspotContactService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HubspotContactService::class);
    }

    /** @test */
    public function it_builds_properties_object_correctly()
    {
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

        $this->assertInstanceOf(\HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput::class, $properties);
        $this->assertEquals('test@example.com', $properties->getProperties()['email']);
        $this->assertEquals('John', $properties->getProperties()['firstname']);
        $this->assertEquals('Doe', $properties->getProperties()['lastname']);
    }

    /** @test */
    public function it_creates_contact_successfully()
    {
        // Mock the HubSpot API response
        $mockResponse = new SimplePublicObject;
        $mockResponse->setId('12345');
        $mockResponse->setProperties([
            'email' => 'test@example.com',
            'firstname' => 'John',
            'lastname' => 'Doe',
        ]);

        // Mock the HubSpot facade using Laravel's facade mocking
        $mockBasicApi = Mockery::mock();
        $mockBasicApi->shouldReceive('create')
            ->once()
            ->andReturn($mockResponse);

        $mockContacts = Mockery::mock();
        $mockContacts->shouldReceive('basicApi')
            ->andReturn($mockBasicApi);

        $mockCrm = Mockery::mock();
        $mockCrm->shouldReceive('contacts')
            ->andReturn($mockContacts);

        Hubspot::shouldReceive('crm')
            ->andReturn($mockCrm);

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

        $result = $this->service->createContact($data, 'TestUser');

        // Test passes if the method completes without throwing an exception
        $this->assertTrue(true);
    }

    /** @test */
    public function it_handles_api_exception_during_contact_creation()
    {
        // Mock API exception
        $mockBasicApi = Mockery::mock();
        $mockBasicApi->shouldReceive('create')
            ->once()
            ->andThrow(new ApiException('API Error', 400));

        $mockContacts = Mockery::mock();
        $mockContacts->shouldReceive('basicApi')
            ->andReturn($mockBasicApi);

        $mockCrm = Mockery::mock();
        $mockCrm->shouldReceive('contacts')
            ->andReturn($mockContacts);

        Hubspot::shouldReceive('crm')
            ->andReturn($mockCrm);

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

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('HubSpot API validation error: API Error');

        $this->service->createContact($data, 'TestUser');
    }

    /** @test */
    public function it_updates_contact_successfully()
    {
        // Mock the HubSpot API response
        $mockResponse = new SimplePublicObject;
        $mockResponse->setId('12345');
        $mockResponse->setProperties([
            'email' => 'test@example.com',
            'firstname' => 'Updated',
            'lastname' => 'Name',
        ]);

        // Mock the HubSpot facade
        $mockBasicApi = Mockery::mock();
        $mockBasicApi->shouldReceive('update')
            ->once()
            ->with('12345', Mockery::any())
            ->andReturn($mockResponse);
        $mockBasicApi->shouldReceive('getById')
            ->with('12345')
            ->andReturn(['id' => '12345']);

        $mockContacts = Mockery::mock();
        $mockContacts->shouldReceive('basicApi')
            ->andReturn($mockBasicApi);

        $mockCrm = Mockery::mock();
        $mockCrm->shouldReceive('contacts')
            ->andReturn($mockContacts);

        Hubspot::shouldReceive('crm')
            ->andReturn($mockCrm);

        $data = [
            'id' => 1,
            'hubspot_id' => '12345',
            'email' => 'test@example.com',
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'hubspotMap' => [
                'email' => 'email',
                'firstname' => 'first_name',
                'lastname' => 'last_name',
            ],
        ];

        $result = $this->service->updateContact($data, 'TestUser');

        // Test passes if the method completes without throwing an exception
        $this->assertTrue(true);
    }

    /** @test */
    public function it_validates_contact_exists()
    {
        // Mock successful contact retrieval
        $mockBasicApi = Mockery::mock();
        $mockBasicApi->shouldReceive('getById')
            ->with('12345')
            ->andReturn(['id' => '12345']);

        $mockContacts = Mockery::mock();
        $mockContacts->shouldReceive('basicApi')
            ->andReturn($mockBasicApi);

        $mockCrm = Mockery::mock();
        $mockCrm->shouldReceive('contacts')
            ->andReturn($mockContacts);

        Hubspot::shouldReceive('crm')
            ->andReturn($mockCrm);

        // Use reflection to test protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateHubspotContactExists');
        $method->setAccessible(true);

        $exists = $method->invoke($this->service, '12345');
        $this->assertTrue($exists);
    }

    /** @test */
    public function it_returns_false_for_nonexistent_contact()
    {
        // Mock 404 response
        $mockBasicApi = Mockery::mock();
        $mockBasicApi->shouldReceive('getById')
            ->with('999999')
            ->andThrow(new ApiException('Not found', 404));

        $mockContacts = Mockery::mock();
        $mockContacts->shouldReceive('basicApi')
            ->andReturn($mockBasicApi);

        $mockCrm = Mockery::mock();
        $mockCrm->shouldReceive('contacts')
            ->andReturn($mockContacts);

        Hubspot::shouldReceive('crm')
            ->andReturn($mockCrm);

        // Use reflection to test protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateHubspotContactExists');
        $method->setAccessible(true);

        $exists = $method->invoke($this->service, '999999');
        $this->assertFalse($exists);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

// Test model class for unit tests
class UnitTestUser extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['email', 'first_name', 'last_name'];
}
f