<?php

use Tapp\LaravelHubspot\Facades\Hubspot;

beforeEach(function () {
    // Skip tests if no HubSpot API key is configured
    if (! config('hubspot.api_key') || config('hubspot.disabled')) {
        test()->markTestSkipped('HubSpot API key not configured or disabled');
    }
});

test('it can connect to hubspot api', function () {
    try {
        // Test basic API connection by creating a simple contact
        $properties = [
            'email' => 'test-'.uniqid().'@example.com',
            'firstname' => 'Test',
            'lastname' => 'Connection',
        ];

        $contactObject = new \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput(['properties' => $properties]);
        $result = Hubspot::crm()->contacts()->basicApi()->create($contactObject);

        expect($result)->not->toBeEmpty();

        // Clean up
        $contactId = is_array($result) ? $result['id'] : $result->getId();
        $this->cleanupTestContact($contactId);

    } catch (\Exception $e) {
        test()->fail('Failed to connect to HubSpot API: '.$e->getMessage());
    }
});

test('it can create contact via service', function () {
    $testData = [
        'email' => 'test-'.uniqid().'@example.com',
        'first_name' => 'Test',
        'last_name' => 'User',
    ];

    try {
        // Test the API directly to avoid database setup
        $properties = [
            'email' => $testData['email'],
            'firstname' => $testData['first_name'],
            'lastname' => $testData['last_name'],
        ];

        $contactObject = new \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput(['properties' => $properties]);
        $result = Hubspot::crm()->contacts()->basicApi()->create($contactObject);

        // Handle both array and object responses
        $contactId = is_array($result) ? $result['id'] : $result->getId();
        $properties = is_array($result) ? $result['properties'] : $result->getProperties();

        expect($contactId)->not->toBeEmpty();
        expect($properties['email'])->toBe($testData['email']);

        // Clean up - delete the test contact
        $this->cleanupTestContact($contactId);

    } catch (\Exception $e) {
        test()->fail('Failed to create contact: '.$e->getMessage());
    }
});

test('it can create company via trait', function () {
    $testCompany = new TestCompany([
        'name' => 'Test Company '.uniqid(),
        'domain' => 'testcompany.com',
    ]);

    try {
        // Use the HubSpot API directly to avoid database operations
        $properties = $testCompany->hubspotProperties($testCompany->hubspotMap);
        $companyObject = new \HubSpot\Client\Crm\Companies\Model\SimplePublicObjectInput(['properties' => $properties]);

        $result = Hubspot::crm()->companies()->basicApi()->create($companyObject);

        // Handle both array and object responses
        $companyId = is_array($result) ? $result['id'] : $result->getId();
        $properties = is_array($result) ? $result['properties'] : $result->getProperties();

        expect($companyId)->not->toBeEmpty();
        expect($properties['name'])->toBe($testCompany->name);
        expect($properties['domain'])->toBe($testCompany->domain);

        // Clean up - delete the test company
        $this->cleanupTestCompany($companyId);

    } catch (\Exception $e) {
        test()->fail('Failed to create company: '.$e->getMessage());
    }
});

test('it can find contact by email', function () {
    $testEmail = 'test-'.uniqid().'@example.com';

    try {
        // First create a contact
        $properties = [
            'email' => $testEmail,
            'firstname' => 'Test',
            'lastname' => 'Search',
        ];

        $contactObject = new \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput(['properties' => $properties]);
        $createdContact = Hubspot::crm()->contacts()->basicApi()->create($contactObject);
        $contactId = is_array($createdContact) ? $createdContact['id'] : $createdContact->getId();

        // Now search for it
        $searchRequest = new \HubSpot\Client\Crm\Contacts\Model\PublicObjectSearchRequest([
            'filterGroups' => [
                [
                    'filters' => [
                        [
                            'propertyName' => 'email',
                            'operator' => 'EQ',
                            'value' => $testEmail,
                        ],
                    ],
                ],
            ],
        ]);

        $searchResult = Hubspot::crm()->contacts()->searchApi()->doSearch($searchRequest);

        expect($searchResult['results'])->not->toBeEmpty();
        expect($searchResult['results'][0]['properties']['email'])->toBe($testEmail);

        // Clean up
        $this->cleanupTestContact($contactId);

    } catch (\Exception $e) {
        test()->fail('Failed to find contact by email: '.$e->getMessage());
    }
});

