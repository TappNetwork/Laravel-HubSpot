<?php

namespace Tapp\LaravelHubspot\Services;

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

            // Check if response is an Error object
            if ($hubspotContact instanceof \HubSpot\Client\Crm\Contacts\Model\Error) {
                throw new \Exception('HubSpot API returned an error: '.$hubspotContact->getMessage());
            }

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
            $emailField = $this->getMappedEmailField($data);
            if ($e->getCode() === 409 && $emailField) {
                Log::info('HubSpot contact already exists, finding by email', [
                    'email' => $emailField,
                    'error' => $e->getMessage(),
                ]);

                $contact = $this->findContact($data);
                if ($contact && isset($contact['id'])) {
                    // Update the model with existing HubSpot ID
                    $this->updateModelHubspotId($data['id'] ?? null, $contact['id'], $modelClass);

                    // Update the contact properties with new data
                    $data['hubspot_id'] = $contact['id'];
                    $data['modelClass'] = $modelClass;
                    $this->updateContact($data);

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
        } catch (\Exception $e) {
            // Handle "Contact already exists" error that might not be an ApiException
            $emailField = $this->getMappedEmailField($data);
            if (str_contains($e->getMessage(), 'Contact already exists') && $emailField) {
                Log::info('HubSpot contact already exists, finding by email', [
                    'email' => $emailField,
                    'error' => $e->getMessage(),
                ]);

                $contact = $this->findContact($data);
                if ($contact && isset($contact['id'])) {
                    // Update the model with existing HubSpot ID
                    $this->updateModelHubspotId($data['id'] ?? null, $contact['id'], $modelClass);

                    // Update the contact properties with new data
                    $data['hubspot_id'] = $contact['id'];
                    $data['modelClass'] = $modelClass;
                    $this->updateContact($data);

                    // Handle company association
                    $this->associateCompanyIfNeeded($contact['id'], $data);

                    return $contact;
                }
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
            $emailField = $this->getMappedEmailField($data);
            if ($emailField) {
                $contact = $this->findContact($data);
                if ($contact) {
                    // Update with correct hubspot_id and retry
                    $contactId = $contact['id'];
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

        // Log the properties being sent for debugging
        $propertiesArray = $this->buildPropertiesArray($map, $data);
        Log::info('Updating HubSpot contact with properties', [
            'hubspot_id' => $data['hubspot_id'],
            'email' => $data['email'] ?? 'unknown',
            'properties_sent' => $propertiesArray,
            'property_map' => $map,
        ]);

        try {
            $hubspotContact = Hubspot::crm()->contacts()->basicApi()->update(
                $data['hubspot_id'],
                $properties
            );

            // Check if response is an Error object
            if ($hubspotContact instanceof \HubSpot\Client\Crm\Contacts\Model\Error) {
                throw new \Exception('HubSpot API returned an error: '.$hubspotContact->getMessage());
            }
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
            /** @var \HubSpot\Client\Crm\Contacts\Model\SimplePublicObject $hubspotContact */
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
     * Find a contact by email or ID.
     */
    public function findContact(array $data): ?array
    {
        if (! empty($data['hubspot_id'])) {
            try {
                $contact = Hubspot::crm()->contacts()->basicApi()->getById($data['hubspot_id']);

                // Check if response is an Error object
                if ($contact instanceof \HubSpot\Client\Crm\Contacts\Model\Error) {
                    throw new \Exception('HubSpot API returned an error: '.$contact->getMessage());
                }

                return $this->normalizeContactResponse($contact);
            } catch (ApiException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
                // Don't clear invalid hubspot_id, just continue to email lookup
            }
        }

        // Use the mapped email field from hubspotMap instead of data['email'] directly
        $emailField = $this->getMappedEmailField($data);
        if ($emailField) {
            try {
                $contact = Hubspot::crm()->contacts()->basicApi()->getById($emailField, null, null, null, false, 'email');

                // Check if response is an Error object
                if ($contact instanceof \HubSpot\Client\Crm\Contacts\Model\Error) {
                    throw new \Exception('HubSpot API returned an error: '.$contact->getMessage());
                }

                // Update the model with HubSpot ID
                $contactId = is_array($contact) ? $contact['id'] : $contact->getId();
                $this->updateModelHubspotId($data['id'] ?? null, $contactId, $data['modelClass'] ?? null);

                return $this->normalizeContactResponse($contact);
            } catch (ApiException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }

        return null;
    }

    /**
     * Normalize contact response to consistent array format.
     */
    protected function normalizeContactResponse($contact): array
    {
        // Convert SimplePublicObject to array for consistency
        if ($contact instanceof \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectWithAssociations) {
            /** @var \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectWithAssociations $contact */
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
                'properties' => method_exists($contact, 'getProperties') ? ($contact->getProperties() ?? []) : [],
            ];
        }

        // Fallback: try to convert to array
        return (array) $contact;
    }

    /**
     * Build HubSpot properties array for logging purposes.
     */
    protected function buildPropertiesArray(array $map, array $data): array
    {
        $properties = [];

        // Process mapped properties
        foreach ($map as $hubspotProperty => $modelProperty) {
            $value = PropertyConverter::getNestedValue($data, $modelProperty);

            if ($value !== null) {
                $convertedValue = PropertyConverter::convertValueForHubspot($value, $hubspotProperty);
                if ($convertedValue !== null) {
                    $properties[$hubspotProperty] = $convertedValue;
                }
            }
        }

        // Process dynamic properties that are explicitly added by hubspotProperties method
        if (isset($data['dynamicProperties']) && is_array($data['dynamicProperties'])) {
            foreach ($data['dynamicProperties'] as $hubspotProperty => $value) {
                // If this property is in the map but wasn't found above, use the dynamic property
                // Otherwise, add it if it's not already in the map
                if (array_key_exists($hubspotProperty, $map)) {
                    // Only add if it wasn't already processed above
                    if (! array_key_exists($hubspotProperty, $properties)) {
                        $convertedValue = PropertyConverter::convertValueForHubspot($value, $hubspotProperty);
                        if ($convertedValue !== null) {
                            $properties[$hubspotProperty] = $convertedValue;
                        }
                    }
                } else {
                    // Convert and add dynamic property
                    if ($value !== null) {
                        $convertedValue = PropertyConverter::convertValueForHubspot($value, $hubspotProperty);
                        if ($convertedValue !== null) {
                            $properties[$hubspotProperty] = $convertedValue;
                        }
                    }
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
            $value = PropertyConverter::getNestedValue($data, $modelProperty);

            if ($value !== null) {
                $convertedValue = PropertyConverter::convertValueForHubspot($value, $hubspotProperty);
                if ($convertedValue !== null) {
                    $properties[$hubspotProperty] = $convertedValue;
                }
            }
        }

        // Process dynamic properties that are explicitly added by hubspotProperties method
        if (isset($data['dynamicProperties']) && is_array($data['dynamicProperties'])) {
            foreach ($data['dynamicProperties'] as $hubspotProperty => $value) {
                // If this property is in the map but wasn't found above, use the dynamic property
                // Otherwise, add it if it's not already in the map
                if (array_key_exists($hubspotProperty, $map)) {
                    // Only add if it wasn't already processed above
                    if (! array_key_exists($hubspotProperty, $properties)) {
                        $convertedValue = PropertyConverter::convertValueForHubspot($value, $hubspotProperty);
                        if ($convertedValue !== null) {
                            $properties[$hubspotProperty] = $convertedValue;
                        }
                    }
                } else {
                    // Convert and add dynamic property
                    if ($value !== null) {
                        $convertedValue = PropertyConverter::convertValueForHubspot($value, $hubspotProperty);
                        if ($convertedValue !== null) {
                            $properties[$hubspotProperty] = $convertedValue;
                        }
                    }
                }
            }
        }

        // Validate all properties are strings before creating the object
        PropertyConverter::validateHubspotProperties($properties);

        return new ContactObject(['properties' => $properties]);
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

        $companyService = app(HubspotCompanyService::class);

        // If company doesn't have hubspot_id, create it in HubSpot first
        if (empty($companyData['hubspot_id'])) {
            try {
                $companyId = $companyService->createOrFindCompany($companyData);
                if ($companyId) {
                    $companyService->associateCompanyWithContact($companyId, $contactId);

                    // Update the company model with the new hubspot_id
                    $this->updateCompanyHubspotId($companyData['id'] ?? null, $companyId, $data['modelClass'] ?? null);
                } else {
                    Log::warning('Could not create or find company for contact association', [
                        'company_name' => $companyData['name'] ?? 'unknown',
                        'contact_id' => $contactId,
                        'company_data' => $companyData,
                    ]);
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
            $companyService->associateCompanyWithContact($companyData['hubspot_id'], $contactId);
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
                $companyId = $companyService->createOrFindCompany($companyData);
                if ($companyId) {
                    $companyService->associateCompanyWithContact($companyId, $contactId);
                    $this->updateCompanyHubspotId($companyData['id'] ?? null, $companyId, $data['modelClass'] ?? null);
                }
            } else {
                // Log the unexpected error and re-throw
                Log::error('Unexpected error during company association', [
                    'company_id' => $companyData['id'] ?? null,
                    'hubspot_id' => $companyData['hubspot_id'],
                    'contact_id' => $contactId,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                ]);
                throw $e;
            }
        }
    }

    /**
     * Get the mapped email field from the data's hubspotMap
     */
    protected function getMappedEmailField(array $data): ?string
    {
        // Check if hubspotMap exists and has an email mapping
        if (isset($data['hubspotMap']['email'])) {
            $emailField = $data['hubspotMap']['email'];

            // Handle dot notation for nested properties
            if (strpos($emailField, '.')) {
                return data_get($data, $emailField);
            }

            return $data[$emailField] ?? null;
        }

        // Fallback to data['email'] if no mapping is defined
        return $data['email'] ?? null;
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
