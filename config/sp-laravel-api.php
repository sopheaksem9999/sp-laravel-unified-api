<?php

return [
    'response' => [
        'include_request_id' => true,
    ],
    'openapi' => [
        // default output path relative to project root
        'output' => 'openapi-schema.json',
    ],
    'auth' => [
        'guard' => env('SP_LARAVEL_API_AUTH_GUARD', 'api'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PHP Attribute-Based Table Discovery
    |--------------------------------------------------------------------------
    |
    | When enabled, the package scans the listed paths for Eloquent models
    | annotated with #[RecordTable] and merges them into the schema registry.
    |
    | File-based config (config/records/tables/) always takes precedence.
    |
    | 'paths' is an array of directories relative to the project root.
    |
    */
    'attribute_discovery' => [
        'enabled' => env('SP_ATTRIBUTE_DISCOVERY', false),
        'paths'   => ['app/Models'],
    ],
];
