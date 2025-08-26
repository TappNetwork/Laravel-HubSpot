<?php

namespace Tapp\LaravelHubspot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Services\HubspotContactService;

class SyncHubspotContactJob implements ShouldQueue
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
            $service = app(HubspotContactService::class);

            if ($this->operation === 'create') {
                $service->createContact($this->modelData, $this->modelClass);
            } else {
                $service->updateContact($this->modelData);
            }
        } catch (\Exception $e) {
            Log::error('HubSpot contact sync job failed', [
                'operation' => $this->operation,
                'model_data' => $this->modelData,
                'error' => $e->getMessage(),
            ]);

            // If it's a rate limit error, retry with delay
            if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate limit')) {
                Log::info('Rate limit detected, releasing job for retry', [
                    'operation' => $this->operation,
                    'model_id' => $this->modelData['id'] ?? null,
                ]);
                $this->release(30); // Retry in 30 seconds

                return;
            }

            // If it's a 409 conflict (duplicate), retry with shorter delay
            if (str_contains($e->getMessage(), '409') || str_contains($e->getMessage(), 'conflict')) {
                Log::info('Conflict detected (likely duplicate company), releasing job for retry', [
                    'operation' => $this->operation,
                    'model_id' => $this->modelData['id'] ?? null,
                ]);
                $this->release(5); // Retry in 5 seconds

                return;
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('HubSpot contact sync job failed permanently', [
            'operation' => $this->operation,
            'model_data' => $this->modelData,
            'error' => $exception->getMessage(),
        ]);
    }
}
