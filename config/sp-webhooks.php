<?php

use Sopheak\Core\Enums\RecordColumnTypeEnum;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordColumnType;
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
                // Governed by record.id_type — see MigrationIdHelper::primary()
                // in 2024_01_01_000001_create_sp_webhooks_tables. The declared
                // type is what tells RecordService::createRecord to generate a
                // key; declaring 'string' left the column with no value and no
                // database default, so a POST without a client-supplied id
                // failed the not-null constraint.
                'id' => new RecordColumnType(type: RecordConfigService::idType() === 'uuid' ? RecordColumnTypeEnum::UUID : RecordColumnTypeEnum::BIGINT, nullable: false, primary: true, autoIncrement: RecordConfigService::idType() !== 'uuid'),
                'name' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'url' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'secret' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'is_active' => new RecordColumnType(type: RecordColumnTypeEnum::BOOLEAN, nullable: false),
                // Created by $table->timestamps() in the migration; declaring
                // them is what makes them sortable and filterable.
                'created_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
                'updated_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
            ],
            // Write-only: the HMAC signing secret is chosen by the client on
            // create/update and never returned. WebhookTrigger reads it from
            // the database directly.
            columnHiddens: ['secret'],
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
                'id' => new RecordColumnType(type: RecordConfigService::idType() === 'uuid' ? RecordColumnTypeEnum::UUID : RecordColumnTypeEnum::BIGINT, nullable: false, primary: true, autoIncrement: RecordConfigService::idType() !== 'uuid'),
                'endpoint_id' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'table_name' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'event' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'created_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
                'updated_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
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
                'id' => new RecordColumnType(type: RecordConfigService::idType() === 'uuid' ? RecordColumnTypeEnum::UUID : RecordColumnTypeEnum::BIGINT, nullable: false, primary: true, autoIncrement: RecordConfigService::idType() !== 'uuid'),
                'endpoint_id' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'event' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'payload' => new RecordColumnType(type: RecordColumnTypeEnum::JSON, nullable: false),
                'response_status' => new RecordColumnType(type: RecordColumnTypeEnum::INTEGER),
                'response_body' => new RecordColumnType(type: RecordColumnTypeEnum::STRING),
                'status' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'created_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
                'updated_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
            ],
        ),
    ],
];
