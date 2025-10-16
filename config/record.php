<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\DeliveryNoteController;
use App\Http\Controllers\EmployeeRosterController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\InventoryValuationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReceiveNoteController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Utilities\Enums\HttpMethodEnum;
use App\Utilities\Types\RecordBelongsToType;
use App\Utilities\Types\RecordFunctionType;
use App\Utilities\Types\RecordHasManyThroughType;
use App\Utilities\Types\RecordHasManyType;
use App\Utilities\Types\RecordMetaBelongsToManyType;
use App\Utilities\Types\RecordSpatiePermissionType;
use App\Utilities\Types\RecordTablePublic;
use App\Utilities\Types\RecordTableType;

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
 * ## Table Configuration Structure:
 *
 * ### Basic Table with Static Metadata and Relationships:
 * ```php
 * 'customers' => [
 *     'pms_name' => 'customer',              // Permission system resource name
 *     'primary_key' => 'id',                 // Primary key column (default: 'id')
 *     'soft_deletes' => false,               // Has deleted_at column (default: true)
 *     'has_tenant_id' => true,               // Has tenant_id column (default: true)
 *     'public' => [
 *         'read' => false,
 *         'write' => false,
 *     ],
 *     'relationships' => [
 *         'invoices' => [
 *             'table' => 'invoices',
 *             'type' => 'hasMany',
 *             'foreignKey' => 'customer_id',
 *             'localKey' => 'id',
 *         ],
 *         'delivery_notes' => [
 *             'table' => 'delivery_notes',
 *             'type' => 'hasMany',
 *             'foreignKey' => 'customer_id',
 *             'localKey' => 'id',
 *         ]
 *     ],
 * ],
 * ```
 *
 * ### Complex Table with Multiple Relationship Types:
 * ```php
 * 'invoices' => [
 *     'pms_name' => 'invoice',
 *     'primary_key' => 'id',
 *     'soft_deletes' => true,
 *     'has_tenant_id' => true,
 *     'public' => [
 *         'read' => false,
 *         'write' => false,
 *     ],
 *     'relationships' => [
 *         // belongsTo relationship
 *         'customer' => [
 *             'table' => 'customers',
 *             'type' => 'belongsTo',
 *             'foreignKey' => 'customer_id',
 *             'ownerKey' => 'id',
 *         ],
 *         // hasMany relationship
 *         'items' => [
 *             'table' => 'invoice_items',
 *             'type' => 'hasMany',
 *             'foreignKey' => 'invoice_id',
 *             'localKey' => 'id',
 *         ],
 *         // hasManyThrough relationship
 *         'receivePayments' => [
 *             'table' => 'receive_payments',
 *             'type' => 'hasManyThrough',
 *             'through' => 'receive_payment_items',
 *             'firstKey' => 'invoice_id',
 *             'secondKey' => 'id',
 *             'localKey' => 'id',
 *             'secondLocalKey' => 'receive_payment_id',
 *         ],
 *         // belongsToMany with pivot table and conditions
 *         'deliveryNotes' => [
 *             'table' => 'delivery_notes',
 *             'type' => 'belongsToMany',
 *             'pivot' => 'meta',
 *             'foreignPivotKey' => 'owner_id',
 *             'relatedPivotKey' => 'target_id',
 *             'pivotWhere' => [
 *                 ['column' => 'owner', 'operator' => '=', 'value' => 'invoice'],
 *             ],
 *         ]
 *     ],
 * ],
 * ```
 *
 * ### Table with Minimal Configuration:
 * ```php
 * 'delivery_notes' => [
 *     'pms_name' => 'deliveryNote',
 *     // Uses defaults: primary_key='id', soft_deletes=true, has_tenant_id=true
 *     'public' => [
 *         'read' => false,
 *         'write' => false,
 *     ],
 *     // Relationships must be explicitly defined when needed
 *     // No auto-detection - all relationships are statically configured
 * ],
 * ```
 *
 * ### Table with Legacy Configuration:
 * ```php
 * 'products' => [
 *     'pms_name' => 'product',
 *     'public' => [
 *         'read' => true,
 *         'write' => false,
 *     ],
 *     // Uses legacy 'legacy' array for backward compatibility
 *     'legacy' => [
 *         'categories' => [
 *             'table' => 'categories',
 *             'type' => 'belongsTo',
 *             'foreignKey' => 'category_id',
 *         ]
 *     ],
 * ]
 * ```
 *
 * ## Static Configuration Architecture:
 *
 * ### Table Metadata Configuration:
 * All table metadata is statically defined in this configuration file:
 * - `primary_key`: Primary key column name (default: 'id')
 * - `soft_deletes`: Whether table uses soft deletes (default: true)
 * - `has_tenant_id`: Whether table has tenant_id column (default: true)
 *
 * ### Relationship Definition Requirements:
 * All relationships must be explicitly defined - no auto-detection is performed.
 *
 * **1. belongsTo** - Explicit Definition Required
 * - Must define: table, type, foreignKey, ownerKey
 * - Example: `delivery_notes.customer_id` -> `customers.id`
 * ```php
 * 'customer' => [
 *     'table' => 'customers',
 *     'type' => 'belongsTo',
 *     'foreignKey' => 'customer_id',
 *     'ownerKey' => 'id',
 * ]
 * ```
 *
 * **2. hasMany** - Explicit Definition Required
 * - Must define: table, type, foreignKey, localKey
 * - Example: `customers` -> `invoices.customer_id`
 * ```php
 * 'invoices' => [
 *     'table' => 'invoices',
 *     'type' => 'hasMany',
 *     'foreignKey' => 'customer_id',
 *     'localKey' => 'id',
 * ]
 * ```
 *
 * **3. hasManyThrough** - Complex Relationship Definition
 * - Must define: table, type, through, firstKey, secondKey, localKey, secondLocalKey
 * - Example: invoices -> receive_payments through receive_payment_items
 * ```php
 * 'receivePayments' => [
 *     'table' => 'receive_payments',
 *     'type' => 'hasManyThrough',
 *     'through' => 'receive_payment_items',
 *     'firstKey' => 'invoice_id',
 *     'secondKey' => 'id',
 *     'localKey' => 'id',
 *     'secondLocalKey' => 'receive_payment_id',
 * ]
 * ```
 *
 * **4. belongsToMany** - Pivot Table Relationship
 * - Must define: table, type, pivot, foreignPivotKey, relatedPivotKey, pivotWhere
 * - Example: invoices -> delivery_notes through meta pivot table
 * ```php
 * 'deliveryNotes' => [
 *     'table' => 'delivery_notes',
 *     'type' => 'belongsToMany',
 *     'pivot' => 'meta',
 *     'foreignPivotKey' => 'owner_id',
 *     'relatedPivotKey' => 'target_id',
 *     'pivotWhere' => [
 *         ['column' => 'owner', 'operator' => '=', 'value' => 'invoice'],
 *     ],
 * ]
 * ```
 *
 * Relationship Resolution Priority:
 * 1. Explicit relationships defined in 'relationships' array
 *    Example: invoices.relationships.customer (explicitly defined)
 * 2. Legacy relationship configurations (deprecated)
 *    Example: Old config format for backward compatibility
 * 3. No fallback - all relationships must be explicitly configured
 *    No auto-detection is performed for performance and reliability
 *
 * Security:
 * - All tables default to read=false, write=false for security
 * - Public access must be explicitly enabled per table
 * - PMS (Permission Management System) names for authorization
 *
 * Performance:
 * - Static metadata configuration (no database introspection)
 * - Static relationship definitions (no auto-detection overhead)
 * - Relationship depth limiting (max_depth)
 * - Bulk operation limits (bulk_max)
 * - Query result caching integration
 *
 * ## API Response Behavior:
 *
 * ### Automatic deleted_at Field Removal:
 * All API responses automatically exclude 'deleted_at' fields from the response data.
 * This applies to:
 * - Individual record retrieval (show method)
 * - Collection retrieval (index method)
 * - Nested relationships and includes
 * - Bulk operation responses
 *
 * The removal is performed recursively on all nested objects and arrays,
 * ensuring clean API responses without exposing soft-delete timestamps.
 *
 * ### Query Filtering for Soft Deletes:
 * - Tables with soft_deletes=true automatically filter out deleted records
 * - Query includes whereNull('deleted_at') condition
 * - Only non-deleted records are retrieved from the database
 * - Response filtering removes any remaining deleted_at fields
 *
 * ## Enhanced Bulk Operations:
 *
 * ### Automatic Operation Detection:
 * The bulk endpoint automatically determines operation type based on data structure:
 * ```php
 * // Create operation: no 'id' field present
 * {"name": "New Customer", "email": "new@example.com"}
 *
 * // Update operation: 'id' field present with other data
 * {"id": 123, "name": "Updated Customer", "email": "updated@example.com"}
 *
 * // Delete operation: only 'id' field present
 * {"id": 123}
 * ```
 *
 * ### Bulk Request Format:
 * Supports direct JSON array input without requiring wrapper objects:
 * ```json
 * [
 *   {"name": "Customer 1", "email": "customer1@example.com"},
 *   {"id": 456, "name": "Updated Customer"},
 *   {"id": 789}
 * ]
 * ```
 *
 * ### Bulk Response Format:
 * ```json
 * {
 *   "success": true,
 *   "message": "Bulk operation completed successfully",
 *   "data": [
 *     // Created and updated records (full data)
 *     {"id": 123, "name": "Customer 1", "email": "customer1@example.com"},
 *     {"id": 456, "name": "Updated Customer", "email": "existing@example.com"}
 *   ],
 *   "meta": {
 *     "affected": 3,
 *     "request_id": "req_abc123"
 *   }
 * }
 * ```
 *
 * ### Audit Logging:
 * All bulk operations generate comprehensive audit logs:
 * - Individual audit entries for each create, update, and delete operation
 * - Complete record data captured using show() method for full context
 * - Audit logs include relationship data when available
 * - Request ID tracking for operation correlation
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
    | Usage:
    | - Set to true for multi-tenant applications requiring data isolation
    | - Set to false for single-tenant applications or legacy systems
    | - Can be controlled via RECORD_ENABLE_TENANT_ID environment variable
    |
    | Performance Impact:
    | - When disabled: No tenant_id queries are executed, optimal performance
    | - When enabled: Additional WHERE clauses for tenant_id filtering
    |
    | Security:
    | - When enabled: Automatic tenant data isolation
    | - When disabled: No automatic tenant filtering (ensure proper authorization)
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
    | Examples:
    | - 'api' => /api/customers
    | - 'api/v1' => /api/v1/customers
    | - 'api/v2' => /api/v2/customers
    | - 'records' => /records/customers
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
    | -  'closure', or 'query'
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
    |     'custom_report' => [
    |         'type' => 'query',
    |         'query' => 'SELECT * FROM reports WHERE type = ::report_type',
    |         'required_params' => ['report_type'],
    |         'allowed_methods' => [HttpMethodEnum::GET->value, HttpMethodEnum::POST->value],
    |     ],
    | ],
    |
    */
    'global_functions' => [
        // Report Functions - Migrated from v1 API routes
        'report_estimate' => new RecordFunctionType(
            pms_name: 'view_estimateReport',
            method: [HttpMethodEnum::GET->value],
            class: EstimateController::class,
            function_method: 'listEstimateReport',
            description: 'Generate estimate reports with filtering options'
        ),
        'report_so_customer' => new RecordFunctionType(
            pms_name: 'view_soReport',
            method: [HttpMethodEnum::GET->value],
            class: EstimateController::class,
            function_method: 'listSoCustomerReport',
            description: 'Generate sales order customer reports'
        ),
        'report_so_item_pending' => new RecordFunctionType(
            pms_name: 'view_soReport',
            method: [HttpMethodEnum::GET->value],
            class: EstimateController::class,
            function_method: 'listSoItemPendingReport',
            description: 'Generate pending sales order item reports'
        ),
        'report_dn_item_pending' => new RecordFunctionType(
            pms_name: 'view_deliveryNoteReport',
            method: [HttpMethodEnum::GET->value],
            class: DeliveryNoteController::class,
            function_method: 'listDeliveryNodeItemPendingReport',
            description: 'Generate pending delivery note item reports'
        ),
        'report_delivery_note' => new RecordFunctionType(
            pms_name: 'view_deliveryNoteReport',
            method: [HttpMethodEnum::GET->value],
            class: DeliveryNoteController::class,
            function_method: 'listDeliveryNoteReport',
            description: 'Generate delivery note reports with filtering options'
        ),
        'report_invoice' => new RecordFunctionType(
            pms_name: 'view_invoiceReport',
            method: [HttpMethodEnum::GET->value],
            class: InvoiceController::class,
            function_method: 'lisInvoiceReport',
            description: 'Generate invoice reports with filtering options'
        ),
        'report_customer_invoice' => new RecordFunctionType(
            pms_name: 'view_invoiceReport',
            method: [HttpMethodEnum::GET->value],
            class: InvoiceController::class,
            function_method: 'listCustomerInvoiceReport',
            description: 'Generate customer-specific invoice reports'
        ),
        'report_product' => new RecordFunctionType(
            pms_name: 'view_productReport',
            method: [HttpMethodEnum::GET->value],
            class: ProductController::class,
            function_method: 'listProductReport',
            description: 'Generate product reports with filtering options'
        ),
        'report_purchase_order' => new RecordFunctionType(
            pms_name: 'view_purchaseOrderReport',
            method: [HttpMethodEnum::GET->value],
            class: PurchaseOrderController::class,
            function_method: 'listPurchaseOrderReport',
            description: 'Generate purchase order reports with filtering options'
        ),
        'report_receive_note' => new RecordFunctionType(
            pms_name: 'view_receiveNoteReport',
            method: [HttpMethodEnum::GET->value],
            class: ReceiveNoteController::class,
            function_method: 'listReceiveNoteReport',
            description: 'Generate receive note reports with filtering options'
        ),
        'report_bill' => new RecordFunctionType(
            pms_name: 'view_billReport',
            method: [HttpMethodEnum::GET->value],
            class: BillController::class,
            function_method: 'listBillReport',
            description: 'Generate bill reports with filtering options'
        ),
        'report_inventory_valuation' => new RecordFunctionType(
            pms_name: 'view_inventoryValuationReport',
            method: [HttpMethodEnum::GET->value],
            class: InventoryValuationController::class,
            function_method: 'listInventoryValuationReport',
            description: 'Generate inventory valuation reports'
        ),
        'report_inventory_summary' => new RecordFunctionType(
            pms_name: 'view_inventorySummaryReport',
            method: [HttpMethodEnum::GET->value],
            class: InventoryValuationController::class,
            function_method: 'listInventorySummaryReport',
            description: 'Generate inventory summary reports'
        ),
    ],

    // Tables exposed via /api/v2/record endpoints with permissions and relationships
    // Embedding is always enabled; access is controlled per-table and by resolver depth
    'tables' => [
        'settings' => new RecordTableType(
            pms_name: 'settings',
            public: new RecordTablePublic(
                read: true,
                write: false,
            ),
        ),

        'users' => new RecordTableType(
            pms_name: 'user',
            disable_auditLog: true,
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'employee_group' => new RecordBelongsToType(
                    table: 'employee_groups',
                    foreignKey: 'employee_group_id',
                    ownerKey: 'id',
                ),
                'designation' => new RecordBelongsToType(
                    table: 'designations',
                    foreignKey: 'designation_id',
                    ownerKey: 'id',
                ),
                'department' => new RecordBelongsToType(
                    table: 'departments',
                    foreignKey: 'department_id',
                    ownerKey: 'id',
                ),
                'roles' => new RecordSpatiePermissionType(
                    related: 'roles',
                    relation: \App\Models\User::class,
                    table: config('permission.table_names.model_has_roles'),
                    foreignPivotKey: config('permission.column_names.model_morph_key'),
                    relatedPivotKey: 'role_id',
                    parentKey: 'id',
                    relatedKey: 'id',
                    teamsEnabled: config('permission.teams', false),
                ),
            ],
            functions: [
                'list' => new RecordFunctionType(
                    pms_name: 'view_employee',
                    method: [HttpMethodEnum::GET->value],
                    class: UserController::class,
                    function_method: 'listUser',
                ),
                'create' => new RecordFunctionType(
                    pms_name: 'create_employee',
                    method: [HttpMethodEnum::POST->value],
                    class: UserController::class,
                    function_method: 'createUser',
                ),
                'update/{id}' => new RecordFunctionType(
                    pms_name: 'update_employee',
                    method: [HttpMethodEnum::POST->value],
                    class: UserController::class,
                    function_method: 'updateUser',
                ),
                'delete/{id}' => new RecordFunctionType(
                    pms_name: 'delete_employee',
                    method: [HttpMethodEnum::POST->value],
                    class: UserController::class,
                    function_method: 'deleteUser',
                ),
            ],
        ),

        'modules' => new RecordTableType(
            pms_name: 'module',
            public: new RecordTablePublic(
                read: true,
                write: false,
            ),
        ),

        'roles' => new RecordTableType(
            pms_name: 'role',
            table: 'roles',
            soft_deletes: false,
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            functions: [
                'role_permission' => new RecordFunctionType(
                    pms_name: 'view_role',
                    method: [HttpMethodEnum::GET->value],
                    class: RoleController::class,
                    function_method: 'listRolePermission',
                ),
                'role_permission/{id}' => new RecordFunctionType(
                    pms_name: 'view_role',
                    method: [HttpMethodEnum::GET->value],
                    class: RoleController::class,
                    function_method: 'getRolePermissionById',
                ),
            ]
        ),

        'customers' => new RecordTableType(
            pms_name: 'customer',
            soft_deletes: false,
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'updated_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                ),
                'invoices' => new RecordHasManyType(
                    table: 'invoices',
                    foreignKey: 'customer_id',
                ),
                'estimates' => new RecordHasManyType(
                    table: 'estimates',
                    foreignKey: 'customer_id',
                ),
                'deliveryNote' => new RecordHasManyType(
                    table: 'delivery_notes',
                    foreignKey: 'customer_id',
                ),
            ],
        ),

        'products' => new RecordTableType(
            pms_name: 'product',
            soft_deletes: false,
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'invoiceItems' => new RecordHasManyType(
                    table: 'invoice_items',
                    foreignKey: 'product_id',
                    localKey: 'qbo_id',
                ),
                'invoices' => new RecordHasManyThroughType(
                    table: 'invoices',
                    through: 'invoice_items',
                    firstKey: 'product_id',
                    secondKey: 'id',
                    localKey: 'qbo_id',
                    secondLocalKey: 'invoice_id',
                ),
                'created_by_user' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                ),
                'updated_by_user' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
            ],
        ),

        'vendors' => new RecordTableType(
            pms_name: 'vendor',
            soft_deletes: false,
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'bills' => new RecordHasManyType(
                    table: 'bills',
                    foreignKey: 'vendor_id',
                    localKey: 'id',
                ),
                'purchaseOrders' => new RecordHasManyType(
                    table: 'purchase_orders',
                    foreignKey: 'vendor_id',
                    localKey: 'id',
                ),
                'receiveNotes' => new RecordHasManyType(
                    table: 'receive_notes',
                    foreignKey: 'vendor_id',
                    localKey: 'id',
                ),
            ],
        ),

        'banks' => new RecordTableType(
            pms_name: 'bank',
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'invoices' => new RecordHasManyType(
                    table: 'invoices',
                    foreignKey: 'bank_id',
                    localKey: 'id',
                ),
                'estimates' => new RecordHasManyType(
                    table: 'estimates',
                    foreignKey: 'bank_id',
                    localKey: 'id',
                ),
                'deliveryNotes' => new RecordHasManyType(
                    table: 'delivery_notes',
                    foreignKey: 'bank_id',
                    localKey: 'id',
                ),
                'bills' => new RecordHasManyType(
                    table: 'bills',
                    foreignKey: 'bank_id',
                    localKey: 'id',
                ),
                'purchaseOrders' => new RecordHasManyType(
                    table: 'purchase_orders',
                    foreignKey: 'bank_id',
                    localKey: 'id',
                ),
            ],
        ),
        'terms' => new RecordTableType(
            pms_name: 'term',
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'bills' => new RecordHasManyType(
                    table: 'bills',
                    foreignKey: 'term_id',
                    localKey: 'id',
                ),
                'invoices' => new RecordHasManyType(
                    table: 'invoices',
                    foreignKey: 'term_id',
                    localKey: 'id',
                ),
                'estimates' => new RecordHasManyType(
                    table: 'estimates',
                    foreignKey: 'term_id',
                    localKey: 'id',
                ),
                'deliveryNotes' => new RecordHasManyType(
                    table: 'delivery_notes',
                    foreignKey: 'term_id',
                    localKey: 'id',
                ),
                'purchaseOrders' => new RecordHasManyType(
                    table: 'purchase_orders',
                    foreignKey: 'term_id',
                    localKey: 'id',
                ),
            ],
        ),

        'locations' => new RecordTableType(
            pms_name: 'location',
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'bills' => new RecordHasManyType(
                    table: 'bills',
                    foreignKey: 'location_id',
                    localKey: 'id',
                ),
                'invoices' => new RecordHasManyType(
                    table: 'invoices',
                    foreignKey: 'location_id',
                    localKey: 'id',
                ),
                'estimates' => new RecordHasManyType(
                    table: 'estimates',
                    foreignKey: 'location_id',
                    localKey: 'id',
                ),
                'deliveryNotes' => new RecordHasManyType(
                    table: 'delivery_notes',
                    foreignKey: 'location_id',
                    localKey: 'id',
                ),
                'purchaseOrders' => new RecordHasManyType(
                    table: 'purchase_orders',
                    foreignKey: 'location_id',
                    localKey: 'id',
                ),
                'receiveNotes' => new RecordHasManyType(
                    table: 'receive_notes',
                    foreignKey: 'location_id',
                    localKey: 'id',
                ),
            ],
        ),

        'warehouses' => new RecordTableType(
            pms_name: 'warehouse',
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'bills' => new RecordHasManyType(
                    table: 'bills',
                    foreignKey: 'warehouse_id',
                    localKey: 'id',
                ),
                'invoices' => new RecordHasManyType(
                    table: 'invoices',
                    foreignKey: 'warehouse_id',
                    localKey: 'id',
                ),
                'estimates' => new RecordHasManyType(
                    table: 'estimates',
                    foreignKey: 'warehouse_id',
                    localKey: 'id',
                ),
                'deliveryNotes' => new RecordHasManyType(
                    table: 'delivery_notes',
                    foreignKey: 'warehouse_id',
                    localKey: 'id',
                ),
                'purchaseOrders' => new RecordHasManyType(
                    table: 'purchase_orders',
                    foreignKey: 'warehouse_id',
                    localKey: 'id',
                ),
                'receiveNotes' => new RecordHasManyType(
                    table: 'receive_notes',
                    foreignKey: 'warehouse_id',
                    localKey: 'id',
                ),
            ],
        ),

        'classes' => new RecordTableType(
            pms_name: 'class',
            soft_deletes: false,
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'bills' => new RecordHasManyType(
                    table: 'bills',
                    foreignKey: 'class_id',
                    localKey: 'id',
                ),
                'invoices' => new RecordHasManyType(
                    table: 'invoices',
                    foreignKey: 'class_id',
                    localKey: 'id',
                ),
                'estimates' => new RecordHasManyType(
                    table: 'estimates',
                    foreignKey: 'class_id',
                    localKey: 'id',
                ),
                'deliveryNotes' => new RecordHasManyType(
                    table: 'delivery_notes',
                    foreignKey: 'class_id',
                    localKey: 'id',
                ),
                'purchaseOrders' => new RecordHasManyType(
                    table: 'purchase_orders',
                    foreignKey: 'class_id',
                    localKey: 'id',
                ),
                'billItems' => new RecordHasManyType(
                    table: 'bill_items',
                    foreignKey: 'class_id',
                    localKey: 'id',
                ),
                'invoiceItems' => new RecordHasManyType(
                    table: 'invoice_items',
                    foreignKey: 'class_id',
                    localKey: 'id',
                ),
                'estimateItems' => new RecordHasManyType(
                    table: 'estimate_items',
                    foreignKey: 'class_id',
                    localKey: 'id',
                ),
                'deliveryNoteItems' => new RecordHasManyType(
                    table: 'delivery_note_items',
                    foreignKey: 'class_id',
                    localKey: 'id',
                ),
                'purchaseOrderItems' => new RecordHasManyType(
                    table: 'purchase_order_items',
                    foreignKey: 'class_id',
                    localKey: 'id',
                ),
            ],
        ),

        'customer_attended' => new RecordTableType(
            pms_name: 'customerAttended',
            soft_deletes: false,
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'invoices' => new RecordHasManyType(
                    table: 'invoices',
                    foreignKey: 'customer_attended_id',
                    localKey: 'id',
                ),
                'estimates' => new RecordHasManyType(
                    table: 'estimates',
                    foreignKey: 'customer_attended_id',
                    localKey: 'id',
                ),
                'deliveryNotes' => new RecordHasManyType(
                    table: 'delivery_notes',
                    foreignKey: 'customer_attended_id',
                    localKey: 'id',
                ),
            ],
        ),

        'deposit_to_accounts' => new RecordTableType(
            pms_name: 'depositToAccount',
            soft_deletes: false,
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'receivePayments' => new RecordHasManyType(
                    table: 'receive_payments',
                    foreignKey: 'deposit_to_account_id',
                    localKey: 'id',
                ),
            ],
        ),

        'estimates' => new RecordTableType(
            pms_name: 'estimateSo',
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updated_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'items' => new RecordHasManyType(
                    table: 'estimate_items',
                    foreignKey: 'estimate_id',
                    localKey: 'id',
                ),
                'customer' => new RecordBelongsToType(
                    table: 'customers',
                    foreignKey: 'customer_id',
                    ownerKey: 'id',
                ),
                'bank' => new RecordBelongsToType(
                    table: 'banks',
                    foreignKey: 'bank_id',
                    ownerKey: 'id',
                ),
                'warehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'warehouse_id',
                    ownerKey: 'id',
                ),
                'customerAttended' => new RecordBelongsToType(
                    table: 'customer_attended',
                    foreignKey: 'customer_attended_id',
                    ownerKey: 'id',
                ),
                'classes' => new RecordBelongsToType(
                    table: 'classes',
                    foreignKey: 'class_id',
                    ownerKey: 'id',
                ),
                'term' => new RecordBelongsToType(
                    table: 'terms',
                    foreignKey: 'term_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        'estimate_items' => new RecordTableType(
            pms_name: 'estimateItem',
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'estimate' => new RecordBelongsToType(
                    table: 'estimates',
                    foreignKey: 'estimate_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        'delivery_notes' => new RecordTableType(
            pms_name: 'deliveryNote',
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updated_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
                'customer' => new RecordBelongsToType(
                    table: 'customers',
                    foreignKey: 'customer_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'items' => new RecordHasManyType(
                    table: 'delivery_note_items',
                    foreignKey: 'delivery_note_id',
                    localKey: 'id',
                ),
                'bank' => new RecordBelongsToType(
                    table: 'banks',
                    foreignKey: 'bank_id',
                    ownerKey: 'id',
                ),
                'warehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'warehouse_id',
                    ownerKey: 'id',
                ),
                'customerAttended' => new RecordBelongsToType(
                    table: 'customer_attended',
                    foreignKey: 'customer_attended_id',
                    ownerKey: 'id',
                ),
                'classes' => new RecordBelongsToType(
                    table: 'classes',
                    foreignKey: 'class_id',
                    ownerKey: 'id',
                ),
                'term' => new RecordBelongsToType(
                    table: 'terms',
                    foreignKey: 'term_id',
                    ownerKey: 'id',
                ),
                // Estimates via meta pivot with constraint owner = 'delivery_note'
                'relationship' => new RecordMetaBelongsToManyType(
                    related: 'estimates',
                    table: 'meta',
                    foreignPivotKey: 'owner_id',
                    relatedPivotKey: 'target_id',
                    wherePivot: [
                        ['column' => 'owner', 'operator' => '=', 'value' => 'delivery_note'],
                    ],
                    select: [
                        'estimates.id',
                        'estimates.ref_number',
                        'estimates.date',
                        'estimates.total_amount',
                    ],
                ),
            ],
        ),

        'invoices' => new RecordTableType(
            pms_name: 'invoice',
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'customer' => new RecordBelongsToType(
                    table: 'customers',
                    foreignKey: 'customer_id',
                    ownerKey: 'id',
                ),
                'items' => new RecordHasManyType(
                    table: 'invoice_items',
                    foreignKey: 'invoice_id',
                    localKey: 'id',
                ),
                'term' => new RecordBelongsToType(
                    table: 'terms',
                    foreignKey: 'term_id',
                    ownerKey: 'id',
                ),
                'bank' => new RecordBelongsToType(
                    table: 'banks',
                    foreignKey: 'bank_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'warehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'warehouse_id',
                    ownerKey: 'id',
                ),
                'customerAttended' => new RecordBelongsToType(
                    table: 'customer_attended',
                    foreignKey: 'customer_attended_id',
                    ownerKey: 'id',
                ),
                'classes' => new RecordBelongsToType(
                    table: 'classes',
                    foreignKey: 'class_id',
                    ownerKey: 'id',
                ),
                // Invoice -> ReceivePayment through ReceivePaymentItem
                'receivePayments' => new RecordHasManyThroughType(
                    table: 'receive_payments',
                    through: 'receive_payment_items',
                    firstKey: 'invoice_id',      // FK on through table
                    secondKey: 'id',             // FK on target table
                    localKey: 'id',              // PK on source (invoices)
                    secondLocalKey: 'receive_payment_id', // local key on through table
                    orderBy: ['date' => 'desc'],
                ),
                // All receive payment items belonging to this invoice
                'receivePaymentItems' => new RecordHasManyType(
                    table: 'receive_payment_items',
                    foreignKey: 'invoice_id',
                    localKey: 'id',
                    // hint for eager child include when requested
                    with: ['receivePayment'],
                ),
                // Delivery notes via meta pivot with constraint owner = 'invoice'
                'relationship' => new RecordMetaBelongsToManyType(
                    related: 'delivery_notes',
                    table: 'meta',
                    foreignPivotKey: 'owner_id',
                    relatedPivotKey: 'target_id',
                    wherePivot: [
                        ['column' => 'owner', 'operator' => '=', 'value' => 'invoice'],
                    ],
                    select: [
                        'delivery_notes.id',
                        'delivery_notes.ref_number',
                        'delivery_notes.date',
                        'delivery_notes.total_amount',
                    ],
                ),
            ],
        ),

        'invoice_items' => new RecordTableType(
            pms_name: 'invoiceItem',
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'invoice' => new RecordBelongsToType(
                    table: 'invoices',
                    foreignKey: 'invoice_id',
                    ownerKey: 'id',
                ),
                'product' => new RecordBelongsToType(
                    table: 'products',
                    foreignKey: 'product_id',
                    ownerKey: 'id',
                ),
                'class' => new RecordBelongsToType(
                    table: 'classes',
                    foreignKey: 'class_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        'receive_payments' => new RecordTableType(
            pms_name: 'receivePayment',
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'customer' => new RecordBelongsToType(
                    table: 'customers',
                    foreignKey: 'customer_id',
                    ownerKey: 'id',
                ),
                'deposit_to_account' => new RecordBelongsToType(
                    table: 'deposit_to_accounts',
                    foreignKey: 'deposit_to_account_id',
                    ownerKey: 'id',
                ),
                'items' => new RecordHasManyType(
                    table: 'receive_payment_items',
                    foreignKey: 'receive_payment_id',
                    localKey: 'id',
                ),
            ],
        ),

        'receive_payment_items' => new RecordTableType(
            pms_name: 'receivePaymentItem',
            public: new RecordTablePublic(
                read: false,
                write: false,
            ),
            relationships: [
                'receivePayment' => new RecordBelongsToType(
                    table: 'receive_payments',
                    foreignKey: 'receive_payment_id',
                    ownerKey: 'id',
                ),
                'invoice' => new RecordBelongsToType(
                    table: 'invoices',
                    foreignKey: 'invoice_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        // Purchase Order related tables
        'purchase_orders' => new RecordTableType(
            pms_name: 'purchaseOrder',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'vendor' => new RecordBelongsToType(
                    table: 'vendors',
                    foreignKey: 'vendor_id',
                    ownerKey: 'id',
                ),
                'term' => new RecordBelongsToType(
                    table: 'terms',
                    foreignKey: 'term_id',
                    ownerKey: 'id',
                ),
                'bank' => new RecordBelongsToType(
                    table: 'banks',
                    foreignKey: 'bank_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'warehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'warehouse_id',
                    ownerKey: 'id',
                ),
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updatedBy' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
                'items' => new RecordHasManyType(
                    table: 'purchase_order_items',
                    foreignKey: 'purchase_order_id',
                    localKey: 'id',
                ),
                'receiveNotes' => new RecordHasManyType(
                    table: 'receive_notes',
                    foreignKey: 'purchase_order_id',
                    localKey: 'id',
                ),
                'approvals' => new RecordHasManyType(
                    table: 'purchase_order_approvals',
                    foreignKey: 'purchase_order_id',
                    localKey: 'id',
                ),
            ],
        ),

        'purchase_order_items' => new RecordTableType(
            pms_name: 'purchaseOrderItem',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'purchaseOrder' => new RecordBelongsToType(
                    table: 'purchase_orders',
                    foreignKey: 'purchase_order_id',
                    ownerKey: 'id',
                ),
                'customer' => new RecordBelongsToType(
                    table: 'customers',
                    foreignKey: 'customer_id',
                    ownerKey: 'id',
                ),
                'project' => new RecordBelongsToType(
                    table: 'projects',
                    foreignKey: 'project_id',
                    ownerKey: 'id',
                ),
                'product' => new RecordBelongsToType(
                    table: 'products',
                    foreignKey: 'product_id',
                    ownerKey: 'id',
                ),
                'class' => new RecordBelongsToType(
                    table: 'classes',
                    foreignKey: 'class_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        // Bill related tables
        'bills' => new RecordTableType(
            pms_name: 'bill',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'vendor' => new RecordBelongsToType(
                    table: 'vendors',
                    foreignKey: 'vendor_id',
                    ownerKey: 'id',
                ),
                'receiveNote' => new RecordBelongsToType(
                    table: 'receive_notes',
                    foreignKey: 'receive_note_id',
                    ownerKey: 'id',
                ),
                'term' => new RecordBelongsToType(
                    table: 'terms',
                    foreignKey: 'qbo_term_id',
                    ownerKey: 'id',
                ),
                'bank' => new RecordBelongsToType(
                    table: 'banks',
                    foreignKey: 'bank_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updatedBy' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
                'items' => new RecordHasManyType(
                    table: 'bill_items',
                    foreignKey: 'bill_id',
                    localKey: 'id',
                ),
                'warehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'warehouse_id',
                    ownerKey: 'id',
                ),
                'classes' => new RecordBelongsToType(
                    table: 'classes',
                    foreignKey: 'class_id',
                    ownerKey: 'id',
                ),
                // Receive notes via meta pivot with constraint owner = 'bill'
                'relationship' => new RecordMetaBelongsToManyType(
                    related: \App\Models\ReceiveNote::class,
                    table: 'meta',
                    foreignPivotKey: 'owner_id',
                    relatedPivotKey: 'target_id',
                    wherePivot: [
                        ['column' => 'owner', 'operator' => '=', 'value' => 'bill'],
                    ],
                    select: [
                        'receive_notes.id',
                        'receive_notes.ref_number',
                        'receive_notes.date',
                        'receive_notes.total_amount',
                    ],
                ),
            ],
        ),

        'bill_items' => new RecordTableType(
            pms_name: 'billItem',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'bill' => new RecordBelongsToType(
                    table: 'bills',
                    foreignKey: 'bill_id',
                    ownerKey: 'id',
                ),
                'receiveNote' => new RecordBelongsToType(
                    table: 'receive_notes',
                    foreignKey: 'receive_note_id',
                    ownerKey: 'id',
                ),
                'receiveNoteItem' => new RecordBelongsToType(
                    table: 'receive_note_items',
                    foreignKey: 'receive_note_item_id',
                    ownerKey: 'id',
                ),
                'customer' => new RecordBelongsToType(
                    table: 'customers',
                    foreignKey: 'customer_id',
                    ownerKey: 'id',
                ),
                'project' => new RecordBelongsToType(
                    table: 'projects',
                    foreignKey: 'project_id',
                    ownerKey: 'id',
                ),
                'product' => new RecordBelongsToType(
                    table: 'products',
                    foreignKey: 'product_id',
                    ownerKey: 'id',
                ),
                'class' => new RecordBelongsToType(
                    table: 'classes',
                    foreignKey: 'class_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        // Receive Note related tables
        'receive_notes' => new RecordTableType(
            pms_name: 'receiveNote',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'purchaseOrder' => new RecordBelongsToType(
                    table: 'purchase_orders',
                    foreignKey: 'purchase_order_id',
                    ownerKey: 'id',
                ),
                'vendor' => new RecordBelongsToType(
                    table: 'vendors',
                    foreignKey: 'vendor_id',
                    ownerKey: 'id',
                ),
                'warehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'warehouse_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updatedBy' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
                'items' => new RecordHasManyType(
                    table: 'receive_note_items',
                    foreignKey: 'receive_note_id',
                    localKey: 'id',
                ),
                'bills' => new RecordHasManyType(
                    table: 'bills',
                    foreignKey: 'receive_note_id',
                    localKey: 'id',
                ),
            ],
        ),

        'receive_note_items' => new RecordTableType(
            pms_name: 'receiveNoteItem',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'receiveNote' => new RecordBelongsToType(
                    table: 'receive_notes',
                    foreignKey: 'receive_note_id',
                    ownerKey: 'id',
                ),
                'purchaseOrderItem' => new RecordBelongsToType(
                    table: 'purchase_order_items',
                    foreignKey: 'purchase_order_item_id',
                    ownerKey: 'id',
                ),
                'product' => new RecordBelongsToType(
                    table: 'products',
                    foreignKey: 'product_id',
                    ownerKey: 'id',
                ),
                'billItems' => new RecordHasManyType(
                    table: 'bill_items',
                    foreignKey: 'receive_note_item_id',
                    localKey: 'id',
                ),
            ],
        ),

        // Delivery Note related tables
        'delivery_notes' => new RecordTableType(
            pms_name: 'deliveryNote',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'customer' => new RecordBelongsToType(
                    table: 'customers',
                    foreignKey: 'customer_id',
                    ownerKey: 'id',
                ),
                'warehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'warehouse_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updatedBy' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
                'items' => new RecordHasManyType(
                    table: 'delivery_note_items',
                    foreignKey: 'delivery_note_id',
                    localKey: 'id',
                ),
            ],
        ),

        'delivery_note_items' => new RecordTableType(
            pms_name: 'deliveryNoteItem',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'deliveryNote' => new RecordBelongsToType(
                    table: 'delivery_notes',
                    foreignKey: 'delivery_note_id',
                    ownerKey: 'id',
                ),
                'product' => new RecordBelongsToType(
                    table: 'products',
                    foreignKey: 'product_id',
                    ownerKey: 'id',
                ),
                'class' => new RecordBelongsToType(
                    table: 'classes',
                    foreignKey: 'class_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        // Estimate related tables
        'estimates' => new RecordTableType(
            pms_name: 'estimateSo',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'customer' => new RecordBelongsToType(
                    table: 'customers',
                    foreignKey: 'customer_id',
                    ownerKey: 'id',
                ),
                'term' => new RecordBelongsToType(
                    table: 'terms',
                    foreignKey: 'term_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updatedBy' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
                'items' => new RecordHasManyType(
                    table: 'estimate_items',
                    foreignKey: 'estimate_id',
                    localKey: 'id',
                ),
            ],
        ),

        'estimate_items' => new RecordTableType(
            pms_name: 'estimateItem',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'estimate' => new RecordBelongsToType(
                    table: 'estimates',
                    foreignKey: 'estimate_id',
                    ownerKey: 'id',
                ),
                'product' => new RecordBelongsToType(
                    table: 'products',
                    foreignKey: 'product_id',
                    ownerKey: 'id',
                ),
                'class' => new RecordBelongsToType(
                    table: 'classes',
                    foreignKey: 'class_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        // Sale Receipt related tables
        'sale_receipts' => new RecordTableType(
            pms_name: 'saleReceipt',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'customer' => new RecordBelongsToType(
                    table: 'customers',
                    foreignKey: 'customer_id',
                    ownerKey: 'id',
                ),
                'depositToAccount' => new RecordBelongsToType(
                    table: 'banks',
                    foreignKey: 'deposit_to_account_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updatedBy' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
            ],
        ),

        // Transfer related tables
        'transfers' => new RecordTableType(
            pms_name: 'transfer',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'fromWarehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'from_warehouse_id',
                    ownerKey: 'id',
                ),
                'toWarehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'to_warehouse_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updatedBy' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
            ],
        ),

        // Inter Transfer related tables
        'inter_transfers' => new RecordTableType(
            pms_name: 'interTransfer',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'fromWarehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'from_warehouse_id',
                    ownerKey: 'id',
                ),
                'toWarehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'to_warehouse_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updatedBy' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
            ],
        ),

        // POS related tables
        'pos' => new RecordTableType(
            pms_name: 'pos',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'customer' => new RecordBelongsToType(
                    table: 'customers',
                    foreignKey: 'customer_id',
                    ownerKey: 'id',
                ),
                'warehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'warehouse_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updatedBy' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
            ],
        ),

        // Inventory related tables
        'inventory_summaries' => new RecordTableType(
            pms_name: 'inventorySummary',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'product' => new RecordBelongsToType(
                    table: 'products',
                    foreignKey: 'product_id',
                    ownerKey: 'id',
                ),
                'warehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'warehouse_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        'inventory_valuation' => new RecordTableType(
            pms_name: 'inventoryValuation',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'product' => new RecordBelongsToType(
                    table: 'products',
                    foreignKey: 'product_id',
                    ownerKey: 'id',
                ),
                'warehouse' => new RecordBelongsToType(
                    table: 'warehouses',
                    foreignKey: 'warehouse_id',
                    ownerKey: 'id',
                ),
                'location' => new RecordBelongsToType(
                    table: 'locations',
                    foreignKey: 'location_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        // Audit and tracking tables
        'audit_logs' => new RecordTableType(
            pms_name: 'auditLog',
            soft_deletes: false,
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'user' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'user_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        // Exchange rates
        'exchange_rates' => new RecordTableType(
            pms_name: 'exchangeRate',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'updatedBy' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'updated_by',
                    ownerKey: 'id',
                ),
            ],
        ),

        // Scenario and approval tables
        'scenarios' => new RecordTableType(
            pms_name: 'scenario',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'steps' => new RecordHasManyType(
                    table: 'scenario_steps',
                    foreignKey: 'scenario_id',
                    localKey: 'id',
                ),
                'approvals' => new RecordHasManyType(
                    table: 'scenario_approvals',
                    foreignKey: 'scenario_id',
                    localKey: 'id',
                ),
            ],
        ),

        'scenario_steps' => new RecordTableType(
            pms_name: 'scenarioStep',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'scenario' => new RecordBelongsToType(
                    table: 'scenarios',
                    foreignKey: 'scenario_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        'scenario_approvals' => new RecordTableType(
            pms_name: 'scenarioApproval',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'scenario' => new RecordBelongsToType(
                    table: 'scenarios',
                    foreignKey: 'scenario_id',
                    ownerKey: 'id',
                ),
                'user' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'user_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        'purchase_order_approvals' => new RecordTableType(
            pms_name: 'purchaseOrderApproval',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'purchaseOrder' => new RecordBelongsToType(
                    table: 'purchase_orders',
                    foreignKey: 'purchase_order_id',
                    ownerKey: 'id',
                ),
                'user' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'user_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        'qr_locations' => new RecordTableType(
            pms_name: 'qrLocation',
            public: new RecordTablePublic(
                read: false,
                write: true,
            )
        ),

        'departments' => new RecordTableType(
            pms_name: 'department',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'parent' => new RecordBelongsToType(
                    table: 'departments',
                    foreignKey: 'parent_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        'designations' => new RecordTableType(
            pms_name: 'designation',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'parent' => new RecordBelongsToType(
                    table: 'designations',
                    foreignKey: 'parent_id',
                    ownerKey: 'id',
                ),
            ],
        ),

        'employees' => new RecordTableType(
            pms_name: 'employee',
            table: 'users',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'employee_group' => new RecordBelongsToType(
                    table: 'employee_groups',
                    foreignKey: 'employee_group_id',
                    ownerKey: 'id',
                ),
                'designation' => new RecordBelongsToType(
                    table: 'designations',
                    foreignKey: 'designation_id',
                    ownerKey: 'id',
                ),
                'department' => new RecordBelongsToType(
                    table: 'departments',
                    foreignKey: 'department_id',
                    ownerKey: 'id',
                ),
                'roles' => new RecordSpatiePermissionType(
                    related: 'roles',
                    relation: \App\Models\User::class,
                    table: config('permission.table_names.model_has_roles'),
                    foreignPivotKey: config('permission.column_names.model_morph_key'),
                    relatedPivotKey: 'role_id',
                    parentKey: 'id',
                    relatedKey: 'id',
                    teamsEnabled: config('permission.teams', false),
                ),
            ],
            functions: [
                'list' => new RecordFunctionType(
                    pms_name: 'view_employee',
                    method: [HttpMethodEnum::GET->value],
                    class: UserController::class,
                    function_method: 'listUser',
                ),
                'create' => new RecordFunctionType(
                    pms_name: 'create_employee',
                    method: [HttpMethodEnum::POST->value],
                    class: UserController::class,
                    function_method: 'createUser',
                ),
                'update/{id}' => new RecordFunctionType(
                    pms_name: 'update_employee',
                    method: [HttpMethodEnum::PUT->value],
                    class: UserController::class,
                    function_method: 'updateUser',
                ),
                'delete/{id}' => new RecordFunctionType(
                    pms_name: 'delete_employee',
                    method: [HttpMethodEnum::DELETE->value],
                    class: UserController::class,
                    function_method: 'deleteUser',
                ),
            ],
        ),

        'employee_groups' => new RecordTableType(
            pms_name: 'employeeGroup',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
        ),

        'employee_shifts' => new RecordTableType(
            pms_name: 'employeeShift',
            public: new RecordTablePublic(
                read: true,
                write: true,
            ),
            relationships: [
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'last_updated_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'last_updated_by',
                    ownerKey: 'id',
                ),
                'assignments' => new RecordHasManyType(
                    table: 'employee_roster_assignments',
                    foreignKey: 'roster_period_id',
                    localKey: 'id',
                ),
            ],
        ),

        'currencies' => new RecordTableType(
            pms_name: 'currency',
            public: new RecordTablePublic(
                read: false,
                write: true,
            )
        ),

        'employee_rosters' => new RecordTableType(
            pms_name: 'employeeRoster',
            public: new RecordTablePublic(
                read: false,
                write: true,
            ),
            relationships: [
                'user' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'user_id',
                    ownerKey: 'id',
                ),
                'employee_shift' => new RecordBelongsToType(
                    table: 'employee_shifts',
                    foreignKey: 'employee_shift_id',
                    ownerKey: 'id',
                ),
                'created_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'created_by',
                    ownerKey: 'id',
                ),
                'last_updated_by' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'last_updated_by',
                    ownerKey: 'id',
                ),
            ],
            functions: [
                'rosters' => new RecordFunctionType(
                    pms_name: 'view_employeeRoster',
                    method: [HttpMethodEnum::GET->value],
                    class: EmployeeRosterController::class,
                    function_method: 'listEmployeeRosters',
                ),
                'upsert' => new RecordFunctionType(
                    pms_name: ['create_employeeRoster', 'update_employeeRoster'],
                    method: [HttpMethodEnum::POST->value],
                    class: EmployeeRosterController::class,
                    function_method: 'upsertEmployeeRosters',
                ),
                'close' => new RecordFunctionType(
                    pms_name: ['create_employeeRoster', 'update_employeeRoster'],
                    method: [HttpMethodEnum::POST->value],
                    class: EmployeeRosterController::class,
                    function_method: 'closeEmployeeRosters',
                ),
            ]
        ),

        'attendances' => new RecordTableType(
            pms_name: 'attendance',
            public: new RecordTablePublic(
                read: false,
                write: true,
            ),
            relationships: [
                'user' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'user_id',
                    ownerKey: 'id',
                ),
                'employee_roster' => new RecordBelongsToType(
                    table: 'employee_rosters',
                    foreignKey: 'employee_roster_id',
                    ownerKey: 'id',
                ),
                'employee_shift' => new RecordBelongsToType(
                    table: 'employee_shifts',
                    foreignKey: 'employee_shift_id',
                    ownerKey: 'id',
                ),
                'employee_group' => new RecordBelongsToType(
                    table: 'employee_groups',
                    foreignKey: 'employee_group_id',
                    ownerKey: 'id',
                ),
                'qr_location' => new RecordBelongsToType(
                    table: 'qr_locations',
                    foreignKey: 'qr_location_id',
                    ownerKey: 'id',
                ),
                'designation' => new RecordBelongsToType(
                    table: 'designations',
                    foreignKey: 'designation_id',
                    ownerKey: 'id',
                ),
                'department' => new RecordBelongsToType(
                    table: 'departments',
                    foreignKey: 'department_id',
                    ownerKey: 'id',
                ),
            ],
            functions: [
                'scan' => new RecordFunctionType(
                    pms_name: 'scan_attendance',
                    method: [HttpMethodEnum::POST->value],
                    class: AttendanceController::class,
                    function_method: 'qrCheckIn',
                ),
                'manual_check_in' => new RecordFunctionType(
                    pms_name: 'create_attendance',
                    method: [HttpMethodEnum::POST->value],
                    class: AttendanceController::class,
                    function_method: 'manualCheckIn',
                ),
                'manual_check_out' => new RecordFunctionType(
                    pms_name: 'update_attendance',
                    method: [HttpMethodEnum::POST->value],
                    class: AttendanceController::class,
                    function_method: 'manualCheckOut',
                ),
            ]
        ),
    ],
];
