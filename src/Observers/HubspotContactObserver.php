<?php

namespace Tapp\LaravelHubspot\Observers;

use Illuminate\Database\Eloquent\Model;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;
use Tapp\LaravelHubspot\Jobs\SyncHubspotContactJob;

class HubspotContactObserver
{
    /**
     * Handle the Model "created" event.
     */
    public function created(Model $model): void
    {
        if (! $this->shouldSync($model)) {
            return;
        }

        $this->dispatchSyncJob($model, 'create');
    }

    /**
     * Handle the Model "updated" event.
     */
    public function updated(Model $model): void
    {
        if (! $this->shouldSync($model)) {
            return;
        }

        // Only sync if HubSpot-relevant fields changed
        if (! $this->hasHubspotRelevantChanges($model)) {
            return;
        }

        $this->dispatchSyncJob($model, 'update');
    }

    /**
     * Check if the model should be synced to HubSpot.
     */
    protected function shouldSync(Model $model): bool
    {
        if (config('hubspot.disabled')) {
            return false;
        }

        // Check if model implements HubspotModelInterface and has HubSpot configuration
        if (! $model instanceof HubspotModelInterface) {
            return false;
        }

        $hubspotMap = $model->getHubspotMap();
        if (empty($hubspotMap)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the model has changes relevant to HubSpot.
     */
    protected function hasHubspotRelevantChanges(Model $model): bool
    {
        if (! $model instanceof HubspotModelInterface) {
            return false;
        }

        $hubspotFields = array_values($model->getHubspotMap());

        // Check if any HubSpot-mapped fields have changed
        foreach ($hubspotFields as $field) {
            if ($model->wasChanged($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dispatch the sync job.
     */
    protected function dispatchSyncJob(Model $model, string $operation): void
    {
        if (! config('hubspot.queue.enabled', true)) {
            // For synchronous operation, you could call a service here
            return;
        }

        $jobData = $this->prepareJobData($model);

        SyncHubspotContactJob::dispatch($jobData, $operation, get_class($model));
    }

    /**
     * Prepare data for the job.
     */
    protected function prepareJobData(Model $model): array
    {
        if (! $model instanceof HubspotModelInterface) {
            return [];
        }

        $data = [
            'id' => $model->getKey(),
            'hubspot_id' => $model->getHubspotId(),
            'hubspotMap' => $model->getHubspotMap(),
            'hubspotUpdateMap' => $model->getHubspotUpdateMap(),
            'hubspotCompanyRelation' => $model->getHubspotCompanyRelation(),
        ];

        // Include HubSpot-mapped fields
        foreach ($model->getHubspotMap() as $hubspotField => $modelField) {
            $data[$modelField] = $this->getNestedValue($model, $modelField);
        }

        // Include dynamic properties from overridden hubspotProperties method
        $dynamicProperties = $model->getHubspotProperties($model->getHubspotMap());
        if (! empty($dynamicProperties)) {
            $data['dynamicProperties'] = [];

            foreach ($dynamicProperties as $hubspotField => $value) {
                // Only add if not already included as a mapped field
                if (! in_array($hubspotField, array_values($model->getHubspotMap()))) {
                    $data['dynamicProperties'][$hubspotField] = $value;
                }
            }
        }

        // Include company relation data if it exists
        $companyRelation = $model->getHubspotCompanyRelation();
        if (! empty($companyRelation)) {
            $company = $model->getRelationValue($companyRelation);
            if ($company) {
                $data['hubspotCompanyRelation'] = [
                    'id' => $company->getKey(),
                    'hubspot_id' => $company instanceof HubspotModelInterface ? $company->getHubspotId() : ($company->hubspot_id ?? null),
                    'name' => $company->name ?? $company->getAttribute('name'),
                ];
            }
        }

        return $data;
    }

    /**
     * Get nested value from model using dot notation.
     */
    protected function getNestedValue(Model $model, string $key)
    {
        return data_get($model, $key);
    }
}
