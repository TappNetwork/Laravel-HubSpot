<?php

namespace Tapp\LaravelHubspot\Jobs;

use HubSpot\Client\Crm\Companies\ApiException;
use HubSpot\Client\Crm\Companies\Model\Filter;
use HubSpot\Client\Crm\Companies\Model\FilterGroup;
use HubSpot\Client\Crm\Companies\Model\PublicObjectSearchRequest as CompanySearch;
use HubSpot\Client\Crm\Companies\Model\SimplePublicObjectInput as CompanyObject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Facades\Hubspot;

class SyncHubspotCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries;

    public $backoff;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $modelData,
        public string $operation = 'update',
        public ?string $modelClass = null
    ) {
        $this->tries = config('hubspot.queue.retry_attempts', 3);
        $this->backoff = config('hubspot.queue.retry_delay', 60);
        $this->onQueue(config('hubspot.queue.queue', 'hubspot'));
        $this->onConnection(config('hubspot.queue.connection', 'default'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (config('hubspot.disabled')) {
            return;
        }

        try {
            if ($this->operation === 'create') {
                $this->createCompany();
            } else {
                $this->updateCompany();
            }
        } catch (\Exception $e) {
            Log::error('HubSpot company sync job failed', [
                'operation' => $this->operation,
                'model_data' => $this->modelData,
                'error' => $e->getMessage(),
            ]);

            // If it's a rate limit error, retry with delay
            if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate limit')) {
                Log::info('Rate limit detected, releasing job for retry', [
                    'operation' => $this->operation,
                    'model_id' => $this->modelData['id'] ?? null,
                ]);
                $this->release(30); // Retry in 30 seconds

                return;
            }

            // If it's a 409 conflict (duplicate), retry with shorter delay
            if (str_contains($e->getMessage(), '409') || str_contains($e->getMessage(), 'conflict')) {
                Log::info('Conflict detected (likely duplicate company), releasing job for retry', [
                    'operation' => $this->operation,
                    'model_id' => $this->modelData['id'] ?? null,
                ]);
                $this->release(5); // Retry in 5 seconds

                return;
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('HubSpot company sync job failed permanently', [
            'operation' => $this->operation,
            'model_data' => $this->modelData,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Create a new HubSpot company.
     */
    protected function createCompany(): void
    {
        $properties = $this->buildPropertiesObject($this->modelData['hubspotMap'] ?? []);

        try {
            $hubspotCompany = Hubspot::crm()->companies()->basicApi()->create($properties);

            // Update the model with HubSpot ID
            $companyId = $this->extractCompanyId($hubspotCompany);
            $this->updateModelHubspotId($companyId);
        } catch (ApiException $e) {
            // Handle 409 conflict (duplicate company name) by finding existing company
            if ($e->getCode() === 409 && ! empty($this->modelData['name'])) {
                Log::info('HubSpot company already exists, finding by name', [
                    'name' => $this->modelData['name'],
                    'error' => $e->getMessage(),
                ]);

                $company = $this->findCompanyByName($this->modelData['name']);
                if ($company) {
                    // Update the model with existing HubSpot ID
                    $companyId = $this->extractCompanyId($company);
                    $this->updateModelHubspotId($companyId);

                    return;
                }
            }

            // Handle 400 bad request (validation errors)
            if ($e->getCode() === 400) {
                Log::error('HubSpot API 400 error - company data validation failed', [
                    'name' => $this->modelData['name'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'properties_sent' => $this->modelData['hubspotMap'] ?? [],
                ]);
                throw new \Exception('HubSpot API validation error: '.$e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Update an existing HubSpot company.
     */
    protected function updateCompany(): void
    {
        if (empty($this->modelData['hubspot_id'])) {
            throw new \Exception('HubSpot ID missing. Cannot update company: '.($this->modelData['name'] ?? 'unknown'));
        }

        $properties = $this->buildPropertiesObject($this->modelData['hubspotMap'] ?? []);

        $hubspotCompany = Hubspot::crm()->companies()->basicApi()->update(
            $this->modelData['hubspot_id'],
            $properties
        );
    }

    /**
     * Extract company ID safely from various response types.
     */
    protected function extractCompanyId($company): string
    {
        // If it's already an array, return the id
        if (is_array($company)) {
            return $company['id'];
        }

        // If it's an object with getId method, use it
        if (is_object($company) && method_exists($company, 'getId')) {
            return $company->getId();
        }

        // If it's an Error object, throw an exception
        if (is_object($company) && get_class($company) === 'HubSpot\Client\Crm\Companies\Model\Error') {
            throw new \Exception('HubSpot API returned an error: '.(method_exists($company, 'getMessage') ? $company->getMessage() : 'Unknown error'));
        }

        // Fallback: try to convert to array
        $companyArray = (array) $company;
        if (isset($companyArray['id'])) {
            return $companyArray['id'];
        }

        throw new \Exception('Unable to extract company ID from response');
    }

    /**
     * Build HubSpot properties object from model data.
     */
    protected function buildPropertiesObject(array $map): CompanyObject
    {
        $properties = [];

        foreach ($map as $hubspotProperty => $modelProperty) {
            $value = $this->getNestedValue($this->modelData, $modelProperty);

            if ($value !== null) {
                $convertedValue = $this->convertValueForHubspot($value, $hubspotProperty);
                if ($convertedValue !== null) {
                    $properties[$hubspotProperty] = $convertedValue;
                }
            }
        }

        // Validate all properties are strings before creating the object
        $this->validateHubspotProperties($properties);

        return new CompanyObject(['properties' => $properties]);
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
     * Get nested value from array using dot notation.
     */
    protected function getNestedValue(array $array, string $key)
    {
        return data_get($array, $key);
    }

    /**
     * Update the model with HubSpot ID.
     */
    protected function updateModelHubspotId(string $hubspotId): void
    {
        if (! $this->modelClass || ! class_exists($this->modelClass)) {
            return;
        }

        $model = $this->modelClass::find($this->modelData['id'] ?? null);
        if ($model) {
            $model->update(['hubspot_id' => $hubspotId]);
        }
    }

    /**
     * Find company by name.
     */
    protected function findCompanyByName(string $name): ?array
    {
        try {
            $filter = new Filter([
                'value' => $name,
                'property_name' => 'name',
                'operator' => 'EQ',
            ]);

            $filterGroup = new FilterGroup([
                'filters' => [$filter],
            ]);

            $companySearch = new CompanySearch([
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

                return $result;
            }
        } catch (ApiException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        return null;
    }
}
