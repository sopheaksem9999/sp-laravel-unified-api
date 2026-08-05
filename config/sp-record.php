<?php

use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Support\RecordConfigLoader;

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
 * - Global settings (per_page_max, cache.ttl, etc.)
 * - Table configurations with permissions, metadata, and relationships
 * - Relationship types: belongsTo, hasMany, hasManyThrough, belongsToMany
 *
 * @see CoreRecordController
 * @see RelationshipResolverUtils
 * @see SchemaRegistryUtils
 */

/*
 * Directory holding client-authored table config files, relative to this
 * config directory.
 *
 * Bound to a variable because it is read twice below and the two readings must
 * never disagree: once as the `table_config_path` key -- which
 * RecordConfigService::tableConfigPath() exposes and which
 * MakeRecordTableCommand, SyncRecordColumnsCommand and
 * GenerateRecordTablesFromDatabaseCommand all write generated table configs
 * into -- and once as the directory the `autoloaded` scan at the bottom of
 * this file actually reads.
 *
 * `autoloaded => true` disables RecordConfigService's runtime scan, and that
 * runtime scan is the only other reader of `table_config_path`. So the loader
 * call below is the SOLE consumer of this directory in a published install:
 * hardcoding a directory there instead of reusing this value would mean a
 * client who changes `table_config_path` keeps generating table configs into
 * their chosen directory while nothing ever loads them -- every CRUD route
 * those tables defined 404s, with no error anywhere.
 *
 * Change the path here and both follow.
 */
$tableConfigPath = 'records/tables';

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
    'tenant_column_type' => 'string', // PGSQL RLS cast type: string, integer, bigint, uuid

    /*
    |--------------------------------------------------------------------------
    | Bundled Module ID Type
    |--------------------------------------------------------------------------
    |
    | Controls the primary key type for the package's own sp_permissions and
    | sp_roles tables, and for the foreign key columns that reference them.
    |
    | Supported values: 'integer' (default) or 'uuid'.
    |
    | Set this to 'uuid' if your project uses UUID primary keys, so the roles
    | and permissions API surface matches the rest of your tables.
    |
    | Choose this BEFORE running the package migrations and do not change it
    | afterwards. It is read only while the tables are being created, so a later
    | change does not alter them — it just makes this setting disagree with the
    | schema, and the mismatch fails loudly on the next write rather than
    | degrading quietly. Switching to 'uuid' after migrating makes role creation
    | write a uuid into an integer id column (PostgreSQL: invalid input syntax
    | for type bigint); switching back to 'integer' makes it insert no id at all
    | into a column that has no default (PostgreSQL: null value in column "id").
    | Converting an already-migrated project means writing your own migration
    | for sp_permissions.id, sp_roles.id and every foreign key listed above.
    |
    | Not governed by this setting:
    | - sp_attachments, sp_attachment_folders and sp_webhook_* always use uuid.
    | - The pivot ids (sp_role_permissions, sp_model_has_roles,
    |   sp_model_permissions) and sp_audit_logs.id are always auto-incrementing
    |   integers. Nothing references them and their insert paths supply no id.
    |
    */
    'id_type' => 'integer', // uuid|integer
    'tenant_header' => 'X-Tenant-ID',
    // Change this at the $tableConfigPath binding above the return, not here:
    // the `autoloaded` scan at the bottom of this file reads the same value.
    'table_config_path' => $tableConfigPath,

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
        'admission' => [
            // Optional cache admission controls for avoiding low-hit dynamic queries.
            // These rules are inactive while all arrays are empty.
            'only_tables' => [],
            'except_tables' => [],
            'only_actions' => [],
            'except_actions' => [],
            'skip_query_params' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Tenant Context
    |--------------------------------------------------------------------------
    |
    | Controls the optional pgsql.tenant middleware behavior. The default
    | remains session-scoped for backward compatibility.
    |
    | Supported modes:
    | - session: existing SET SESSION behavior
    | - session_once: SET SESSION once per request/tenant/connection
    | - transaction_local: request-scoped set_config(..., true), for transaction-wrapped queries
    | - off: skip tenant context SQL
    |
    */
    'pgsql_tenant_context' => [
        'mode' => env('SP_PGSQL_TENANT_CONTEXT_MODE', 'session'),
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

    // Maximum nesting depth to prevent performance issues (default: 10)
    'max_depth' => 10,

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

        // When true, paginated list requests default to total=false unless clients pass total=true
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
    // Scanned here rather than at runtime so `php artisan config:cache` bakes
    // the result into the cached payload and production does no filesystem
    // scanning. `autoloaded` tells RecordConfigService to skip its own scan.
    //
    // The directory names come from RecordConfigService::globalFunctionDirectoryNames()
    // -- the same list the runtime scan (config_path()-based) uses -- so the
    // two cannot drift apart into scanning different directories.
    //
    // NOTE: values reachable from here must be var_export()-able. A Closure
    // validator or a 'type' => 'closure' global function will make
    // `php artisan config:cache` fail. Use [MyValidator::class, 'method'] instead.
    'autoloaded' => true,
    'global_functions' => RecordConfigLoader::globalFunctions(
        ...array_map(
            static fn (string $name): string => __DIR__ . '/' . $name,
            RecordConfigService::globalFunctionDirectoryNames(),
        ),
    ),

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
    // Scanned from the $tableConfigPath directory in the client's own config
    // directory -- the same value the `table_config_path` key above reports,
    // deliberately, so the two cannot name different directories. See the
    // `autoloaded` note above the `global_functions` key for why this is
    // resolved here rather than at request time.
    'tables' => RecordConfigLoader::tables(__DIR__ . '/' . $tableConfigPath),
];
