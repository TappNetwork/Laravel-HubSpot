<?php

namespace Tapp\LaravelHubspot\Models;

use HubSpot\Client\Crm\Companies\ApiException;
use HubSpot\Client\Crm\Companies\Model\Filter;
use HubSpot\Client\Crm\Companies\Model\FilterGroup;
use HubSpot\Client\Crm\Companies\Model\PublicObjectSearchRequest as CompanySearch;
use HubSpot\Client\Crm\Companies\Model\SimplePublicObjectInput as CompanyObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Facades\Hubspot;
use Tapp\LaravelHubspot\Jobs\SyncHubspotCompanyJob;

trait HubspotCompany
{
    // public array $hubspotMap = [];

    public static function bootHubspotCompany(): void
    {
        static::creating(fn (Model $model) => static::updateOrCreateHubspotCompany($model));

        static::updating(fn (Model $model) => static::updateOrCreateHubspotCompany($model));
    }

    public static function createHubspotCompany($model)
    {
        try {
            $hubspotCompany = Hubspot::crm()->companies()->basicApi()->create($model->hubspotPropertiesObject($model->hubspotMap));

            $model->hubspot_id = $hubspotCompany['id'];
            static::saveHubspotId($model, $hubspotCompany['id']);
        } catch (ApiException $e) {
            throw $e;
        }

        return $hubspotCompany;
    }

    public static function updateHubspotCompany($model)
    {
        if (! $model->hubspot_id) {
            throw new \Exception('Hubspot ID missing. Cannot update company: '.$model->name);
        }

        // Validate that the company exists in HubSpot before attempting update
        if (! static::validateHubspotCompanyExists($model->hubspot_id)) {
            // Try to find by name without clearing the invalid ID
            if ($model->name) {
                $company = static::findCompanyByName($model->name);
                if ($company) {
                    // Update with correct hubspot_id and retry
                    static::saveHubspotId($model, $company['id']);
                    $model->hubspot_id = $company['id'];
                } else {
                    // Company doesn't exist, create it instead
                    return static::createHubspotCompany($model);
                }
            } else {
                throw new \Exception('Invalid HubSpot ID and no name provided for company: '.$model->name);
            }
        }

        try {
            $hubspotCompany = Hubspot::crm()->companies()->basicApi()->update($model->hubspot_id, $model->hubspotPropertiesObject($model->hubspotMap));
        } catch (ApiException $e) {
            // Handle specific API errors
            if ($e->getCode() === 400) {
                $properties = $model->hubspotProperties($model->hubspotMap);
                Log::error('HubSpot API 400 error - data validation failed', [
                    'name' => $model->name,
                    'hubspot_id' => $model->hubspot_id,
                    'error' => $e->getMessage(),
                    'properties_sent' => $properties,
                    'property_map' => $model->hubspotMap,
                ]);
                throw new \Exception('HubSpot API validation error: '.$e->getMessage());
            }
            throw $e;
        }

        return $hubspotCompany;
    }

    /*
     * Main entry point: update existing company or create new one
     */
    public static function updateOrCreateHubspotCompany($model)
    {
        if (config('hubspot.disabled')) {
            return;
        }

        // Check if queuing is enabled
        if (config('hubspot.queue.enabled', true)) {
            return static::dispatchHubspotCompanyJob($model);
        }

        // Fallback to synchronous operation
        $hubspotCompany = static::getCompanyByIdOrName($model);

        if ($hubspotCompany) {
            return static::updateHubspotCompany($model);
        }

        // Company doesn't exist, create it
        return static::createHubspotCompany($model);
    }

    /**
     * Dispatch a job to handle HubSpot company synchronization
     */
    protected static function dispatchHubspotCompanyJob($model)
    {
        $modelData = static::prepareModelDataForJob($model);

        // Determine operation type
        $operation = $model->hubspot_id ? 'update' : 'create';

        SyncHubspotCompanyJob::dispatch($modelData, $operation, get_class($model));

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

        return $data;
    }

    public static function getCompanyByIdOrName($model)
    {
        $hubspotCompany = null;

        if ($model->hubspot_id) {
            try {
                return Hubspot::crm()->companies()->basicApi()->getById($model->hubspot_id);
            } catch (ApiException $e) {
                // Company not found by ID, continue to try by name
                if ($e->getCode() !== 404) {
                    throw $e; // Re-throw non-404 errors
                }
            }
        }

        // if no hubspot id or if id fetch failed, try searching by name
        try {
            $filter = new Filter([
                'value' => $model->name,
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
                $hubspotCompany = $searchResults['results'][0];

                // Update the hubspot_id and save it to prevent future 404s
                $model->hubspot_id = $hubspotCompany['id'];
                static::saveHubspotId($model, $hubspotCompany['id']);
            }
        } catch (ApiException $e) {
            // Company not found by name either, return null
            if ($e->getCode() !== 404) {
                throw $e; // Re-throw non-404 errors
            }
        }

        return $hubspotCompany;
    }

    /**
     * Validate that a HubSpot company exists by ID.
     */
    public static function validateHubspotCompanyExists(string $hubspotId): bool
    {
        try {
            Hubspot::crm()->companies()->basicApi()->getById($hubspotId);

            return true;
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                Log::warning('HubSpot company not found by ID', [
                    'hubspot_id' => $hubspotId,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
            throw $e;
        }
    }

    /**
     * Find company by name.
     */
    protected static function findCompanyByName(string $name): ?array
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
                return $searchResults['results'][0];
            }
        } catch (ApiException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        return null;
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

    /**
     * get properties to be synced with hubspot
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

            // Convert Carbon objects to ISO 8601 format for HubSpot
            if ($propertyValue instanceof \Carbon\Carbon) {
                $properties[$key] = $propertyValue->toISOString();
            }
            // Convert other objects to string if they have __toString method
            elseif (is_object($propertyValue) && method_exists($propertyValue, '__toString')) {
                $properties[$key] = (string) $propertyValue;
            }
            // Skip null values to avoid sending them to HubSpot
            elseif (is_null($propertyValue)) {
                continue;
            } else {
                $properties[$key] = $propertyValue;
            }
        }

        return $properties;
    }

    /**
     * get properties to be synced with hubspot
     */
    public function hubspotPropertiesObject(array $map): CompanyObject
    {
        return new CompanyObject(['properties' => $this->hubspotProperties($map)]);
    }

    public static function findOrCreateCompany($properties)
    {
        $filter = new Filter([
            'value' => $properties['name'],
            'property_name' => 'name',
            'operator' => 'EQ',
        ]);

        $filterGroup = new FilterGroup([
            'filters' => [$filter],
        ]);

        $companySearch = new CompanySearch([
            'filter_groups' => [$filterGroup],
        ]);

        try {
            $searchResults = Hubspot::crm()->companies()->searchApi()->doSearch($companySearch);
        } catch (\Exception $e) {
            throw $e;
        }

        $companyExists = $searchResults['total'];

        if ($companyExists) {
            return $searchResults['results'][0];
        } else {
            $companyObject = new CompanyObject([
                'properties' => $properties,
            ]);

            return Hubspot::crm()->companies()->basicApi()->create($companyObject);
        }
    }
}
