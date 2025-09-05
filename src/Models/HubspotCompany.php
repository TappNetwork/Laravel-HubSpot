<?php

namespace Tapp\LaravelHubspot\Models;

use HubSpot\Client\Crm\Companies\Model\SimplePublicObjectInput as CompanyObject;
use Tapp\LaravelHubspot\Services\PropertyConverter;

trait HubspotCompany
{
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

            $properties[$key] = $propertyValue;
        }

        return $properties;
    }

    /**
     * Get properties to be synced with hubspot as CompanyObject
     */
    public function hubspotPropertiesObject(array $map): CompanyObject
    {
        $properties = $this->hubspotProperties($map);
        $convertedProperties = [];

        foreach ($properties as $key => $value) {
            $convertedValue = PropertyConverter::convertValueForHubspot($value, $key);
            if ($convertedValue !== null) {
                $convertedProperties[$key] = $convertedValue;
            }
        }

        // Validate all properties are strings
        PropertyConverter::validateHubspotProperties($convertedProperties);

        return new CompanyObject(['properties' => $convertedProperties]);
    }
}