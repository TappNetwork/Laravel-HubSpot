<?php

namespace Tapp\LaravelHubspot\Models;

use HubSpot\Client\Crm\Associations\V4\Model\AssociationSpec;
use HubSpot\Client\Crm\Contacts\ApiException;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput as ContactObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Facades\Hubspot;
use Tapp\LaravelHubspot\Jobs\SyncHubspotContactJob;

trait HubspotContact
{
    public static function bootHubspotContact(): void
    {
        static::creating(fn (Model $model) => static::updateOrCreateHubspotContact($model));

        static::updating(fn (Model $model) => static::updateOrCreateHubspotContact($model));
    }

    // TODO put these in an interface
    // public array $hubspotMap = [];
    // public string $hubspotCompanyRelation = '';
    // public array $hubspotUpdateMap = [];

    /*
     * Main entry point: update existing contact or create new one
     */
    public static function updateOrCreateHubspotContact($model)
    {
        if (config('hubspot.disabled')) {
            return;
        }

        // Check if queuing is enabled
        if (config('hubspot.queue.enabled', true)) {
            return static::dispatchHubspotContactJob($model);
        }

        // Fallback to synchronous operation
        $hubspotContact = static::getContactByEmailOrId($model);

        if ($hubspotContact) {
            return static::updateHubspotContact($model);
        }

        // Contact doesn't exist, create it
        return static::createHubspotContact($model);
    }

    /**
     * Dispatch a job to handle HubSpot contact synchronization
     */
    protected static function dispatchHubspotContactJob($model)
    {
        $modelData = static::prepareModelDataForJob($model);

        // Determine operation type
        $operation = $model->hubspot_id ? 'update' : 'create';

        SyncHubspotContactJob::dispatch($modelData, $operation, get_class($model));

        return null; // Job is queued, no immediate result
    }

    /**
     * Prepare model data for job serialization
     */
    protected static function prepareModelDataForJob($model): array
    {
        $data = $model->toArray();

        // Add HubSpot-specific properties
        $data['hubspotMap'] = $model->hubspotMap ?? [];
        $data['hubspotUpdateMap'] = $model->hubspotUpdateMap ?? [];
        $data['hubspotCompanyRelation'] = $model->hubspotCompanyRelation ?? '';

        // Add company relation data if it exists
        if (! empty($model->hubspotCompanyRelation)) {
            $company = $model->getRelationValue($model->hubspotCompanyRelation);
            if ($company) {
                $data['hubspotCompanyRelation'] = $company->toArray();
            }
        }

        return $data;
    }

    public static function createHubspotContact($model)
    {
        try {
            $hubspotContact = Hubspot::crm()->contacts()->basicApi()->create($model->hubspotPropertiesObject($model->hubspotMap));

            $model->hubspot_id = $hubspotContact['id'];
        } catch (ApiException $e) {
            throw $e;
        }

        $hubspotCompany = $model->getRelationValue($model->hubspotCompanyRelation);

        if ($hubspotCompany && ! $hubspotCompany->hubspot_id) {
            $hubspotCompany->touch();
            $hubspotCompany = $hubspotCompany->fresh();
        }

        if ($hubspotCompany && $hubspotCompany->hubspot_id) {
            if (! isset($hubspotContact['id'])) {
                Log::warning('HubSpot contact is missing id. Cannot assign company.', [
                    'email' => $model->email,
                    'hubspot_contact' => $hubspotContact,
                ]);
            } else {
                static::associateCompanyWithContact($hubspotCompany->hubspot_id, $hubspotContact['id']);
            }
        }

        return $hubspotContact;
    }

    public static function updateHubspotContact($model)
    {
        if (! $model->hubspot_id) {
            throw new \Exception('Hubspot ID missing. Cannot update contact: '.$model->email);
        }

        // Validate that the contact exists in HubSpot before attempting update
        if (! static::validateHubspotContactExists($model->hubspot_id)) {
            // Try to find by email without clearing the invalid ID
            if ($model->email) {
                $contact = static::findContactByEmail($model->email);
                if ($contact) {
                    // Update with correct hubspot_id and retry
                    static::saveHubspotId($model, $contact['id']);
                    $model->hubspot_id = $contact['id'];
                } else {
                    // Contact doesn't exist, create it instead
                    return static::createHubspotContact($model);
                }
            } else {
                throw new \Exception('Invalid HubSpot ID and no email provided for contact: '.$model->email);
            }
        }

        $map = static::getPropertyMap($model);

        try {
            $hubspotContact = Hubspot::crm()->contacts()->basicApi()->update($model->hubspot_id, $model->hubspotPropertiesObject($map));
        } catch (ApiException $e) {
            // Handle specific API errors
            if ($e->getCode() === 400) {
                $properties = $model->hubspotProperties($map);
                Log::error('HubSpot API 400 error - data validation failed', [
                    'email' => $model->email,
                    'hubspot_id' => $model->hubspot_id,
                    'error' => $e->getMessage(),
                    'properties_sent' => $properties,
                    'property_map' => $map,
                ]);
                throw new \Exception('HubSpot API validation error: '.$e->getMessage());
            }
            throw $e;
        }

        // Handle company association
        static::associateCompanyIfNeeded($model, $hubspotContact);

        return $hubspotContact;
    }

