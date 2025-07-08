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

        foreach ($contacts as $contact) {
            HubspotContact::updateOrCreateHubspotContact($contact);
        }

        return Command::SUCCESS;
    }
}
