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
            if ($e->getCode() === 409 && ! empty($data['email'])) {
                Log::info('HubSpot contact already exists, finding by email', [
                    'email' => $data['email'],
                    'error' => $e->getMessage(),
                ]);

                $contact = $this->findContact(['email' => $data['email']]);
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
            if (str_contains($e->getMessage(), 'Contact already exists') && ! empty($data['email'])) {
                Log::info('HubSpot contact already exists, finding by email', [
                    'email' => $data['email'],
                    'error' => $e->getMessage(),
                ]);

                $contact = $this->findContact(['email' => $data['email']]);
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
            if (! empty($data['email'])) {
                $contact = $this->findContact(['email' => $data['email']]);
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

        if (! empty($data['email'])) {
            try {
                $contact = Hubspot::crm()->contacts()->basicApi()->getById($data['email'], null, null, null, false, 'email');

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
                // If this property is in the map but wasn't found above, use the dynamic property
                // Otherwise, add it if it's not already in the map
                if (array_key_exists($hubspotProperty, $map)) {
                    // Only add if it wasn't already processed above
                    if (! array_key_exists($hubspotProperty, $properties)) {
                        $convertedValue = $this->convertValueForHubspot($value, $hubspotProperty);
                        if ($convertedValue !== null) {
                            $properties[$hubspotProperty] = $convertedValue;
                        }
                    }
                } else {
                    // Convert and add dynamic property
                    if ($value !== null) {
                        $convertedValue = $this->convertValueForHubspot($value, $hubspotProperty);
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
                // If this property is in the map but wasn't found above, use the dynamic property
                // Otherwise, add it if it's not already in the map
                if (array_key_exists($hubspotProperty, $map)) {
                    // Only add if it wasn't already processed above
                    if (! array_key_exists($hubspotProperty, $properties)) {
                        $convertedValue = $this->convertValueForHubspot($value, $hubspotProperty);
                        if ($convertedValue !== null) {
                            $properties[$hubspotProperty] = $convertedValue;
                        }
                    }
                } else {
                    // Convert and add dynamic property
                    if ($value !== null) {
                        $convertedValue = $this->convertValueForHubspot($value, $hubspotProperty);
                        if ($convertedValue !== null) {
                            $properties[$hubspotProperty] = $convertedValue;
                        }
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

        $companyService = app(HubspotCompanyService::class);

        // If company doesn't have hubspot_id, create it in HubSpot first
        if (empty($companyData['hubspot_id'])) {
            try {
                $companyId = $companyService->createOrFindCompany($companyData);
                if ($companyId) {
                    $companyService->associateCompanyWithContact($companyId, $contactId);

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
                    'error_code' => method_exists($e, 'getCode') ? $e->getCode() : 'unknown',
                ]);
                throw $e;
            }
        }
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
