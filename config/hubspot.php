<?php

// config for Tapp/LaravelHubspot
return [
    'disabled' => env('HUBSPOT_DISABLED', false),
    'api_key' => env('HUBSPOT_TOKEN'),
    'log_requests' => env('HUBSPOT_LOG_REQUESTS', false),
    'use_real_api' => env('HUBSPOT_USE_REAL_API', false),
    'property_group' => env('HUBSPOT_PROPERTY_GROUP', 'app_user_profile'),
    'property_group_label' => env('HUBSPOT_PROPERTY_GROUP_LABEL', 'App User Profile'),

    // Queue configuration
    'queue' => [
        'enabled' => env('HUBSPOT_QUEUE_ENABLED', true),
        'connection' => env('HUBSPOT_QUEUE_CONNECTION', config('queue.default', 'sync')),
        'queue' => env('HUBSPOT_QUEUE_NAME', config('queue.connections.'.config('queue.default', 'sync').'.queue', 'default')),
        'retry_attempts' => env('HUBSPOT_QUEUE_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('HUBSPOT_QUEUE_RETRY_DELAY', 60),
    ],

];