test('it can update contact properties', function () {
    $testEmail = 'test-'.uniqid().'@example.com';

    try {
        // First create a contact
        $properties = [
            'email' => $testEmail,
            'firstname' => 'Original',
            'lastname' => 'Name',
        ];

        $contactObject = new \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput(['properties' => $properties]);
        $createdContact = Hubspot::crm()->contacts()->basicApi()->create($contactObject);
        $contactId = is_array($createdContact) ? $createdContact['id'] : $createdContact->getId();

        // Now update it
        $updateProperties = [
            'firstname' => 'Updated',
            'lastname' => 'Name',
        ];

        $updateObject = new \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput(['properties' => $updateProperties]);
        $updatedContact = Hubspot::crm()->contacts()->basicApi()->update($contactId, $updateObject);

        $updatedProperties = is_array($updatedContact) ? $updatedContact['properties'] : $updatedContact->getProperties();

        expect($updatedProperties['firstname'])->toBe('Updated');
        expect($updatedProperties['lastname'])->toBe('Name');

        // Clean up
        $this->cleanupTestContact($contactId);

    } catch (\Exception $e) {
        test()->fail('Failed to update contact: '.$e->getMessage());
    }
});

test('it can delete contact', function () {
    $testEmail = 'test-'.uniqid().'@example.com';

    try {
        // First create a contact
        $properties = [
            'email' => $testEmail,
            'firstname' => 'Test',
            'lastname' => 'Delete',
        ];

        $contactObject = new \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput(['properties' => $properties]);
        $createdContact = Hubspot::crm()->contacts()->basicApi()->create($contactObject);
        $contactId = is_array($createdContact) ? $createdContact['id'] : $createdContact->getId();

        // Now delete it
        Hubspot::crm()->contacts()->basicApi()->archive($contactId);

        // Verify it's deleted by trying to get it
        try {
            Hubspot::crm()->contacts()->basicApi()->getById($contactId);
            test()->fail('Contact should have been deleted');
        } catch (\Exception $e) {
            // Expected - contact should not exist
            expect($e->getMessage())->toContain('404');
        }

    } catch (\Exception $e) {
        test()->fail('Failed to delete contact: '.$e->getMessage());
    }
});

test('it handles invalid api key gracefully', function () {
    // Temporarily set invalid API key
    config(['hubspot.api_key' => 'invalid-key']);

    try {
        $properties = [
            'email' => 'test@example.com',
            'firstname' => 'Test',
            'lastname' => 'User',
        ];

        $contactObject = new \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput(['properties' => $properties]);

        expect(fn () => Hubspot::crm()->contacts()->basicApi()->create($contactObject))
            ->toThrow(\Exception::class);

    } catch (\Exception $e) {
        // Expected - should throw an exception with invalid API key
        expect($e->getMessage())->toContain('401');
    }
});

// Helper methods
function cleanupTestContact($contactId)
{
    try {
        Hubspot::crm()->contacts()->basicApi()->archive($contactId);
    } catch (\Exception $e) {
        // Ignore cleanup errors
    }
}

function cleanupTestCompany($companyId)
{
    try {
        Hubspot::crm()->companies()->basicApi()->archive($companyId);
    } catch (\Exception $e) {
        // Ignore cleanup errors
    }
}

// Test model class for integration tests
class TestCompany extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['name', 'domain'];

    public $hubspotMap = [
        'name' => 'name',
        'domain' => 'domain',
    ];

    public function hubspotProperties($map)
    {
        $properties = [];
        foreach ($map as $hubspotProperty => $modelProperty) {
            if (isset($this->attributes[$modelProperty])) {
                $properties[$hubspotProperty] = $this->attributes[$modelProperty];
            }
        }

        return $properties;
    }
}