    public static function getContactByEmailOrId($model)
    {
        $hubspotContact = null;

        if ($model->hubspot_id) {
            try {
                return Hubspot::crm()->contacts()->basicApi()->getById($model->hubspot_id);
            } catch (ApiException $e) {
                // Contact not found by ID, continue to try by email without clearing
                if ($e->getCode() === 404) {
                    // Don't clear the ID, just continue to email lookup
                } else {
                    throw $e; // Re-throw non-404 errors
                }
            }
        }

        // if no hubspot id or if id fetch failed, try fetching by email
        if ($model->email) {
            try {
                $hubspotContact = Hubspot::crm()->contacts()->basicApi()->getById($model->email, null, null, null, false, 'email');

                // Update the hubspot_id and save it to prevent future 404s
                $model->hubspot_id = $hubspotContact['id'];
                static::saveHubspotId($model, $hubspotContact['id']);
            } catch (ApiException $e) {
                // Contact not found by email either, return null
                if ($e->getCode() !== 404) {
                    throw $e; // Re-throw non-404 errors
                }
            }
        }

        return $hubspotContact;
    }

    /**
     * Validate that a HubSpot contact exists by ID.
     */
    public static function validateHubspotContactExists(string $hubspotId): bool
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
    protected static function findContactByEmail(string $email): ?array
    {
        try {
            $contact = Hubspot::crm()->contacts()->basicApi()->getById($email, null, null, null, false, 'email');

            // Convert object to array if needed
            if (is_object($contact)) {
                $contact = [
                    'id' => $contact->getId(),
                    'properties' => $contact->getProperties() ?? [],
                ];
            }

            return $contact;
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    private static function getPropertyMap($model): array
    {
        // If hubspotUpdateMap is defined and not empty, use it
        if (property_exists($model, 'hubspotUpdateMap') && ! empty($model->hubspotUpdateMap)) {
            return $model->hubspotUpdateMap;
        }

        // Otherwise, default to hubspotMap
        return $model->hubspotMap ?? [];
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
     * Get properties to be synced with hubspot
     */
    public function hubspotProperties(array $map): array
    {
        $properties = [];

        foreach ($map as $key => $value) {
            if (strpos($value, '.')) {
                $propertyValue = data_get($this, $value);
            } else {
                $propertyValue = $this->$value;
            }

            // Skip null values to avoid sending them to HubSpot
            if (is_null($propertyValue)) {
                continue;
            }

            // Store the raw value - conversion will be handled by convertAndValidateProperties
            $properties[$key] = $propertyValue;
        }

        return $properties;
    }

    /**
     * Convert and validate properties after the overridden hubspotProperties method
     */
    protected function convertAndValidateProperties(array $properties): array
    {
        $convertedProperties = [];

        foreach ($properties as $key => $value) {
            // Skip null values
            if (is_null($value)) {
                continue;
            }

            // Convert data types to strings for HubSpot API
            if ($value instanceof \Carbon\Carbon) {
                $convertedProperties[$key] = $value->toISOString();
            } elseif (is_array($value)) {
                if (empty($value)) {
                    continue;
                }
                $convertedProperties[$key] = (array_keys($value) === range(0, count($value) - 1))
                    ? implode(', ', array_filter($value, 'is_scalar'))
                    : json_encode($value);
            } elseif (is_object($value)) {
                if (method_exists($value, '__toString')) {
                    $convertedProperties[$key] = (string) $value;
                } elseif (method_exists($value, 'toArray')) {
                    $arrayValue = $value->toArray();
                    $convertedProperties[$key] = is_array($arrayValue) ? json_encode($arrayValue) : (string) $arrayValue;
                } else {
                    throw new \InvalidArgumentException(
                        'Cannot convert object of type '.get_class($value)." to string for property: {$key}. ".
                        'Objects must implement __toString() or toArray() methods to be automatically converted.'
                    );
                }
            } elseif (is_bool($value)) {
                $convertedProperties[$key] = $value ? 'true' : 'false';
            } elseif (is_numeric($value)) {
                $convertedProperties[$key] = (string) $value;
            } else {
                $convertedProperties[$key] = (string) $value;
            }
        }

        // Validate all properties are strings
        $this->validateHubspotProperties($convertedProperties);

        return $convertedProperties;
    }

    /**
     * Get properties to be synced with hubspot as ContactObject
     */
    public function hubspotPropertiesObject(array $map): ContactObject
    {
        // Call the overridden hubspotProperties method first
        $properties = $this->hubspotProperties($map);

        // Then convert and validate the properties
        $convertedProperties = $this->convertAndValidateProperties($properties);

        return new ContactObject(['properties' => $convertedProperties]);
    }

    public static function associateCompanyWithContact(string $companyId, string $contactId)
    {
        $associationSpec = new AssociationSpec([
            'association_category' => 'HUBSPOT_DEFINED',
            'association_type_id' => 1,
        ]);

        try {
            return Hubspot::crm()->associations()->v4()->basicApi()->create('contact', $contactId, 'company', $companyId, [$associationSpec]);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    private static function associateCompanyIfNeeded($model, $hubspotContact): void
    {
        $hubspotCompany = $model->getRelationValue($model->hubspotCompanyRelation);

        if ($hubspotCompany && ! $hubspotCompany->hubspot_id) {
            // trigger the model event to update the sync company to hubspot
            $hubspotCompany->touch();
            $hubspotCompany = $hubspotCompany->fresh();
        }

        if ($hubspotCompany && $hubspotCompany->hubspot_id) {
            if (! isset($hubspotContact['id'])) {
                Log::warning('HubSpot contact is missing id. Cannot assign company.', [
                    'email' => $model->email,
                    'hubspot_contact' => $hubspotContact,
                ]);
            } else {
                static::associateCompanyWithContact($hubspotCompany->hubspot_id, $hubspotContact['id']);
            }
        }
    }

    /**
     * Save hubspot_id to database using direct update to avoid triggering model events
     */
    private static function saveHubspotId($model, $hubspotId): void
    {
        $model->getConnection()->table($model->getTable())
            ->where('id', $model->id)
            ->update(['hubspot_id' => $hubspotId]);
    }
}
