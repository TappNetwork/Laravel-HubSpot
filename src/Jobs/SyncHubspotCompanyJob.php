<?php

namespace Tapp\LaravelHubspot\Jobs;

use HubSpot\Client\Crm\Companies\ApiException;
use HubSpot\Client\Crm\Companies\Model\SimplePublicObjectInput as CompanyObject;
use HubSpot\Client\Crm\Companies\Model\PublicObjectSearchRequest as CompanySearch;
use HubSpot\Client\Crm\Companies\Model\Filter;
use HubSpot\Client\Crm\Companies\Model\FilterGroup;
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
            $companyId = is_array($hubspotCompany) ? $hubspotCompany['id'] : $hubspotCompany->getId();
            $this->updateModelHubspotId($companyId);
        } catch (ApiException $e) {
            // Handle 409 conflict (duplicate company name) by finding existing company
            if ($e->getCode() === 409 && !empty($this->modelData['name'])) {
                Log::info('HubSpot company already exists, finding by name', [
                    'name' => $this->modelData['name'],
                    'error' => $e->getMessage(),
                ]);

                $company = $this->findCompanyByName($this->modelData['name']);
                if ($company) {
                    // Update the model with existing HubSpot ID
                    $companyId = is_array($company) ? $company['id'] : $company->getId();
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
                throw new \Exception('HubSpot API validation error: ' . $e->getMessage());
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
            throw new \Exception('HubSpot ID missing. Cannot update company: ' . ($this->modelData['name'] ?? 'unknown'));
        }

        $properties = $this->buildPropertiesObject($this->modelData['hubspotMap'] ?? []);

        $hubspotCompany = Hubspot::crm()->companies()->basicApi()->update(
            $this->modelData['hubspot_id'],
            $properties
        );
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
                $properties[$hubspotProperty] = $value;
            }
        }

        return new CompanyObject(['properties' => $properties]);
    }

    /**
     * Get nested value from array using dot notation.
     */
    protected function getNestedValue(array $array, string $key)
    {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return null;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Update the model with HubSpot ID.
     */
    protected function updateModelHubspotId(string $hubspotId): void
    {
        if (!$this->modelClass || !class_exists($this->modelClass)) {
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
                return $searchResults['results'][0];
            }
        } catch (ApiException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        return null;
    }
}


