<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit Logging Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the audit logging
    | system. You can enable/disable logging, configure queue settings,
    | and specify which models should be audited.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Enable Audit Logging
    |--------------------------------------------------------------------------
    |
    | This option controls whether audit logging is enabled for the application.
    | When set to false, no audit logs will be created.
    |
    */
    'enabled' => env('SP_LARAVEL_API_AUDIT_LOG_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Audit Log Model
    |--------------------------------------------------------------------------
    |
    | This option is kept for backward compatibility but is no longer used.
    | Audit logs are stored directly in the sp_audit_logs table.
    |
    */
    'audit_log_model' => 'sp_audit_logs',

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | These options control whether audit logs should be processed in a queue
    | for better performance, and which queue connection and name to use.
    |
    */
    'queue_enabled' => env('SP_LARAVEL_API_AUDIT_LOG_QUEUE', false),
    'queue_connection' => env('SP_LARAVEL_API_AUDIT_LOG_QUEUE_CONNECTION', 'default'),
    'queue_name' => env('SP_LARAVEL_API_AUDIT_LOG_QUEUE_NAME', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Log Retention
    |--------------------------------------------------------------------------
    |
    | This option controls how long audit logs should be kept in the database.
    | Logs older than this number of days will be automatically deleted.
    | Set to null to keep logs indefinitely.
    |
    */
    'retention_days' => env('SP_LARAVEL_API_AUDIT_LOG_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Archive Settings
    |--------------------------------------------------------------------------
    |
    | These settings control whether old audit logs should be archived before
    | they are deleted by the retention policy.
    |
    */
    'archive' => [
        'enabled' => env('SP_LARAVEL_API_AUDIT_LOG_ARCHIVE_ENABLED', false),
        'disk' => env('SP_LARAVEL_API_AUDIT_LOG_ARCHIVE_DISK', 'local'),
        'path' => env('SP_LARAVEL_API_AUDIT_LOG_ARCHIVE_PATH', 'audit-archives'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store Diff Only
    |--------------------------------------------------------------------------
    |
    | When enabled, only changed attributes will be stored in old_values and
    | new_values for updated events. This can significantly reduce database size.
    | Set to false (default) to keep backward compatibility and store all data.
    |
    */
    'store_diff_only' => env('SP_LARAVEL_API_AUDIT_LOG_DIFF_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Excluded Events
    |--------------------------------------------------------------------------
    |
    | This array defines which events should be excluded from audit logging.
    | Available events: created, updated, deleted, restored
    |
    */
    'excluded_events' => [
        // 'updated',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Attributes
    |--------------------------------------------------------------------------
    |
    | This array defines which model attributes should be excluded from
    | audit logging. These attributes will not be recorded in the audit trail.
    |
    */
    'excluded_attributes' => [
        'password',
        'remember_token',
        'email_verified_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ],
    'log_relationships' => env('SP_LARAVEL_API_AUDIT_LOG_RELATIONSHIPS', false),
    'subject_fields' => [
        'name',
        'title',
        'ref_number',
        'account_name',
        'entity',
    ],
    'recap_entities' => [
        'estimates',
        'delivery_notes',
        'invoices',
        'sale_orders',
        'pos',
        'purchase_orders',
        'receive_notes',
        'bills',
        'inter_transfers',
        'transfers',
    ],
    'recap_max_fields' => 6,
    'main_field_labels' => [
        'customer_name' => 'Customer',
        'customer_attended_name' => 'Customer Attended',
        'bank_account_name' => 'Bank Account',
        'bank_name' => 'Bank',
        'class_name' => 'Class',
        'location_name' => 'Location',
        'term_name' => 'Term',
        'vendor_name' => 'Vendor',
        'warehouse_name' => 'Warehouse',
        'warehouse' => 'Warehouse',
        'from_warehouse' => 'From Warehouse',
        'to_warehouse' => 'To Warehouse',
        'ref_number' => 'Reference Number',
        'private_note' => 'Private Note',
        'customer_memo' => 'Customer Memo',
        'address' => 'Address',
        'date' => 'Date',
        'due_date' => 'Due Date',
        'total_amount' => 'Total Amount',
    ],
    'entity_labels' => [
        'estimates' => 'Estimate & SO',
        'sale_orders' => 'Sale Receipt',
        'purchase_orders' => 'Purchase Request',
        'receive_notes' => 'Receive Note',
        'inter_transfers' => 'Inter Transfer Request',
        'transfers' => 'Direct Transfer',
        'inventory_valuations' => 'Inventory Movement Detail',
        'inventory_summaries' => 'Inventory Summaries',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Events
    |--------------------------------------------------------------------------
    |
    | This option controls whether authentication events (login, logout, etc.)
    | should be logged in the audit trail.
    |
    */
    'log_authentication_events' => env('SP_LARAVEL_API_AUDIT_LOG_AUTH_EVENTS', true),

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | These settings control performance-related aspects of audit logging.
    |
    */
    'performance' => [
        // Maximum number of relationships to load per audit log
        'max_relationships' => 10,

        // Use database transactions for audit logging
        'use_transactions' => true,

        // Batch size for bulk operations
        'batch_size' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | These settings control security-related aspects of audit logging.
    |
    */
    'security' => [
        // Encrypt sensitive data in audit logs
        'encrypt_sensitive_data' => env('SP_LARAVEL_API_AUDIT_LOG_ENCRYPT', false),

        // Hash user IP addresses for privacy
        'hash_ip_addresses' => env('SP_LARAVEL_API_AUDIT_LOG_HASH_IPS', false),

        // Anonymize user data after retention period
        'anonymize_old_logs' => env('SP_LARAVEL_API_AUDIT_LOG_ANONYMIZE', false),
    ],
    /*
    |--------------------------------------------------------------------------
    | Audit Log Table Configuration
    |--------------------------------------------------------------------------
    |
    | This defines the default table configuration for the audit logs API.
    | It is automatically merged into the main record.tables configuration.
    | By default, create/update/delete are disabled (read-only).
    |
    */
    'tables' => [
        'sp_audit_logs' => new \Sopheak\Core\Types\RecordTableType(
            table: 'sp_audit_logs',
            pmsName: 'audit_log',
            primaryKey: 'id',
            softDeletes: false,
            hasTenantId: true,
            isAuthRead: true,
            isAuthWrite: false,
            canCreate: false,
            canUpdate: false,
            canDelete: false,
            canUpsert: false,
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'entity_name' => ['type' => 'string', 'nullable' => true],
                'entity_type' => ['type' => 'string', 'nullable' => true],
                'entity_id' => ['type' => 'string', 'nullable' => true],
                'user_id' => ['type' => 'string', 'nullable' => true],
                'event' => ['type' => 'string', 'nullable' => true],
                'title' => ['type' => 'string', 'nullable' => true],
                'subject' => ['type' => 'string', 'nullable' => true],
                'recap' => ['type' => 'string', 'nullable' => true],
                'old_data' => ['type' => 'json', 'nullable' => true],
                'new_data' => ['type' => 'json', 'nullable' => true],
                'metadata' => ['type' => 'json', 'nullable' => true],
                'ip_address' => ['type' => 'string', 'nullable' => true],
                'user_agent' => ['type' => 'string', 'nullable' => true],
                'request_id' => ['type' => 'string', 'nullable' => true],
            ],
            relationships: [
                'user' => new \Sopheak\Core\Types\RecordBelongsToType(
                    table: 'users',
                    type: \Sopheak\Core\Enums\RecordRelationshipsEnum::BELONGS_TO,
                    foreignKey: 'user_id',
                    ownerKey: 'id'
                ),
            ],
            functions: [
                'stats' => new \Sopheak\Core\Types\RecordFunctionType(
                    class: \Sopheak\Core\Http\Controllers\AuditLogController::class,
                    functionName: 'getStats',
                    httpMethod: [\Sopheak\Core\Enums\RecordFunctionMethodEnum::GET->value],
                    description: 'Get audit statistics'
                ),
                'field-timeline/{entityType}/{entityId}/{field}' => new \Sopheak\Core\Types\RecordFunctionType(
                    class: \Sopheak\Core\Http\Controllers\AuditLogController::class,
                    functionName: 'getFieldTimeline',
                    httpMethod: [\Sopheak\Core\Enums\RecordFunctionMethodEnum::GET->value],
                    description: 'Get field timeline'
                ),
                'field-stats/{entityType}/{entityId}/{field}' => new \Sopheak\Core\Types\RecordFunctionType(
                    class: \Sopheak\Core\Http\Controllers\AuditLogController::class,
                    functionName: 'getFieldStats',
                    httpMethod: [\Sopheak\Core\Enums\RecordFunctionMethodEnum::GET->value],
                    description: 'Get field statistics'
                ),
            ]
        ),
    ],
];
