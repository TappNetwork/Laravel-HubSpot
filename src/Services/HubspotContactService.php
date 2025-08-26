<?php

namespace Tapp\LaravelHubspot\Services;

use HubSpot\Client\Crm\Associations\V4\ApiException as AssociationsApiException;
use HubSpot\Client\Crm\Associations\V4\Model\AssociationSpec;
use HubSpot\Client\Crm\Companies\ApiException as CompaniesApiException;
use HubSpot\Client\Crm\Contacts\ApiException;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput as ContactObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Facades\Hubspot;

class HubspotContactService
{
    /**
     * Create a new HubSpot contact.
     */
    public function createContact(array $data, string $modelClass): array
    {
        $properties = $this->buildPropertiesObject($data['hubspotMap'] ?? [], $data);

        try {
            $hubspotContact = Hubspot::crm()->contacts()->basicApi()->create($properties);

            // Update the model with HubSpot ID
            $contactId = is_array($hubspotContact) ? $hubspotContact['id'] : $hubspotContact->getId();
            $this->updateModelHubspotId($data['id'] ?? null, $contactId, $modelClass);

            // Handle company association
            $this->associateCompanyIfNeeded($contactId, $data);

            // Convert SimplePublicObject to array for consistency
            if ($hubspotContact instanceof \HubSpot\Client\Crm\Contacts\Model\SimplePublicObject) {
                return [
                    'id' => $hubspotContact->getId(),
                    'properties' => $hubspotContact->getProperties() ?? [],
                ];
            }

            return $hubspotContact;
        } catch (ApiException $e) {
            // Handle 409 conflict (duplicate email) by finding existing contact
            if ($e->getCode() === 409 && ! empty($data['email'])) {
                Log::info('HubSpot contact already exists, finding by email', [
                    'email' => $data['email'],
                    'error' => $e->getMessage(),
                ]);

                $contact = $this->findContactByEmail($data['email']);
                if ($contact && isset($contact['id'])) {
                    // Update the model with existing HubSpot ID
                    $this->updateModelHubspotId($data['id'] ?? null, $contact['id'], $modelClass);

                    // Handle company association
                    $this->associateCompanyIfNeeded($contact['id'], $data);

                    return $contact;
                }
            }

            // Handle 400 bad request (validation errors)
            if ($e->getCode() === 400) {
                $propertiesArray = $this->buildPropertiesArray($data['hubspotMap'] ?? [], $data);
                Log::error('HubSpot API 400 error - data validation failed', [
                    'email' => $data['email'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'properties_sent' => $propertiesArray,
                    'property_map' => $data['hubspotMap'] ?? [],
                ]);
                throw new \Exception('HubSpot API validation error: '.$e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Update an existing HubSpot contact.
     */
    public function updateContact(array $data): array
    {
        if (empty($data['hubspot_id'])) {
            throw new \Exception('HubSpot ID missing. Cannot update contact: '.($data['email'] ?? 'unknown'));
        }

        // Validate that the contact exists in HubSpot before attempting update
        if (! $this->validateHubspotContactExists($data['hubspot_id'])) {
            // Try to find by email without clearing the invalid ID
            if (! empty($data['email'])) {
                $contact = $this->findContactByEmail($data['email']);
                if ($contact) {
                    // Update with correct hubspot_id and retry
                    $contactId = is_array($contact) ? $contact['id'] : $contact->getId();
                    $this->updateModelHubspotId($data['id'] ?? null, $contactId, $data['modelClass'] ?? null);
                    $data['hubspot_id'] = $contactId;
                } else {
                    // Contact doesn't exist, create it instead
                    return $this->createContact($data, $data['modelClass'] ?? '');
                }
            } else {
                throw new \Exception('Invalid HubSpot ID and no email provided for contact: '.($data['email'] ?? 'unknown'));
            }
        }

        // Use hubspotUpdateMap if defined and not empty, otherwise default to hubspotMap
        $map = (! empty($data['hubspotUpdateMap'])) ? $data['hubspotUpdateMap'] : ($data['hubspotMap'] ?? []);
        $properties = $this->buildPropertiesObject($map, $data);

        try {
            $hubspotContact = Hubspot::crm()->contacts()->basicApi()->update(
                $data['hubspot_id'],
                $properties
            );
        } catch (ApiException $e) {
            if ($e->getCode() === 400) {
                $propertiesArray = $this->buildPropertiesArray($map, $data);
                Log::error('HubSpot API 400 error - data validation failed', [
                    'email' => $data['email'] ?? 'unknown',
                    'hubspot_id' => $data['hubspot_id'],
                    'error' => $e->getMessage(),
                    'properties_sent' => $propertiesArray,
                    'property_map' => $map,
                ]);
                throw new \Exception('HubSpot API validation error: '.$e->getMessage());
            }
            throw $e;
        }

        // Handle company association
        $this->associateCompanyIfNeeded($data['hubspot_id'], $data);

        // Convert SimplePublicObject to array for consistency
        if ($hubspotContact instanceof \HubSpot\Client\Crm\Contacts\Model\SimplePublicObject) {
            return [
                'id' => $hubspotContact->getId(),
                'properties' => $hubspotContact->getProperties() ?? [],
            ];
        }

        return $hubspotContact;
    }

    /**
     * Validate that a HubSpot contact exists by ID.
     */
    protected function validateHubspotContactExists(string $hubspotId): bool
    {
        try {
            Hubspot::crm()->contacts()->basicApi()->getById($hubspotId);

            return true;
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                Log::warning('HubSpot contact not found by ID', [
                    'hubspot_id' => $hubspotId,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
            throw $e;
        }
    }

    /**
     * Find contact by email.
     */
    protected function findContactByEmail(string $email): ?array
    {
        try {
            $contact = Hubspot::crm()->contacts()->basicApi()->getById($email, null, null, null, false, 'email');

            // Convert SimplePublicObject to array for consistency
            if ($contact instanceof \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectWithAssociations) {
                return [
                    'id' => $contact->getId(),
                    'properties' => $contact->getProperties() ?? [],
                ];
            }

            // If it's already an array, return it
            if (is_array($contact)) {
                return $contact;
            }

            // If it's an object with getId method, convert to array
            if (is_object($contact) && method_exists($contact, 'getId')) {
                return [
                    'id' => $contact->getId(),
                    'properties' => $contact->getProperties() ?? [],
                ];
            }

            // Fallback: try to convert to array
            return (array) $contact;
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Find a contact by email or ID.
     */
    public function findContact(array $data): ?array
    {
        if (! empty($data['hubspot_id'])) {
            try {
                return Hubspot::crm()->contacts()->basicApi()->getById($data['hubspot_id']);
            } catch (ApiException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
                // Don't clear invalid hubspot_id, just continue to email lookup
            }
        }

        if (! empty($data['email'])) {
            try {
                $contact = Hubspot::crm()->contacts()->basicApi()->getById($data['email'], null, null, null, false, 'email');

                // Update the model with HubSpot ID
                $contactId = is_array($contact) ? $contact['id'] : $contact->getId();
                $this->updateModelHubspotId($data['id'] ?? null, $contactId, $data['modelClass'] ?? null);

                return $contact;
            } catch (ApiException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }

        return null;
    }

    /**
     * Validate that all properties are properly converted to strings for HubSpot API
     */
    protected function validateHubspotProperties(array $properties): void
    {
        $invalidProperties = [];

        foreach ($properties as $key => $value) {
            if (! is_string($value)) {
                $invalidProperties[$key] = [
                    'value' => $value,
                    'type' => gettype($value),
                    'class' => is_object($value) ? get_class($value) : null,
                ];
            }
        }

        if (! empty($invalidProperties)) {
            throw new \InvalidArgumentException(
                'HubSpot properties must be strings after automatic conversion. Invalid properties found: '.
                json_encode($invalidProperties, JSON_PRETTY_PRINT).
                '. This indicates a data type that could not be automatically converted. Please ensure all properties are convertible to strings.'
            );
        }
    }

    /**
     * Convert a value to a string suitable for HubSpot API
     */
    protected function convertValueForHubspot($value, string $propertyName)
    {
        if (is_null($value)) {
            return null;
        } elseif ($value instanceof \Carbon\Carbon) {
            return $value->toISOString();
        } elseif (is_array($value)) {
            if (empty($value)) {
                return null;
            }

            return (array_keys($value) === range(0, count($value) - 1))
                ? implode(', ', array_filter($value, 'is_scalar'))
                : json_encode($value);
        } elseif (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            } elseif (method_exists($value, 'toArray')) {
                $arrayValue = $value->toArray();

                return is_array($arrayValue) ? json_encode($arrayValue) : (string) $arrayValue;
            } else {
                throw new \InvalidArgumentException(
                    'Cannot convert object of type '.get_class($value)." to string for property: {$propertyName}. ".
                    'Objects must implement __toString() or toArray() methods to be automatically converted.'
                );
            }
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (is_numeric($value)) {
            return (string) $value;
        } else {
            return (string) $value;
        }
    }

    /**
     * Build HubSpot properties array for logging purposes.
     */
    protected function buildPropertiesArray(array $map, array $data): array
    {
        $properties = [];

        foreach ($map as $hubspotProperty => $modelProperty) {
            $value = $this->getNestedValue($data, $modelProperty);

            if ($value !== null) {
                $convertedValue = $this->convertValueForHubspot($value, $hubspotProperty);
                if ($convertedValue !== null) {
                    $properties[$hubspotProperty] = $convertedValue;
                }
            }
        }

        return $properties;
    }

    /**
     * Build HubSpot properties object from data.
     */
    protected function buildPropertiesObject(array $map, array $data): ContactObject
    {
        $properties = [];

        // Process mapped properties
        foreach ($map as $hubspotProperty => $modelProperty) {
            $value = $this->getNestedValue($data, $modelProperty);

            if ($value !== null) {
                $convertedValue = $this->convertValueForHubspot($value, $hubspotProperty);
                if ($convertedValue !== null) {
                    $properties[$hubspotProperty] = $convertedValue;
                }
            }
        }

        // Process dynamic properties that are explicitly added by hubspotProperties method
        if (isset($data['dynamicProperties']) && is_array($data['dynamicProperties'])) {
            foreach ($data['dynamicProperties'] as $hubspotProperty => $value) {
                // Skip if this property is already processed as a mapped property
                if (array_key_exists($hubspotProperty, $map)) {
                    continue;
                }

                // Convert and add dynamic property
                if ($value !== null) {
                    $convertedValue = $this->convertValueForHubspot($value, $hubspotProperty);
                    if ($convertedValue !== null) {
                        $properties[$hubspotProperty] = $convertedValue;
                    }
                }
            }
        }

        // Validate all properties are strings before creating the object
        $this->validateHubspotProperties($properties);

        return new ContactObject(['properties' => $properties]);
    }

    /**
     * Get nested value from array using dot notation.
     */
    protected function getNestedValue(array $array, string $key)
    {
        return data_get($array, $key);
    }

    /**
     * Update the model with HubSpot ID.
     */
    protected function updateModelHubspotId(?int $modelId, string $hubspotId, ?string $modelClass): void
    {
        if (! $modelId || ! $modelClass || ! class_exists($modelClass)) {
            return;
        }

        $model = $modelClass::find($modelId);
        if ($model) {
            $model->update(['hubspot_id' => $hubspotId]);
        }
    }

    /**
     * Associate contact with company if needed.
     */
    protected function associateCompanyIfNeeded(string $contactId, array $data): void
    {
        $companyData = $data['hubspotCompanyRelation'] ?? null;
        if (! $companyData) {
            return;
        }

        // Handle case where companyData is a string (relationship name) instead of array
        if (is_string($companyData)) {
            Log::info('Company data is string, skipping association in service layer', [
                'company_relation' => $companyData,
                'contact_id' => $contactId,
            ]);

            return;
        }

        // If company doesn't have hubspot_id, create it in HubSpot first
        if (empty($companyData['hubspot_id'])) {
            try {
                $companyId = $this->createOrFindCompany($companyData);
                if ($companyId) {
                    $this->associateCompanyWithContact($companyId, $contactId);

                    // Update the company model with the new hubspot_id
                    $this->updateCompanyHubspotId($companyData['id'] ?? null, $companyId, $data['modelClass'] ?? null);
                }
            } catch (\Exception $e) {
                Log::error('Failed to create/sync company for contact association', [
                    'company_name' => $companyData['name'] ?? 'unknown',
                    'contact_id' => $contactId,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        // Verify the company exists in HubSpot before association
        try {
            $this->associateCompanyWithContact($companyData['hubspot_id'], $contactId);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'does not exist in HubSpot')) {
                Log::warning('Company has invalid HubSpot ID, clearing and recreating', [
                    'company_id' => $companyData['id'] ?? null,
                    'invalid_hubspot_id' => $companyData['hubspot_id'],
                    'company_name' => $companyData['name'] ?? 'unknown',
                ]);

                // Clear the invalid hubspot_id and recreate the company
                $this->updateCompanyHubspotId($companyData['id'] ?? null, null, $data['modelClass'] ?? null);

                // Try to create/find the company again
                $companyId = $this->createOrFindCompany($companyData);
                if ($companyId) {
                    $this->associateCompanyWithContact($companyId, $contactId);
                    $this->updateCompanyHubspotId($companyData['id'] ?? null, $companyId, $data['modelClass'] ?? null);
                }
            } else {
                // Log the unexpected error and re-throw
                Log::error('Unexpected error during company association', [
                    'company_id' => $companyData['id'] ?? null,
                    'hubspot_id' => $companyData['hubspot_id'],
                    'contact_id' => $contactId,
                    'error' => $e->getMessage(),
                    'error_code' => method_exists($e, 'getCode') ? $e->getCode() : 'unknown',
                ]);
                throw $e;
            }
        }
    }

    /**
     * Associate a company with a contact in HubSpot.
     */
    protected function associateCompanyWithContact(string $companyId, string $contactId): void
    {
        try {
            // First verify the company exists in HubSpot
            try {
                $company = Hubspot::crm()->companies()->basicApi()->getById($companyId);
                Log::info('Company verified in HubSpot before association', [
                    'company_id' => $companyId,
                    'company_name' => $company->getProperties()['name'] ?? 'unknown',
                ]);
            } catch (CompaniesApiException $e) {
                if ($e->getCode() === 404) {
                    Log::error('Company does not exist in HubSpot, cannot associate', [
                        'company_id' => $companyId,
                        'contact_id' => $contactId,
                    ]);
                    throw new \Exception("Company with ID {$companyId} does not exist in HubSpot");
                }
                throw $e;
            } catch (\Exception $e) {
                // Re-throw any other exceptions
                throw $e;
            }

            $associationSpec = new AssociationSpec([
                'association_category' => 'HUBSPOT_DEFINED',
                'association_type_id' => 1, // Company to Contact association
            ]);

            Hubspot::crm()->associations()->v4()->basicApi()->create(
                'contact',
                $contactId,
                'company',
                $companyId,
                [$associationSpec]
            );
        } catch (AssociationsApiException $e) {
            Log::error('Failed to associate company with contact', [
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Create or find a company in HubSpot.
     */
    protected function createOrFindCompany(array $companyData): ?string
    {
        try {
            // Extract company name - handle translatable fields
            $companyName = $this->extractCompanyName($companyData['name'] ?? '');

            Log::info('Looking for existing company', [
                'company_name' => $companyName,
                'company_data' => $companyData,
            ]);

            // First try to find existing company by name
            try {
                $existingCompany = $this->findCompanyByName($companyName);
                if ($existingCompany) {
                    Log::info('Found existing company', [
                        'company_name' => $companyName,
                        'hubspot_id' => $existingCompany['id'],
                    ]);

                    return $existingCompany['id'];
                }
            } catch (CompaniesApiException $e) {
                // If it's a rate limit, re-throw to let the job retry
                if ($e->getCode() === 429) {
                    throw $e;
                }
                // For other API errors, log and continue to create
                Log::warning('API error during company search, will attempt to create', [
                    'company_name' => $companyName,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('No existing company found, creating new one', [
                'company_name' => $companyName,
            ]);

            // Small delay to prevent race conditions
            usleep(100000); // 0.1 second delay

            // Double-check if company was created by another process
            $existingCompany = $this->findCompanyByName($companyName);
            if ($existingCompany) {
                Log::info('Company was created by another process', [
                    'company_name' => $companyName,
                    'hubspot_id' => $existingCompany['id'],
                ]);

                return $existingCompany['id'];
            }

            // Create new company if not found
            $properties = [
                'name' => $companyName,
            ];

            // Add additional properties if available
            if (! empty($companyData['address'])) {
                $properties['address'] = $companyData['address'];
            }
            if (! empty($companyData['city'])) {
                $properties['city'] = $companyData['city'];
            }
            if (! empty($companyData['state'])) {
                $properties['state'] = $companyData['state'];
            }
            if (! empty($companyData['zip'])) {
                $properties['zip'] = $companyData['zip'];
            }

            // Try to create the company, handle duplicates gracefully
            try {
                $newCompany = Hubspot::crm()->companies()->basicApi()->create(['properties' => $properties]);
                $companyId = is_array($newCompany) ? $newCompany['id'] : $newCompany->getId();

                Log::info('Created new company in HubSpot', [
                    'company_name' => $companyName,
                    'hubspot_id' => $companyId,
                ]);

                return $companyId;
            } catch (CompaniesApiException $e) {
                // Handle 409 conflict (duplicate company created by another process)
                if ($e->getCode() === 409) {
                    Log::info('Company creation conflict, searching for existing company', [
                        'company_name' => $companyName,
                    ]);

                    // Wait a moment and search again
                    usleep(200000); // 0.2 second delay
                    $existingCompany = $this->findCompanyByName($companyName);
                    if ($existingCompany) {
                        Log::info('Found company after conflict resolution', [
                            'company_name' => $companyName,
                            'hubspot_id' => $existingCompany['id'],
                        ]);

                        return $existingCompany['id'];
                    }
                }

                // Re-throw other errors
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Failed to create or find company in HubSpot', [
                'company_name' => $companyData['name'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Find company by name in HubSpot.
     */
    protected function findCompanyByName(string $name): ?array
    {
        try {
            // Clean the name for better matching
            $cleanName = $this->cleanCompanyName($name);

            // First try exact match
            $filter = new \HubSpot\Client\Crm\Companies\Model\Filter([
                'value' => $cleanName,
                'property_name' => 'name',
                'operator' => 'EQ',
            ]);

            $filterGroup = new \HubSpot\Client\Crm\Companies\Model\FilterGroup([
                'filters' => [$filter],
            ]);

            $companySearch = new \HubSpot\Client\Crm\Companies\Model\PublicObjectSearchRequest([
                'filter_groups' => [$filterGroup],
            ]);

            $searchResults = Hubspot::crm()->companies()->searchApi()->doSearch($companySearch);

            if ($searchResults['total'] > 0) {
                $result = $searchResults['results'][0];

                // Convert object to array if needed
                if (is_object($result)) {
                    $result = [
                        'id' => $result->getId(),
                        'properties' => $result->getProperties() ?? [],
                    ];
                }

                Log::info('Found company by exact name match', [
                    'search_name' => $cleanName,
                    'found_name' => $result['properties']['name'] ?? 'unknown',
                    'company_id' => $result['id'],
                ]);

                return $result;
            }

            // If no exact match, try partial match
            $filter = new \HubSpot\Client\Crm\Companies\Model\Filter([
                'value' => $cleanName,
                'property_name' => 'name',
                'operator' => 'CONTAINS_TOKEN',
            ]);

            $filterGroup = new \HubSpot\Client\Crm\Companies\Model\FilterGroup([
                'filters' => [$filter],
            ]);

            $companySearch = new \HubSpot\Client\Crm\Companies\Model\PublicObjectSearchRequest([
                'filter_groups' => [$filterGroup],
            ]);

            $searchResults = Hubspot::crm()->companies()->searchApi()->doSearch($companySearch);

            if ($searchResults['total'] > 0) {
                // Find the best match by comparing names
                $bestMatch = $this->findBestNameMatch($cleanName, $searchResults['results']);
                if ($bestMatch) {
                    // Convert object to array if needed
                    if (is_object($bestMatch)) {
                        $bestMatch = [
                            'id' => $bestMatch->getId(),
                            'properties' => $bestMatch->getProperties() ?? [],
                        ];
                    }

                    Log::info('Found company by partial name match', [
                        'search_name' => $cleanName,
                        'found_name' => $bestMatch['properties']['name'] ?? 'unknown',
                        'company_id' => $bestMatch['id'],
                    ]);

                    return $bestMatch;
                }
            }
        } catch (CompaniesApiException $e) {
            // Handle rate limiting specifically - retry the job
            if ($e->getCode() === 429) {
                Log::warning('Rate limit hit while searching for company, will retry', [
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);
                throw $e; // Let the job retry mechanism handle it
            }

            // Handle 404 - company not found, this is expected
            if ($e->getCode() === 404) {
                Log::info('Company not found by name (404), will create new one', [
                    'name' => $name,
                ]);

                return null; // Return null to indicate company should be created
            }

            // Handle other API errors
            Log::warning('API error while searching for company by name', [
                'name' => $name,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            throw $e; // Re-throw other API errors
        } catch (\Exception $e) {
            Log::warning('Unexpected error while searching for company by name', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return null;
    }

    /**
     * Clean company name for better matching.
     */
    protected function cleanCompanyName(string $name): string
    {
        // Convert to lowercase and trim whitespace
        $name = strtolower(trim($name));

        // Remove common punctuation and extra spaces
        $name = preg_replace('/[^\w\s]/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    /**
     * Find the best matching company name from search results.
     */
    protected function findBestNameMatch(string $searchName, array $results): ?array
    {
        $searchName = strtolower($searchName);
        $bestMatch = null;
        $bestScore = 0;

        foreach ($results as $result) {
            // Convert object to array if needed
            if (is_object($result)) {
                $resultName = strtolower($result->getProperties()['name'] ?? '');
            } else {
                $resultName = strtolower($result['properties']['name'] ?? '');
            }

            $score = similar_text($searchName, $resultName, $percent);

            if ($percent > $bestScore && $percent > 80) { // Require at least 80% similarity
                $bestScore = $percent;
                $bestMatch = $result;
            }
        }

        return $bestMatch;
    }

    /**
     * Extract company name from translatable field or string.
     */
    protected function extractCompanyName($name): string
    {
        if (is_array($name)) {
            // Handle translatable fields - try to get the first available language
            if (isset($name['en'])) {
                return $name['en'];
            }

            // If no 'en' key, get the first value
            return (string) reset($name);
        }

        return (string) $name;
    }

    /**
     * Update the company model with HubSpot ID.
     */
    protected function updateCompanyHubspotId(?int $modelId, ?string $hubspotId, ?string $modelClass): void
    {
        if (! $modelId || ! $modelClass || ! class_exists($modelClass)) {
            return;
        }

        // Try to find the company model and update it
        // Since we don't know the exact company model class, we'll try to infer it
        // from the contact model class (assuming it's in the same namespace)
        $companyModelClass = str_replace('User', 'Agency', $modelClass);

        if (class_exists($companyModelClass)) {
            $company = $companyModelClass::find($modelId);
            if ($company) {
                $company->update(['hubspot_id' => $hubspotId]);
            }
        }
    }
}
