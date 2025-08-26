<?php

namespace Tapp\LaravelHubspot\Commands;

use Illuminate\Console\Command;
use Tapp\LaravelHubspot\Models\HubspotContact;

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
        $contactModel = $this->argument('model');
        $delay = (int) $this->option('delay');
        $limit = $this->option('limit');

        /** @phpstan-ignore-next-line */
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

        // Process contacts
        foreach ($contacts as $contact) {
            try {
                HubspotContact::updateOrCreateHubspotContact($contact);
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
}
