<?php

namespace Tapp\LaravelHubspot\Models;

use HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInputForCreate as ContactObject;
use Tapp\LaravelHubspot\Services\PropertyConverter;
use Tapp\LaravelHubspot\Traits\HubspotModelTrait;

trait HubspotContact
{
    use HubspotModelTrait;

    /**
     * Get properties to be synced with hubspot
     */
    public function hubspotProperties(array $map): array
    {
        $properties = [];

        foreach ($map as $key => $value) {
            if (strpos($value, '.')) {
                $propertyValue = PropertyConverter::getNestedValue($this->toArray(), $value);
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

            $convertedValue = PropertyConverter::convertValueForHubspot($value, $key);
            if ($convertedValue !== null) {
                $convertedProperties[$key] = $convertedValue;
            }
        }

        // Validate all properties are strings
        PropertyConverter::validateHubspotProperties($convertedProperties);

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

    /**
     * Dispatch a job to sync this model to HubSpot.
     * This bypasses all change detection checks and forces a sync.
     * This is useful when you need to manually trigger a sync (e.g., when accessors change).
     *
     * @return void
     */
    public function syncToHubSpot(): void
    {
        if (config('hubspot.disabled', false)) {
            return;
        }

        $observer = new \Tapp\LaravelHubspot\Observers\HubspotContactObserver;
        $operation = ! empty($this->getHubspotId()) ? 'update' : 'create';
        $observer->dispatchSyncJob($this, $operation);
    }

    /**
     * Update or create a HubSpot contact for this model.
     *
     * This method provides backward compatibility for the removed updateOrCreateHubspotContact method.
     * It will attempt to update the contact if a hubspot_id exists, otherwise it will create a new one.
     *
     * @return array The HubSpot contact data
     *
     * @throws \Exception If the operation fails
     */
    public function updateOrCreateHubspotContact(): array
    {
        $service = app(\Tapp\LaravelHubspot\Services\HubspotContactService::class);

        $data = [
            'id' => $this->getKey(),
            'hubspot_id' => $this->getHubspotId(),
            'hubspotMap' => $this->getHubspotMap(),
            'hubspotUpdateMap' => $this->getHubspotUpdateMap(),
            'hubspotCompanyRelation' => $this->getHubspotCompanyRelation(),
        ];

        // Include mapped fields
        foreach ($this->getHubspotMap() as $hubspotField => $modelField) {
            $data[$modelField] = data_get($this, $modelField);
        }

        // Include dynamic properties
        $dynamicProperties = $this->getHubspotProperties($this->getHubspotMap());
        if (! empty($dynamicProperties)) {
            $data['dynamicProperties'] = $dynamicProperties;
        }

        // Include company relation data if it exists
        $companyRelation = $this->getHubspotCompanyRelation();
        if (! empty($companyRelation)) {
            $company = $this->getRelationValue($companyRelation);
            if ($company) {
                $data['hubspotCompanyRelation'] = [
                    'id' => $company->getKey(),
                    'hubspot_id' => $company instanceof \Tapp\LaravelHubspot\Contracts\HubspotModelInterface ? $company->getHubspotId() : ($company->hubspot_id ?? null),
                    'name' => $company->name ?? $company->getAttribute('name'),
                ];
            }
        }

        // Try to update first if hubspot_id exists, otherwise create
        if (! empty($this->getHubspotId())) {
            try {
                return $service->updateContact($data);
            } catch (\Exception $e) {
                // If update fails (e.g., invalid hubspot_id), try to create instead
                \Illuminate\Support\Facades\Log::info('Update failed, attempting to create contact', [
                    'model_id' => $this->getKey(),
                    'hubspot_id' => $this->getHubspotId(),
                    'error' => $e->getMessage(),
                ]);

                return $service->createContact($data, get_class($this));
            }
        } else {
            return $service->createContact($data, get_class($this));
        }
    }
}
