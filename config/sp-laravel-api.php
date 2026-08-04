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

    /*
    |--------------------------------------------------------------------------
    | Config Rename Notice
    |--------------------------------------------------------------------------
    |
    | The package now ships its config files under sp-* names (sp-record.php,
    | sp-audit.php, sp-attachments.php, sp-webhooks.php, sp-permissions.php).
    | Old unprefixed files you have already published keep working, but the
    | package logs an informational notice once per boot naming each one.
    |
    | Set this to true to silence that notice.
    |
    */
    'suppress_config_rename_notice' => env('SP_SUPPRESS_CONFIG_RENAME_NOTICE', false),
];
