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
    | Audit logs are stored directly in the audit_logs table.
    |
    */
    'audit_log_model' => 'audit_logs',

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
];
