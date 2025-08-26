<?php

namespace Tapp\LaravelHubspot\Services;

use HubSpot\Client\Crm\Associations\V4\ApiException as AssociationsApiException;
use HubSpot\Client\Crm\Associations\V4\Model\AssociationSpec;
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

        foreach ($map as $hubspotProperty => $modelProperty) {
            $value = $this->getNestedValue($data, $modelProperty);

            if ($value !== null) {
                $convertedValue = $this->convertValueForHubspot($value, $hubspotProperty);
                if ($convertedValue !== null) {
                    $properties[$hubspotProperty] = $convertedValue;
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
        if (! $companyData || empty($companyData['hubspot_id'])) {
            return;
        }

        $this->associateCompanyWithContact($companyData['hubspot_id'], $contactId);
    }

    /**
     * Associate a company with a contact in HubSpot.
     */
    protected function associateCompanyWithContact(string $companyId, string $contactId): void
    {
        try {
            $associationSpec = new AssociationSpec([
                'association_category' => 'HUBSPOT_DEFINED',
                'association_type_id' => 1, // Company to Contact association
            ]);

            Hubspot::crm()->associations()->v4()->basicApi()->create(
                'companies',
                $companyId,
                'contacts',
                $contactId,
                [$associationSpec]
            );
        } catch (AssociationsApiException $e) {
            Log::warning('Failed to associate company with contact', [
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
