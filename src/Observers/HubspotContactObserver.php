<?php

namespace Tapp\LaravelHubspot\Observers;

use Illuminate\Database\Eloquent\Model;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;
use Tapp\LaravelHubspot\Jobs\SyncHubspotContactJob;
use Tapp\LaravelHubspot\Support\HubspotModelDataPreparer;

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

        $this->dispatchSyncJob($model, $this->syncOperation($model));
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

        $ignoredFields = method_exists($model, 'getHubspotSyncIgnoredFields')
            ? $model->getHubspotSyncIgnoredFields()
            : [];

        $hubspotFields = array_values(array_diff(
            $model->getHubspotMap(),
            $ignoredFields,
        ));

        foreach ($hubspotFields as $field) {
            if ($model->wasChanged($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefer create when the model has no HubSpot ID yet so a follow-up
     * mapped-field save (e.g. last login) does not queue a doomed update.
     */
    protected function syncOperation(Model $model): string
    {
        if ($model instanceof HubspotModelInterface && empty($model->getHubspotId())) {
            return 'create';
        }

        return 'update';
    }

    /**
     * Dispatch the sync job.
     */
    public function dispatchSyncJob(Model $model, string $operation): void
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
        return HubspotModelDataPreparer::fromModel($model);
    }
}
