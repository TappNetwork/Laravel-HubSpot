<?php

// config for Tapp/LaravelHubspot
return [
    'disabled' => env('HUBSPOT_DISABLED', false),
    'api_key' => env('HUBSPOT_TOKEN'),
    'log_requests' => env('HUBSPOT_LOG_REQUESTS', false),
    'property_group' => env('HUBSPOT_PROPERTY_GROUP', 'app_user_profile'),
    'property_group_label' => env('HUBSPOT_PROPERTY_GROUP_LABEL', 'App User Profile'),

    // Column name on the model for storing the HubSpot contact/company ID (e.g. hubspot_id or hubspot_contact_id)
    'contact_id_column' => env('HUBSPOT_CONTACT_ID_COLUMN', 'hubspot_id'),
    'company_id_column' => env('HUBSPOT_COMPANY_ID_COLUMN', 'hubspot_id'),

    // HubSpot contact property names from hubspotMap whose values are used for
    // pre-create contact lookup (dedupe). Include any custom email properties
    // your portal maps (e.g. work_email, personal_email).
    'contact_email_properties' => [
        'email',
        'secondary_email',
    ],

    // Queue configuration
    'queue' => [
        'enabled' => env('HUBSPOT_QUEUE_ENABLED', true),
        'connection' => env('HUBSPOT_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),
        'queue' => env('HUBSPOT_QUEUE_NAME', env('QUEUE_NAME', 'default')),
        'retry_attempts' => env('HUBSPOT_QUEUE_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('HUBSPOT_QUEUE_RETRY_DELAY', 60),
    ],

];
