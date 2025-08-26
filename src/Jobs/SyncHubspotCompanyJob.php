<?php

namespace Tapp\LaravelHubspot\Jobs;

use HubSpot\Client\Crm\Companies\ApiException;
use HubSpot\Client\Crm\Companies\Model\Filter;
use HubSpot\Client\Crm\Companies\Model\FilterGroup;
use HubSpot\Client\Crm\Companies\Model\PublicObjectSearchRequest as CompanySearch;
use HubSpot\Client\Crm\Companies\Model\SimplePublicObjectInput as CompanyObject;
use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Facades\Hubspot;

class SyncHubspotCompanyJob extends BaseHubspotJob
{
    /**
     * Execute the specific operation (create or update).
     */
    protected function executeOperation(): void
    {
        if ($this->operation === 'create') {
            $this->createCompany();
        } else {
            $this->updateCompany();
        }
    }

    /**
     * Get the job type for logging.
     */
    protected function getJobType(): string
    {
        return 'HubSpot company';
    }

    /**
     * Create a new HubSpot company.
     */
    protected function createCompany(): void
    {
        $properties = $this->buildPropertiesObject($this->modelData['hubspotMap'] ?? []);

        try {
            $hubspotCompany = Hubspot::crm()->companies()->basicApi()->create($properties);

            // Check if response is an Error object
            if ($hubspotCompany instanceof \HubSpot\Client\Crm\Companies\Model\Error) {
                throw new \Exception('HubSpot API returned an error: '.$hubspotCompany->getMessage());
            }

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

        // Check if response is an Error object
        if ($hubspotCompany instanceof \HubSpot\Client\Crm\Companies\Model\Error) {
            throw new \Exception('HubSpot API returned an error: '.$hubspotCompany->getMessage());
        }
    }

    /**
     * Extract company ID safely from various response types.
     */
    protected function extractCompanyId($company): string
    {
        // If it's an Error object, throw an exception
        if (is_object($company) && get_class($company) === 'HubSpot\Client\Crm\Companies\Model\Error') {
            throw new \Exception('HubSpot API returned an error: '.(method_exists($company, 'getMessage') ? $company->getMessage() : 'Unknown error'));
        }

        return $this->extractId($company);
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
