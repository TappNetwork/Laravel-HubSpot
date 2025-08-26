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
            $service->createContact($this->modelData, $this->modelClass);
        } else {
            $service->updateContact($this->modelData);
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
