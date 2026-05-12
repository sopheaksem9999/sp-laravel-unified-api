<?php

/*
 * Record API Configuration.
 *
 * This configuration file defines the settings and table configurations for the
 * /api/v1 endpoints in the ERP system. It controls access permissions,
 * static relationship definitions, table metadata, and various operational limits
 * for the generic record API that provides CRUD operations across multiple database tables.
 *
 * Key Features:
 * - Table-level read/write permissions
 * - Static relationship definitions (no auto-detection)
 * - Static table metadata (primaryKey, softDeletes, hasTenantId)
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
 * @see CoreRecordController
 * @see RelationshipResolverUtils
 * @see SchemaRegistryUtils
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
    'enable_tenant_id' => false,
    'tenant_column' => 'tenant_id',
    'tenant_header' => 'X-Tenant-ID',
    'table_config_path' => 'records/tables',

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
    'api_prefix' => 'api/v1',

    /*
    |--------------------------------------------------------------------------
    | RPC Route Prefix Configuration
    |--------------------------------------------------------------------------
    |
    | This option controls the route prefix for the Global RPC endpoints.
    | By default, routes are registered under 'rpc' (e.g., /api/v1/rpc/my_function).
    |
    */
    'rpc_prefix' => 'rpc', // or ''

    // Maximum items returned per page for list endpoints
    'per_page_max' => 10000,

    // Maximum items returned for limit parameter (non-paginated requests)
    'limit_max' => 10000,

    // Maximum items per bulk operation
    'bulk_max' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Per-Table Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limits per table for write operations. This allows different
    | tables to have stricter or more lenient rate limits based on their needs.
    |
    | Each entry overrides the global rate limits only for the specified table.
    |
    | Example:
    | 'rate_limits' => [
    |     'users' => [
    |         'create' => ['limit' => 50, 'decay_minutes' => 1],
    |         'update' => ['limit' => 100, 'decay_minutes' => 1],
    |     ],
    |     'invoices' => [
    |         'create' => ['limit' => 10, 'decay_minutes' => 1],
    |     ],
    | ],
    */
    'rate_limits' => [
        // Per-table rate limits (empty = use global defaults)
    ],

    // Enable or disable bulk operation endpoints (POST /bulk, /bulk/create, /bulk/update, /bulk/delete, /bulk/upsert)
    'bulk_operations' => env('SP_BULK_OPERATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Real-Time Broadcast Events
    |--------------------------------------------------------------------------
    |
    | When enabled, a RecordMutated event is fired after each successful
    | create/update/delete on a private per-tenant channel:
    |   private-tenant.{tenantId}
    |
    | broadcast_tables: empty array = broadcast all tables.
    |                   Named array = only those tables are broadcast.
    |
    */
    'broadcast_events' => env('SP_BROADCAST_EVENTS', false),
    'broadcast_tables' => [], // e.g. ['invoices', 'tasks']

    // Cache configuration
    'cache' => [
        // Enable/disable caching globally for the Records API
        'enabled' => env('SP_LARAVEL_API_CACHE_API', false),

        // Cache TTL for query results (seconds)
        'ttl' => env('SP_LARAVEL_API_CACHE_API_TTL', 3600),

        // Cache key prefix for Records API
        'prefix' => 'sp_laravel_api',

        // Per-table cache control (overrides global setting)
        'per_table' => [
            // Example: disable cache for specific tables
            // 'sp_audit_logs' => false,
            // 'real_time_data' => false,
        ],
        'per_table_ttl' => [
            // Example: override cache TTL for specific tables
            // 'sp_audit_logs' => 600,
            // 'real_time_data' => 120,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Docs UI Access
    |--------------------------------------------------------------------------
    |
    | login_api can point to your client project's auth route.
    | Supports:
    | - Relative path: /v1/auth/login
    | - Absolute URL: https://api.example.com/v1/auth/login
    | - access_token_key: token key in login response payload
    | - login_api: client project login route/URL used by docs login form
    | - email: optional fixed docs account email enforced by docs login proxy
    */
    'api_docs' => [
        'is_private' => env('SP_LARAVEL_API_DOCS_PRIVATE', false),
        'access_token_key' => 'access_token',
        'login_api' => '/v1/auth/login',
        'email' => env('SP_LARAVEL_API_DOCS_EMAIL'),
    ],

    // Legacy cache_ttl for backward compatibility (deprecated, use cache.ttl instead)
    'cache_ttl' => env('SP_LARAVEL_API_CACHE_API_TTL', 3600),

    // Maximum nesting depth to prevent performance issues (default: 10)
    'max_depth' => 10,

    // Maximum number of relationships to load per query (prevents N+1 / memory exhaustion)
    'max_relations' => 20,

    // Maximum number of items in a single relationship (e.g., belongsToMany pivot)
    'max_relation_items' => 500,

    // Maximum records for subquery JSON optimization before falling back to
    // the N+1 bulk-loading path. Set higher (e.g. 500) for larger pages with
    // simple relationships. The system auto-disables the optimization when
    // the database or relationship type doesn't support JSON aggregation.
    'subquery_optimization_max_records' => 100,

    /*
    |--------------------------------------------------------------------------
    | Pagination Performance Settings
    |--------------------------------------------------------------------------
    |
    | Configure cursor-based pagination and optional COUNT skipping to handle
    | large datasets more efficiently.
    |
    */
    'pagination' => [
        // Default pagination mode: 'offset' (traditional page/per_page) or 'cursor'
        'default_mode' => env('SP_PAGINATION_DEFAULT_MODE', 'offset'),

        // Cursor pagination settings
        'cursor' => [
            // Default cursor column when not specified in request
            'default_column' => 'id',
            // Enable composite cursors (multi-column for non-unique sort columns)
            'composite_enabled' => true,
        ],

        // When true, paginated list requests skip the COUNT(*) query unless explicitly requested
        'skip_total_default' => env('SP_PAGINATION_SKIP_TOTAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connection Settings
    |--------------------------------------------------------------------------
    |
    | Configure separate read/write connections for query load distribution.
    | Leave empty to use the default database connection.
    |
    */
    'database' => [
        // Read connection name (configured in config/database.php)
        'read_connection' => env('DB_READ_CONNECTION'),
        // Write connection name (configured in config/database.php)
        'write_connection' => env('DB_WRITE_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Index Hints
    |--------------------------------------------------------------------------
    |
    | Per-table index hints to influence the query optimizer on large tables.
    |
    | Example:
    | 'index_hints' => [
    |     'customers' => [
    |         'list' => 'idx_customers_tenant_status_created',
    |         'filter_status' => 'idx_customers_status',
    |     ],
    | ],
    |
    */
    'index_hints' => [],

    /*
    |--------------------------------------------------------------------------
    | Query Profiling
    |--------------------------------------------------------------------------
    |
    | When enabled via ?explain=true query parameter, the response meta will
    | include query execution plans and timing for debugging slow queries.
    |
    */
    'profiling' => [
        // Enable the ?explain=true feature (disabled by default for security)
        'enabled' => env('SP_QUERY_PROFILING_ENABLED', false),
    ],

    // Default cascade behavior for nested writes (can be overridden per endpoint)
    'default_cascade' => [
        'create' => false,  // allow nested create on store
        'update' => false,  // allow nested update on update
        'upsert' => false,  // upsert by primary key when provided
    ],

    // Include debug details in API error responses when enabled.
    // Also writes error payloads/exceptions to Laravel log when enabled.
    // Can also be toggled per request with header: X-Debug: true
    'debug' => env('SP_LARAVEL_API_DEBUG', false),

    // permission
    'permission_separator' => ':', // separator for permission ex: view:invoice
    'restrict_to_own_records' => false, // limit queries to records created by the authenticated user
    'own_records_permission_prefix' => 'viewOwn', // example: viewOwn_invoice

    /*
    |--------------------------------------------------------------------------
    | Config-Driven Middleware Map
    |--------------------------------------------------------------------------
    |
    | Apply custom middleware stacks by endpoint action without editing package
    | routes. The final stack for each request is merged in this order:
    | 1) default["*"] 2) default[group] 3) default[action]
    | 4) tables[{table}]["*"] 5) tables[{table}][group] 6) tables[{table}][action]
    |
    | Groups:
    | - read: list, show
    | - write: create, update, delete, restore, force_delete, upsert, bulk*
    | - function: table_function, global_function
    |
    | Example use case:
    | - public reads
    | - authenticated writes
    | - subscription required for specific table writes
    |
    | 'middleware_map' => [
    |     'default' => [
    |         'read' => [],
    |         'write' => ['auth:sanctum'],
    |         'function' => ['auth:sanctum'],
    |     ],
    |     'tables' => [
    |         'bills' => [
    |             'create' => ['auth:sanctum', 'subscribed'],
    |             'update' => ['auth:sanctum', 'subscribed'],
    |         ],
    |     ],
    | ],
    |
    */
    'middleware_map' => [
        'default' => [
            '*' => [],
            'read' => [],
            'write' => [],
            'function' => [],
        ],
        'tables' => [
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Custom Functions
    |--------------------------------------------------------------------------
    |
    | Define global custom functions that can be accessed via the endpoint:
    | POST/GET/PUT/DELETE /api/v1/{functionName}
    |
    | Each function can be configured with:
    | - type: 'class', 'closure', or 'query'
    | - httpMethod: allowed HTTP methods (optional)
    | - required_params: array of required parameters (optional)
    | - class: class name for 'class' type functions
    | - httpMethod: method name for 'class' type functions (default: 'handle')
    | - closure: callable for 'closure' type functions
    | - query: SQL query for 'query' type functions
    |
    | Example:
    | 'global_functions' => [
    |     'system_status' => [
    |         'type' => 'class',
    |         'class' => 'App\\Services\\SystemStatusService',
    |         'functionName' => 'getStatus',
    |         'httpMethod' => [HttpMethodEnum::GET->value],
    |     ],
    | ],
    |
    */
    'global_functions' => [
        // Add your custom functions here
        // Example functions should be defined in your application's config/record.php
    ],

    'global_triggers' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Column Casting
    |--------------------------------------------------------------------------
    |
    | Define default response casts for all records using a flat map:
    | [column_name => cast_rule].
    |
    | Priority:
    | 1) RecordTableType::$casting (table-level)
    | 2) record.casting (this global map)
    | 3) columns[*].cast
    | 4) inferred cast from columns[*].type / columns[*].udt_name
    |
    */
    'casting' => [
    ],

    'default_validation' => [
        'enabled' => false,
        'only_when_missing' => true,
        'required' => true,
        'types' => true,
        'unique' => true,
        'foreign_keys' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Context Protocol (MCP) Support
    |--------------------------------------------------------------------------
    |
    | Configuration for the AI agent MCP integration.
    | - enabled: Toggle the MCP feature entirely (default: false).
    | - read_only: Globally disable MCP write tools (create, update, delete).
    | - route_prefix: The prefix for HTTP/SSE MCP endpoints.
    | - middleware: The middleware applied to the HTTP/SSE endpoints.
    */
    'mcp' => [
        'enabled' => env('SP_MCP_ENABLED', false),
        'read_only' => env('SP_MCP_READ_ONLY', false),
        'route_prefix' => env('SP_MCP_ROUTE_PREFIX', 'mcp'),
        'middleware' => ['api', 'auth:sanctum'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Configurations
    |--------------------------------------------------------------------------
    |
    | Define table-specific configurations including permissions, metadata,
    | and relationships. Each table configuration should include:
    |
    | - pmsName: Permission system resource name
    | - primaryKey: Primary key column (default: 'id')
    | - softDeletes: Has deleted_at column (default: true)
    | - hasTenantId: Has tenant_id column (default: true)
    | - public: Read/write permissions for public access
    | - relationships: Static relationship definitions
    |
    | Example:
    | 'users' => [
    |     'pmsName' => 'user',
    |     'primaryKey' => 'id',
    |     'softDeletes' => true,
    |     'hasTenantId' => true,
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
