<?php

namespace Tapp\LaravelHubspot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

abstract class BaseHubspotJob implements ShouldQueue
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
            $this->executeOperation();
        } catch (\Exception $e) {
            $this->handleJobError($e);
        }
    }

    /**
     * Execute the specific operation (create or update).
     */
    abstract protected function executeOperation(): void;

    /**
     * Handle job errors with retry logic.
     */
    protected function handleJobError(\Exception $e): void
    {
        Log::error($this->getJobType().' sync job failed', [
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
            Log::info('Conflict detected (likely duplicate), releasing job for retry', [
                'operation' => $this->operation,
                'model_id' => $this->modelData['id'] ?? null,
            ]);
            $this->release(5); // Retry in 5 seconds

            return;
        }

        throw $e;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error($this->getJobType().' sync job failed permanently', [
            'operation' => $this->operation,
            'model_data' => $this->modelData,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the job type for logging.
     */
    abstract protected function getJobType(): string;

    /**
     * Update the model with HubSpot ID.
     */
    protected function updateModelHubspotId(string $hubspotId): void
    {
        if (! $this->modelClass || ! class_exists($this->modelClass)) {
            return;
        }

        $model = $this->modelClass::find($this->modelData['id'] ?? null);
        if ($model) {
            $model->update(['hubspot_id' => $hubspotId]);
        }
    }

    /**
     * Extract ID safely from various response types.
     */
    protected function extractId($response): string
    {
        // If it's already an array, return the id
        if (is_array($response)) {
            return $response['id'];
        }

        // If it's an object with getId method, use it
        if (is_object($response) && method_exists($response, 'getId')) {
            return $response->getId();
        }

        // Fallback: try to convert to array
        $responseArray = (array) $response;
        if (isset($responseArray['id'])) {
            return $responseArray['id'];
        }

        throw new \Exception('Unable to extract ID from response');
    }
}
