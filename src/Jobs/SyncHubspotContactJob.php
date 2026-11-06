<?php

namespace Tapp\LaravelHubspot\Jobs;

use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Services\HubspotContactService;

class SyncHubspotContactJob extends BaseHubspotJob
{
    /**
     * Execute the specific operation (create or update).
     */
    protected function executeOperation(): void
    {
        $service = app(HubspotContactService::class);

        Log::info('SyncHubspotContactJob executing operation', [
            'operation' => $this->operation,
            'has_hubspot_id' => ! empty($this->modelData['hubspot_id']),
            'email' => $this->modelData['email'] ?? 'unknown',
        ]);

        if ($this->operation === 'create') {
            $service->createContact($this->modelData, (string) $this->modelClass);
        } else {
            // updateContact persists hubspot_id via data['modelClass'] (e.g. email
            // uniqueness recovery). The job holds the class separately from the
            // payload snapshot, so always inject it for the update path.
            $data = $this->modelData;
            if (is_string($this->modelClass) && $this->modelClass !== '') {
                $data['modelClass'] = $this->modelClass;
            }

            $service->updateContact($data);
        }
    }

    /**
     * Get the job type for logging.
     */
    protected function getJobType(): string
    {
        return 'HubSpot contact';
    }
}
