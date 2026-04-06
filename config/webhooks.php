<?php

use Sopheak\Core\Types\RecordTableType;

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the webhook module settings.
    |
    | - enabled: Enable or disable the webhook module (default: false)
    | - queue_name: The queue connection/name to dispatch webhook jobs to (default: 'default')
    | - max_payload_bytes: Maximum JSON payload size for signing (default: 1MB)
    |
    */
    'enabled' => env('SP_LARAVEL_API_WEBHOOKS_ENABLED', false),
    'queue_name' => env('SP_LARAVEL_API_WEBHOOKS_QUEUE', 'default'),
    'max_payload_bytes' => env('SP_LARAVEL_API_WEBHOOKS_MAX_PAYLOAD', 1048576),

    /*
    |--------------------------------------------------------------------------
    | Webhook Tables Configuration
    |--------------------------------------------------------------------------
    |
    | This file defines the default table configurations for the webhook system.
    | These configurations are automatically merged into the main record.tables
    | configuration by the CoreSpLaravelApiProvider.
    |
    */
    'tables' => [
        'sp_webhook_endpoints' => new RecordTableType(
            table: 'sp_webhook_endpoints',
            pmsName: 'webhook',
            primaryKey: 'id',
            softDeletes: false,
            hasTenantId: true,
            isAuthRead: true,
            isAuthWrite: true,
            columns: [
                'id' => ['type' => 'string', 'nullable' => false],
                'name' => ['type' => 'string', 'nullable' => false],
                'url' => ['type' => 'string', 'nullable' => false],
                'secret' => ['type' => 'string', 'nullable' => false],
                'is_active' => ['type' => 'boolean', 'nullable' => false],
            ],
        ),
        'sp_webhook_subscriptions' => new RecordTableType(
            table: 'sp_webhook_subscriptions',
            pmsName: 'webhook',
            primaryKey: 'id',
            softDeletes: false,
            hasTenantId: true,
            isAuthRead: true,
            isAuthWrite: true,
            columns: [
                'id' => ['type' => 'string', 'nullable' => false],
                'endpoint_id' => ['type' => 'string', 'nullable' => false],
                'table_name' => ['type' => 'string', 'nullable' => false],
                'event' => ['type' => 'string', 'nullable' => false],
            ],
        ),
        'sp_webhook_deliveries' => new RecordTableType(
            table: 'sp_webhook_deliveries',
            pmsName: 'webhook',
            primaryKey: 'id',
            softDeletes: false,
            hasTenantId: true,
            isAuthRead: true,
            isAuthWrite: false, // Deliveries should be read-only via API
            columns: [
                'id' => ['type' => 'string', 'nullable' => false],
                'endpoint_id' => ['type' => 'string', 'nullable' => false],
                'event' => ['type' => 'string', 'nullable' => false],
                'payload' => ['type' => 'json', 'nullable' => false],
                'response_status' => ['type' => 'integer', 'nullable' => true],
                'response_body' => ['type' => 'string', 'nullable' => true],
                'status' => ['type' => 'string', 'nullable' => false],
            ],
        ),
    ],
];
