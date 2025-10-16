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
    'enabled' => env('AUDIT_LOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | These options control whether audit logs should be processed in a queue
    | for better performance, and which queue connection and name to use.
    |
    */
    'queue_enabled' => env('AUDIT_LOG_QUEUE', false),
    'queue_connection' => env('AUDIT_LOG_QUEUE_CONNECTION', 'default'),
    'queue_name' => env('AUDIT_LOG_QUEUE_NAME', 'default'),

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
    'retention_days' => env('AUDIT_LOG_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Excluded Events
    |--------------------------------------------------------------------------
    |
    | This array defines which events should be excluded from audit logging.
    | Available events: created, updated, deleted, restored, force_deleted
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

    /*
    |--------------------------------------------------------------------------
    | Authentication Events
    |--------------------------------------------------------------------------
    |
    | This option controls whether authentication events (login, logout, etc.)
    | should be logged in the audit trail.
    |
    */
    'log_authentication_events' => env('AUDIT_LOG_AUTH_EVENTS', true),

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
        'encrypt_sensitive_data' => env('AUDIT_LOG_ENCRYPT', false),
        
        // Hash user IP addresses for privacy
        'hash_ip_addresses' => env('AUDIT_LOG_HASH_IPS', false),
        
        // Anonymize user data after retention period
        'anonymize_old_logs' => env('AUDIT_LOG_ANONYMIZE', false),
    ],
];