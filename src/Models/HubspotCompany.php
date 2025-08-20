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
            Log::error('Error creating hubspot company', [
                'name' => $model->name,
                'message' => $e->getMessage(),
                'response' => $e->getResponseBody(),
            ]);

            throw $e;
        }

        return $hubspotCompany;
    }

    public static function updateHubspotCompany($model)
    {
        if (! $model->hubspot_id) {
            throw new \Exception('Hubspot ID missing. Cannot update company: '.$model->name);
        }

        try {
            $hubspotCompany = Hubspot::crm()->companies()->basicApi()->update($model->hubspot_id, $model->hubspotPropertiesObject($model->hubspotMap));
        } catch (ApiException $e) {
            Log::error('Hubspot company update failed', [
                'name' => $model->name,
                'hubspot_id' => $model->hubspot_id,
                'message' => $e->getMessage(),
                'response' => $e->getResponseBody(),
            ]);

            throw $e;
        }

        return $hubspotCompany;
    }

    /*
     * if the model has a hubspot_id, find the company by id and update
     * if the model has an email, find the company by email and update
     * if the fetch requests fail, create a new company
     */
    public static function updateOrCreateHubspotCompany($model)
    {
        if (config('hubspot.disabled')) {
            return;
        }

        // TODO this does not support using dot notation in map
        // if ($model->isClean($model->hubspotMap)) {
        //     return;
        // }

        $hubspotCompany = static::getCompanyByIdOrName($model);

        if (! $hubspotCompany) {
            return static::createHubspotCompany($model);
        }

        return static::updateHubspotCompany($model);
    }

    public static function getCompanyByIdOrName($model)
    {
        $hubspotCompany = null;

        if ($model->hubspot_id) {
            try {
                return Hubspot::crm()->companies()->basicApi()->getById($model->hubspot_id);
            } catch (ApiException $e) {
                Log::debug('Hubspot company not found with id', [
                    'id' => $model->id,
                    'hubspot_id' => $model->hubspot_id,
                    'message' => $e->getMessage(),
                    'response' => $e->getResponseBody(),
                ]);
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
            Log::debug('Hubspot company not found with name', [
                'name' => $model->name,
                'message' => $e->getMessage(),
                'response' => $e->getResponseBody(),
            ]);
        }

        return $hubspotCompany;
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
            // dump($filter, $properties);
            // dd($e);
            throw ($e);
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
