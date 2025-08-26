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

    /**
     * Convert a value to a string suitable for HubSpot API.
     */
    protected function convertValueForHubspot($value, string $propertyName)
    {
        if (is_null($value)) {
            return null;
        } elseif ($value instanceof \Carbon\Carbon) {
            return $value->toISOString();
        } elseif (is_array($value)) {
            if (empty($value)) {
                return null;
            }

            // Handle translatable fields (associative arrays with language keys)
            if (array_keys($value) !== range(0, count($value) - 1)) {
                // This is an associative array, likely a translatable field
                if (isset($value['en'])) {
                    return $value['en'];
                }

                // If no 'en' key, get the first value
                return (string) reset($value);
            }

            // Handle regular indexed arrays
            return implode(', ', array_filter($value, 'is_scalar'));
        } elseif (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            } elseif (method_exists($value, 'toArray')) {
                $arrayValue = $value->toArray();

                return is_array($arrayValue) ? json_encode($arrayValue) : (string) $arrayValue;
            } else {
                throw new \InvalidArgumentException(
                    'Cannot convert object of type '.get_class($value)." to string for property: {$propertyName}. ".
                    'Objects must implement __toString() or toArray() methods to be automatically converted.'
                );
            }
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (is_numeric($value)) {
            return (string) $value;
        } else {
            return (string) $value;
        }
    }

    /**
     * Validate that all properties are properly converted to strings for HubSpot API.
     */
    protected function validateHubspotProperties(array $properties): void
    {
        $invalidProperties = [];

        foreach ($properties as $key => $value) {
            if (! is_string($value)) {
                $invalidProperties[$key] = [
                    'value' => $value,
                    'type' => gettype($value),
                    'class' => is_object($value) ? get_class($value) : null,
                ];
            }
        }

        if (! empty($invalidProperties)) {
            throw new \InvalidArgumentException(
                'HubSpot properties must be strings after automatic conversion. Invalid properties found: '.
                json_encode($invalidProperties, JSON_PRETTY_PRINT).
                '. This indicates a data type that could not be automatically converted. Please ensure all properties are convertible to strings.'
            );
        }
    }

    /**
     * Get nested value from array using dot notation.
     */
    protected function getNestedValue(array $array, string $key)
    {
        return data_get($array, $key);
    }
}
