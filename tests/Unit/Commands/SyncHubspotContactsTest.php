<?php

use Tapp\LaravelHubspot\Commands\SyncHubspotContacts;

test('it has correct signature', function () {
    $command = new SyncHubspotContacts;
    $reflection = new \ReflectionClass($command);
    $signatureProperty = $reflection->getProperty('signature');
    $signatureProperty->setAccessible(true);

    expect($signatureProperty->getValue($command))->toBe(
        'hubspot:sync-contacts {model=\App\Models\User} {--delay=0 : Delay between API calls in seconds} {--limit= : Limit the total number of contacts to process}'
    );
});

test('it has correct description', function () {
    $command = new SyncHubspotContacts;
    $reflection = new \ReflectionClass($command);
    $descriptionProperty = $reflection->getProperty('description');
    $descriptionProperty->setAccessible(true);

    expect($descriptionProperty->getValue($command))->toBe('Create missing hubspot contacts.');
});

test('it has correct default options', function () {
    $command = new SyncHubspotContacts;
    $reflection = new \ReflectionClass($command);
    $signatureProperty = $reflection->getProperty('signature');
    $signatureProperty->setAccessible(true);
    $signature = $signatureProperty->getValue($command);

    // Test that the command has the expected options
    expect($signature)->toContain('--delay=0');
    expect($signature)->toContain('--limit=');
});

test('it extends console command', function () {
    $command = new SyncHubspotContacts;

    expect($command)->toBeInstanceOf(\Illuminate\Console\Command::class);
});
