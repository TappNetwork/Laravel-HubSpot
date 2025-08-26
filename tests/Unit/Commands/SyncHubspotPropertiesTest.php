<?php

use Tapp\LaravelHubspot\Commands\SyncHubspotProperties;

test('it has correct signature', function () {
    $command = new SyncHubspotProperties;
    $reflection = new \ReflectionClass($command);
    $signatureProperty = $reflection->getProperty('signature');
    $signatureProperty->setAccessible(true);

    expect($signatureProperty->getValue($command))->toBe('hubspot:sync-properties {--model= : The model class to sync properties for}');
});

test('it has correct description', function () {
    $command = new SyncHubspotProperties;
    $reflection = new \ReflectionClass($command);
    $descriptionProperty = $reflection->getProperty('description');
    $descriptionProperty->setAccessible(true);

    expect($descriptionProperty->getValue($command))->toBe('Create missing hubspot contact properties.');
});

test('it extends console command', function () {
    $command = new SyncHubspotProperties;

    expect($command)->toBeInstanceOf(\Illuminate\Console\Command::class);
});
