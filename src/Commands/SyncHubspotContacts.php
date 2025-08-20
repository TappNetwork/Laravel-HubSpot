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
    protected $signature = 'hubspot:sync-contacts {model=\App\Models\User}';

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

        /** @phpstan-ignore-next-line */
        $contacts = $contactModel::all();

        $totalContacts = $contacts->count();

        if ($totalContacts === 0) {
            $this->info('No contacts found to sync.');
            return Command::SUCCESS;
        }

        $this->info("Starting HubSpot contact sync for {$totalContacts} contacts...");

        $progressBar = $this->output->createProgressBar($totalContacts);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        foreach ($contacts as $contact) {
            try {
                HubspotContact::updateOrCreateHubspotContact($contact);
                $progressBar->advance();
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to sync contact {$contact->email}: " . $e->getMessage());
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);
        $this->info('HubSpot contact sync completed!');

        return Command::SUCCESS;
    }
}
