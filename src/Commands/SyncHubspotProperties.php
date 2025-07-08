<?php

namespace Tapp\LaravelHubspot\Commands;

use HubSpot\Client\Crm\Properties\ApiException;
use HubSpot\Client\Crm\Properties\Model\BatchInputPropertyCreate;
use HubSpot\Client\Crm\Properties\Model\PropertyCreate;
use HubSpot\Client\Crm\Properties\Model\PropertyGroupCreate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Facades\Hubspot;

class SyncHubspotProperties extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hubspot:sync-properties';

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

        // TODO code smell using App here
        // @phpstan-ignore-next-line
        $this->syncProperties('contact', \App\Models\User::class, config('hubspot.property_group'));

        return Command::SUCCESS;
    }

    public function syncProperties($object, $model, $group)
    {
        // @phpstan-ignore-next-line
        $response = Hubspot::crm()->properties()->coreApi()->getAll($object, false);

        $hubspotProperties = collect($response->getResults())->pluck('name');

        // Use the hubspotProperties method to get the property keys
        $syncProperties = array_keys((new $model)->hubspotProperties((new $model)->hubspotMap));

        $missingProperties = collect($syncProperties)->diff($hubspotProperties);

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
            // @phpstan-ignore-next-line
            $response = Hubspot::crm()->properties()->batchApi()->create($object, $data);
        } catch (ApiException $e) {
            $this->warn('Error creating properties. '.$e->getResponseBody());

            Log::error($e);
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
            // @phpstan-ignore-next-line
            return Hubspot::crm()->properties()->groupsApi()->create($object, $propertyGroupCreate);
        } catch (ApiException $e) {
            $this->warn('Error creating property group. '.$e->getResponseBody());

            Log::error($e);
        }
    }
}
