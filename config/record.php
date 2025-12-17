<?php

/*
 * Record API Configuration.
 *
 * This configuration file defines the settings and table configurations for the
 * /api/v2/record endpoints in the ERP system. It controls access permissions,
 * static relationship definitions, table metadata, and various operational limits
 * for the generic record API that provides CRUD operations across multiple database tables.
 *
 * Key Features:
 * - Table-level read/write permissions
 * - Static relationship definitions (no auto-detection)
 * - Static table metadata (primary_key, soft_deletes, has_tenant_id)
 * - Pagination and bulk operation limits
 * - Caching configuration for performance
 * - Nested relationship depth control
 * - Cascade behavior for nested operations
 * - Automatic removal of deleted_at fields from API responses
 * - Enhanced bulk operations with automatic operation detection
 * - Comprehensive audit logging for all operations
 *
 * Structure:
 * - Global settings (per_page_max, cache_ttl, etc.)
 * - Table configurations with permissions, metadata, and relationships
 * - Relationship types: belongsTo, hasMany, hasManyThrough, belongsToMany
 *
 * @see RecordController
 * @see RelationshipResolver
 * @see SchemaRegistry
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Tenant ID Configuration
    |--------------------------------------------------------------------------
    |
    | This option controls whether tenant_id filtering is enabled across the
    | Record API system. When enabled, all queries will include tenant_id
    | filtering for multi-tenant data isolation. When disabled, tenant_id
    | filtering is completely bypassed for optimal performance in single-tenant
    | or non-tenant environments.
    |
    | Default: false (for backward compatibility with existing projects)
    |
    */
    'enable_tenant_id' => env('RECORD_ENABLE_TENANT_ID', false),

    /*
    |--------------------------------------------------------------------------
    | API Route Prefix Configuration
    |--------------------------------------------------------------------------
    |
    | This option controls the route prefix for the Record API endpoints.
    | By default, routes are registered under 'api' (e.g., /api/customers).
    | You can customize this prefix to match your application's routing structure.
    |
    */
    'api_prefix' => env('RECORD_API_PREFIX', 'api'),

    // Maximum items returned per page for list endpoints
    'per_page_max' => 10000,

    // Maximum items returned for limit parameter (non-paginated requests)
    'limit_max' => 10000,

    // Maximum items per bulk operation
    'bulk_max' => 1000,

    // Cache configuration
    'cache' => [
        // Enable/disable caching globally for the Records API
        'enabled' => env('CACHE_API', false),

        // Cache TTL for query results (seconds)
        'ttl' => 3600,

        // Cache key prefix for Records API
        'prefix' => 'records_api',

        // Per-table cache control (overrides global setting)
        'per_table' => [
            // Example: disable cache for specific tables
            // 'audit_logs' => false,
            // 'real_time_data' => false,
        ],
    ],

    // Legacy cache_ttl for backward compatibility (deprecated, use cache.ttl instead)
    'cache_ttl' => 3600,

    // Maximum nesting depth to prevent performance issues (default: 2)
    'max_depth' => 10,

    // Default cascade behavior for nested writes (can be overridden per endpoint)
    'default_cascade' => [
        'create' => false,  // allow nested create on store
        'update' => false,  // allow nested update on update
        'upsert' => false,  // upsert by primary key when provided
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Custom Functions
    |--------------------------------------------------------------------------
    |
    | Define global custom functions that can be accessed via the endpoint:
    | POST/GET/PUT/DELETE /api/v2/record/{functionName}
    |
    | Each function can be configured with:
    | - type: 'class', 'closure', or 'query'
    | - method: allowed HTTP methods (optional)
    | - required_params: array of required parameters (optional)
    | - class: class name for 'class' type functions
    | - method: method name for 'class' type functions (default: 'handle')
    | - closure: callable for 'closure' type functions
    | - query: SQL query for 'query' type functions
    |
    | Example:
    | 'global_functions' => [
    |     'system_status' => [
    |         'type' => 'class',
    |         'class' => 'App\\Services\\SystemStatusService',
    |         'method' => 'getStatus',
    |         'allowed_methods' => [HttpMethodEnum::GET->value],
    |     ],
    | ],
    |
    */
    'global_functions' => [
        // Add your custom functions here
        // Example functions should be defined in your application's config/record.php
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Configurations
    |--------------------------------------------------------------------------
    |
    | Define table-specific configurations including permissions, metadata,
    | and relationships. Each table configuration should include:
    |
    | - pms_name: Permission system resource name
    | - primary_key: Primary key column (default: 'id')
    | - soft_deletes: Has deleted_at column (default: true)
    | - has_tenant_id: Has tenant_id column (default: true)
    | - public: Read/write permissions for public access
    | - relationships: Static relationship definitions
    |
    | Example:
    | 'users' => [
    |     'pms_name' => 'user',
    |     'primary_key' => 'id',
    |     'soft_deletes' => true,
    |     'has_tenant_id' => true,
    |     'public' => [
    |         'read' => false,
    |         'write' => false,
    |     ],
    |     'relationships' => [
    |         // Define relationships here
    |     ],
    | ],
    |
    */
    'tables' => [
        // Add your table configurations here
        // Example configurations should be defined in your application's config/record.php
    ],
];
