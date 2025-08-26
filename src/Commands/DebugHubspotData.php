<?php

namespace Tapp\LaravelHubspot\Commands;

use Illuminate\Console\Command;

class DebugHubspotData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hubspot:debug-data {model=\App\Models\User} {--email= : Debug specific contact by email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug HubSpot data to identify invalid properties.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $contactModel = $this->argument('model');
        $email = $this->option('email');

        /** @phpstan-ignore-next-line */
        $query = $contactModel::query();

        if ($email) {
            $query->where('email', $email);
        }

        $contacts = $query->get();

        if ($contacts->isEmpty()) {
            $this->error('No contacts found.');

            return Command::FAILURE;
        }

        $this->info('Debugging data for '.$contacts->count().' contacts...');

        foreach ($contacts as $contact) {
            $this->debugContact($contact);
        }

        return Command::SUCCESS;
    }

    protected function debugContact($contact): void
    {
        $this->newLine();
        $this->info("=== Contact: {$contact->email} ===");

        // Get the property map that will be used
        $map = $this->getPropertyMap($contact);

        $this->line('Property Map Used: '.(empty($map) ? 'EMPTY' : 'hubspotMap'));

        if (! empty($map)) {
            $this->line('Raw Properties (before conversion):');
            foreach ($map as $hubspotProperty => $modelProperty) {
                $value = $this->getPropertyValue($contact, $modelProperty);
                $this->line("  {$hubspotProperty} => {$modelProperty} = ".$this->formatValue($value));
            }

            $this->newLine();
            $this->line('Converted Properties (what gets sent to HubSpot):');
            $convertedProperties = $this->getConvertedProperties($contact, $map);
            foreach ($convertedProperties as $hubspotProperty => $value) {
                $this->line("  {$hubspotProperty} = ".$this->formatValue($value));
            }
        } else {
            $this->warn('No properties will be sent to HubSpot (empty map)');
        }

        // Check for potential issues
        $this->checkForIssues($contact);
    }

    protected function getPropertyMap($contact): array
    {
        // Use the same logic as the trait
        if (property_exists($contact, 'hubspotUpdateMap') && ! empty($contact->hubspotUpdateMap)) {
            return $contact->hubspotUpdateMap;
        }

        return $contact->hubspotMap ?? [];
    }

    protected function getPropertyValue($contact, $property)
    {
        if (strpos($property, '.') !== false) {
            return data_get($contact, $property);
        }

        return $contact->$property ?? null;
    }

    protected function getConvertedProperties($contact, array $map): array
    {
        // Use the same conversion logic as the trait
        $properties = [];

        foreach ($map as $key => $value) {
            if (strpos($value, '.')) {
                $propertyValue = data_get($contact, $value);
            } else {
                $propertyValue = $contact->$value;
            }

            if (is_null($propertyValue)) {
                continue;
            }

            // Convert data types to strings for HubSpot API
            if ($propertyValue instanceof \Carbon\Carbon) {
                $properties[$key] = $propertyValue->toISOString();
            } elseif (is_array($propertyValue)) {
                if (empty($propertyValue)) {
                    continue;
                }
                $properties[$key] = (array_keys($propertyValue) === range(0, count($propertyValue) - 1))
                    ? implode(', ', array_filter($propertyValue, 'is_scalar'))
                    : json_encode($propertyValue);
            } elseif (is_object($propertyValue)) {
                if (method_exists($propertyValue, '__toString')) {
                    $properties[$key] = (string) $propertyValue;
                } elseif (method_exists($propertyValue, 'toArray')) {
                    $arrayValue = $propertyValue->toArray();
                    $properties[$key] = is_array($arrayValue) ? json_encode($arrayValue) : (string) $arrayValue;
                } else {
                    $properties[$key] = 'ERROR: Cannot convert '.get_class($propertyValue);
                }
            } elseif (is_bool($propertyValue)) {
                $properties[$key] = $propertyValue ? 'true' : 'false';
            } elseif (is_numeric($propertyValue)) {
                $properties[$key] = (string) $propertyValue;
            } else {
                $properties[$key] = (string) $propertyValue;
            }
        }

        return $properties;
    }

    protected function formatValue($value): string
    {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_array($value)) {
            return 'ARRAY: '.json_encode($value);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return 'OBJECT: '.(string) $value;
            }

            return 'OBJECT: '.get_class($value);
        }

        if (is_bool($value)) {
            return 'BOOLEAN: '.($value ? 'true' : 'false');
        }

        return gettype($value).': '.(string) $value;
    }

    protected function checkForIssues($contact): void
    {
        $issues = [];

        // Check for arrays in properties
        if (property_exists($contact, 'hubspotMap')) {
            foreach ($contact->hubspotMap as $hubspotProperty => $modelProperty) {
                $value = $this->getPropertyValue($contact, $modelProperty);
                if (is_array($value)) {
                    $issues[] = "Array found in {$hubspotProperty} ({$modelProperty})";
                }
                if (is_object($value) && ! method_exists($value, '__toString')) {
                    $issues[] = "Non-stringable object found in {$hubspotProperty} ({$modelProperty})";
                }
            }
        }

        if (property_exists($contact, 'hubspotUpdateMap')) {
            foreach ($contact->hubspotUpdateMap as $hubspotProperty => $modelProperty) {
                $value = $this->getPropertyValue($contact, $modelProperty);
                if (is_array($value)) {
                    $issues[] = "Array found in {$hubspotProperty} ({$modelProperty})";
                }
                if (is_object($value) && ! method_exists($value, '__toString')) {
                    $issues[] = "Non-stringable object found in {$hubspotProperty} ({$modelProperty})";
                }
            }
        }

        if (! empty($issues)) {
            $this->warn('Potential issues found:');
            foreach ($issues as $issue) {
                $this->line("  - {$issue}");
            }
        } else {
            $this->info('No obvious issues found.');
        }
    }
}
