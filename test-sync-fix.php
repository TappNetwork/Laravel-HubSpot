<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Tapp\LaravelHubspot\Models\HubspotContact;

echo "Testing dynamic properties sync fix for scott@tappnetwork.com\n";

// Find the user by email
$user = \App\Models\User::where('email', 'scott@tappnetwork.com')->first();

if (! $user) {
    echo "User scott@tappnetwork.com not found!\n";
    exit(1);
}

echo "Found user: {$user->email} (ID: {$user->id})\n";

// Test the hubspotProperties method to see what dynamic properties are generated
echo "\nTesting hubspotProperties method:\n";
$properties = $user->hubspotProperties($user->hubspotMap);

echo "Generated properties:\n";
foreach ($properties as $key => $value) {
    echo "  {$key}: ".(is_scalar($value) ? $value : gettype($value))."\n";
}

// Test the sync
echo "\nSyncing to HubSpot...\n";
try {
    $result = HubspotContact::updateOrCreateHubspotContact($user);
    echo "Sync successful!\n";

    if (isset($result['id'])) {
        echo "HubSpot ID: {$result['id']}\n";
    }

    if (isset($result['properties'])) {
        echo "Synced properties:\n";
        foreach ($result['properties'] as $key => $value) {
            echo "  {$key}: {$value}\n";
        }
    }

} catch (Exception $e) {
    echo 'Sync failed: '.$e->getMessage()."\n";
    exit(1);
}

echo "\nTest completed successfully!\n";
