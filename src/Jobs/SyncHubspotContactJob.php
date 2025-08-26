<?php

namespace Tapp\LaravelHubspot\Jobs;

use Tapp\LaravelHubspot\Services\HubspotContactService;

class SyncHubspotContactJob extends BaseHubspotJob
{
    /**
     * Execute the specific operation (create or update).
     */
    protected function executeOperation(): void
    {
        $service = app(HubspotContactService::class);

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
