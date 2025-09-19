<?php

namespace Tapp\LaravelHubspot\Services;

class PropertyConverter
{
    /**
     * Convert a value to a string suitable for HubSpot API
     */
    public static function convertValueForHubspot($value, string $propertyName)
    {
        if (is_null($value)) {
            return null;
        } elseif ($value instanceof \Carbon\Carbon) {
            return $value->toISOString();
        } elseif (is_array($value)) {
            if (empty($value)) {
                return null;
            }

            // Handle translatable fields (associative arrays with language keys)
            if (array_keys($value) !== range(0, count($value) - 1)) {
                // This is an associative array, likely a translatable field
                if (isset($value['en'])) {
                    return $value['en'];
                }

                // If no 'en' key, get the first value
                return (string) reset($value);
            }

            // Handle regular indexed arrays
            return implode(', ', array_filter($value, 'is_scalar'));
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
     * Validate that all properties are properly converted to strings for HubSpot API
     */
    public static function validateHubspotProperties(array $properties): void
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
     * Get nested value from array using dot notation
     */
    public static function getNestedValue(array $array, string $key)
    {
        return data_get($array, $key);
    }
}
