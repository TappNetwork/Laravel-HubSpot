<?php

namespace Tapp\LaravelHubspot\Commands;

use Illuminate\Console\Command;
use Tapp\LaravelHubspot\Services\HubspotContactService;

class SyncHubspotContacts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hubspot:sync-contacts {model=\App\Models\User} {--delay=0 : Delay between API calls in seconds} {--limit= : Limit the total number of contacts to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create missing hubspot contacts.';

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
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $contactModel */
        $contactModel = $this->argument('model');
        $delay = (int) $this->option('delay');
        $limit = $this->option('limit');

        $contacts = $contactModel::all();

        // Apply limit if specified
        if ($limit) {
            $contacts = $contacts->take((int) $limit);
        }

        $totalContacts = $contacts->count();

        if ($totalContacts === 0) {
            $this->info('No contacts found to sync.');

            return Command::SUCCESS;
        }

        $this->info("Starting HubSpot contact sync for {$totalContacts} contacts...");
        $this->info("Delay between API calls: {$delay}s");

        $progressBar = $this->output->createProgressBar($totalContacts);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        $successCount = 0;
        $errorCount = 0;

        $service = app(HubspotContactService::class);

        // Process contacts
        foreach ($contacts as $contact) {
            try {
                // Prepare data for the service
                $data = $this->prepareContactData($contact);

                if ($contact->hubspot_id) {
                    $service->updateContact($data);
                } else {
                    $service->createContact($data, get_class($contact));
                }

                $successCount++;
                $progressBar->advance();
            } catch (\Exception $e) {
                $errorCount++;
                $this->newLine();
                $this->error("Failed to sync contact {$contact->email}: ".$e->getMessage());
                $progressBar->advance();
            }

            // Add delay between API calls to avoid rate limiting
            if ($delay > 0) {
                sleep($delay);
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Sync Summary:');
        $this->info("- Total contacts processed: {$totalContacts}");
        $this->info("- Successful syncs: {$successCount}");
        $this->info("- Failed syncs: {$errorCount}");

        if ($errorCount > 0) {
            $this->newLine();
            $this->warn("{$errorCount} contacts failed to sync. Check the errors above.");
        }

        $this->info('HubSpot contact sync completed!');

        return $errorCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Prepare contact data for the service.
     */
    protected function prepareContactData(\Illuminate\Database\Eloquent\Model $contact): array
    {
        $data = $contact->toArray();

        // Add HubSpot-specific properties
        $data['hubspotMap'] = $contact->hubspotMap ?? [];
        $data['hubspotUpdateMap'] = $contact->hubspotUpdateMap ?? [];
        $data['hubspotCompanyRelation'] = $contact->hubspotCompanyRelation ?? '';

        // Include dynamic properties from overridden hubspotProperties method
        if (method_exists($contact, 'hubspotProperties')) {
            $dynamicProperties = $contact->hubspotProperties($contact->hubspotMap ?? []);
            if (! empty($dynamicProperties)) {
                $data['dynamicProperties'] = [];

                foreach ($dynamicProperties as $hubspotField => $value) {
                    // Only add if not already included as a mapped field
                    if (! in_array($hubspotField, array_values($contact->hubspotMap ?? []))) {
                        $data['dynamicProperties'][$hubspotField] = $value;
                    }
                }
            }
        }

        // Include company relation data if it exists
        if (! empty($contact->hubspotCompanyRelation)) {
            $company = $contact->getRelationValue($contact->hubspotCompanyRelation);
            if ($company) {
                $data['hubspotCompanyRelation'] = [
                    'id' => $company->getKey(),
                    'hubspot_id' => $company->hubspot_id ?? null,
                    'name' => $company->name ?? $company->getAttribute('name'),
                ];
            }
        }

        return $data;
    }
}
