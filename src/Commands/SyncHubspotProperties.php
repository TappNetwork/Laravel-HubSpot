<?php

namespace Tapp\LaravelHubspot\Commands;

use HubSpot\Client\Crm\Properties\ApiException;
use HubSpot\Client\Crm\Properties\Model\BatchInputPropertyCreate;
use HubSpot\Client\Crm\Properties\Model\PropertyCreate;
use HubSpot\Client\Crm\Properties\Model\PropertyGroupCreate;
use Illuminate\Console\Command;
use Tapp\LaravelHubspot\Facades\Hubspot;

class SyncHubspotProperties extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hubspot:sync-properties {--model= : The model class to sync properties for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create missing hubspot contact properties.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->createPropertyGroup('contact', config('hubspot.property_group'), config('hubspot.property_group_label'));

        // Get the model class from option or use a default
        $modelClass = $this->option('model') ?: config('hubspot.default_model', 'App\\Models\\User');

        if (! class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist.");

            return Command::FAILURE;
        }

        $this->syncProperties('contact', $modelClass, config('hubspot.property_group'));

        return Command::SUCCESS;
    }

    public function syncProperties($object, $model, $group)
    {
        $response = Hubspot::crm()->properties()->coreApi()->getAll($object, false);

        $allHubspotProperties = collect($response->getResults())->pluck('name');

        // Use the hubspotMap to get the property keys, plus any dynamic properties
        $modelInstance = new $model;
        $syncProperties = array_keys($modelInstance->getHubspotMap());

        // Add dynamic properties from hubspotProperties method
        $dynamicProperties = $modelInstance->hubspotProperties($modelInstance->getHubspotMap());
        $dynamicPropertyKeys = array_keys($dynamicProperties);
        $syncProperties = array_unique(array_merge($syncProperties, $dynamicPropertyKeys));

        // Only show HubSpot properties that are relevant to our sync
        $relevantHubspotProperties = $allHubspotProperties->intersect($syncProperties);
        $missingProperties = collect($syncProperties)->diff($allHubspotProperties);

        // Output properties from app
        $this->line('Properties from app: '.implode(', ', $syncProperties));

        // Output existing HubSpot properties that match our sync
        $this->line('Existing HubSpot properties: '.$relevantHubspotProperties->implode(', '));

        // Output missing properties
        $this->line('Missing properties: '.$missingProperties->implode(', '));

        if ($missingProperties->isNotEmpty()) {
            $this->line("creating {$object} properties: ".$missingProperties->implode(', '));
        } else {
            $this->info("{$object} properties already exist");

            return;
        }

        $properties = $missingProperties->map(fn ($name) => new PropertyCreate([
            'name' => $name,
            'label' => $name,
            'type' => 'string',
            'field_type' => 'text',
            'group_name' => $group,
        ]))->values()->toArray();

        $data = new BatchInputPropertyCreate([
            'inputs' => $properties,
        ]);

        try {
            $response = Hubspot::crm()->properties()->batchApi()->create($object, $data);
        } catch (ApiException $e) {
            $this->error('Error creating properties: '.$e->getMessage());
            throw $e;
        }

        $this->info("{$object} properties created");
    }

    public function createPropertyGroup($object, $group, $label)
    {
        $propertyGroupCreate = new PropertyGroupCreate([
            'name' => $group,
            'display_order' => -1,
            'label' => $label,
        ]);

        try {
            return Hubspot::crm()->properties()->groupsApi()->create($object, $propertyGroupCreate);
        } catch (ApiException $e) {
            $this->warn('Error creating property group. '.$e->getResponseBody());
            // Property group might already exist, don't throw for this case
        }
    }
}
