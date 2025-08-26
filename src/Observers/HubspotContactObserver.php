<?php

namespace Tapp\LaravelHubspot\Observers;

use Illuminate\Database\Eloquent\Model;
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

        // Check if model has HubSpot configuration
        if (! property_exists($model, 'hubspotMap') || empty($model->hubspotMap)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the model has changes relevant to HubSpot.
     */
    protected function hasHubspotRelevantChanges(Model $model): bool
    {
        $hubspotFields = array_values($model->hubspotMap);

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
        $data = [
            'id' => $model->id,
            'hubspot_id' => $model->hubspot_id ?? null,
            'hubspotMap' => $model->hubspotMap ?? [],
            'hubspotUpdateMap' => $model->hubspotUpdateMap ?? [],
            'hubspotCompanyRelation' => $model->hubspotCompanyRelation ?? '',
        ];

        // Only include HubSpot-mapped fields
        foreach ($model->hubspotMap as $hubspotField => $modelField) {
            $data[$modelField] = $this->getNestedValue($model, $modelField);
        }

        // Include company relation data if it exists
        if (! empty($model->hubspotCompanyRelation)) {
            $company = $model->getRelationValue($model->hubspotCompanyRelation);
            if ($company) {
                $data['hubspotCompanyRelation'] = [
                    'id' => $company->id,
                    'hubspot_id' => $company->hubspot_id ?? null,
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
