<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

// Only run preset tests if Pest 3.x is available (Laravel 11+)
if (class_exists('Pest\\Preset')) {
    arch('php preset')->preset()->php();
    arch('security preset')->preset()->security();
    arch('laravel preset')->preset()->laravel();
}
