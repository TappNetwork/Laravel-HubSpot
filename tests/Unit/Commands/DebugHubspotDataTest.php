<?php

use Tapp\LaravelHubspot\Commands\DebugHubspotData;

test('it has correct signature', function () {
    $command = new DebugHubspotData;
    $reflection = new \ReflectionClass($command);
    $signatureProperty = $reflection->getProperty('signature');
    $signatureProperty->setAccessible(true);

    expect($signatureProperty->getValue($command))->toBe('hubspot:debug-data {model=\App\Models\User} {--email= : Debug specific contact by email}');
});

test('it has correct description', function () {
    $command = new DebugHubspotData;
    $reflection = new \ReflectionClass($command);
    $descriptionProperty = $reflection->getProperty('description');
    $descriptionProperty->setAccessible(true);

    expect($descriptionProperty->getValue($command))->toBe('Debug HubSpot data to identify invalid properties.');
});

test('it extends console command', function () {
    $command = new DebugHubspotData;

    expect($command)->toBeInstanceOf(\Illuminate\Console\Command::class);
});
