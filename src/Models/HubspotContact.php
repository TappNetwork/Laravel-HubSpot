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
}
