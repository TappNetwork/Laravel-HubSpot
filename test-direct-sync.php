<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Tapp\LaravelHubspot\Services\HubspotContactService;

echo "Testing direct API sync for scott@tappnetwork.com\n";

// Find the user by email
$user = \App\Models\User::where('email', 'scott@tappnetwork.com')->first();

if (! $user) {
    echo "User scott@tappnetwork.com not found!\n";
    exit(1);
}

echo "Found user: {$user->email} (ID: {$user->id})\n";
echo 'HubSpot ID: '.($user->hubspot_id ?? 'none')."\n";

// Test the hubspotProperties method to see what dynamic properties are generated
echo "\nTesting hubspotProperties method:\n";
$properties = $user->hubspotProperties($user->hubspotMap);

echo "Generated properties:\n";
foreach ($properties as $key => $value) {
    echo "  {$key}: ".(is_scalar($value) ? $value : gettype($value))."\n";
}

// Prepare data for direct service call
$data = [
    'id' => $user->id,
    'hubspot_id' => $user->hubspot_id,
    'email' => $user->email,
    'first_name' => $user->first_name,
    'last_name' => $user->last_name,
    'hubspotMap' => $user->hubspotMap,
    'hubspotUpdateMap' => $user->hubspotUpdateMap ?? [],
    'hubspotCompanyRelation' => $user->hubspotCompanyRelation ?? '',
    'modelClass' => get_class($user),
];

// Add dynamic properties
$dynamicProperties = $user->hubspotProperties($user->hubspotMap);
$data['dynamicProperties'] = [];

foreach ($dynamicProperties as $hubspotField => $value) {
    // Only add if not already included as a mapped field
    if (! in_array($hubspotField, array_values($user->hubspotMap))) {
        $data['dynamicProperties'][$hubspotField] = $value;
    }
}

echo "\nPrepared data for service:\n";
echo "Dynamic properties to be synced:\n";
foreach ($data['dynamicProperties'] as $key => $value) {
    echo "  {$key}: {$value}\n";
}

// Test direct service call
echo "\nMaking direct API call to HubSpot...\n";
try {
    $service = app(HubspotContactService::class);

    if ($user->hubspot_id) {
        echo "Updating existing contact...\n";
        $result = $service->updateContact($data);
    } else {
        echo "Creating new contact...\n";
        $result = $service->createContact($data, get_class($user));
    }

    echo "API call successful!\n";

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
    echo 'API call failed: '.$e->getMessage()."\n";
    exit(1);
}

echo "\nTest completed successfully!\n";
