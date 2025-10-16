# API v2 Records

This document describes the ERP v2 Records API exposed under `/api/v2/record`. It covers
available endpoints, authentication/authorization, filtering, selection/embedding, sorting,
pagination, and response formats.

## Base Path
- All endpoints are served under: `/api/v2/record`
- Only tables whitelisted in `config/record.php` (`tables` keys) are accessible

## Configuration

The API is configured via `config/record.php`, which defines:
- Table settings (permissions, soft deletes, etc.)
- Relationship definitions using typed classes
- Global and table-specific functions
- Performance and security settings

### Table Configuration Structure

Each table in the `tables` array follows this structure:

```php
'table_name' => [
    'pms_name' => 'Display Name',        // Human-readable name for permissions
    'soft_deletes' => true|false,         // Enable soft delete support
    'public' => true|false,               // Allow public access (no auth required)
    'viewOnlyCreateBy' => true|false,     // Restrict to records created by user
    'relationships' => [                  // Relationship definitions
        'alias' => new RecordRelationshipType(...),
    ],
    'functions' => [                      // Table-specific functions
        'function_name' => 'ControllerClass@method',
    ],
],
```

### Relationship Type Classes

Relationships are defined using typed classes for better IDE support and validation:

#### RecordBelongsToType
```php
'customer' => new RecordBelongsToType(
    table: 'customers',
    foreignKey: 'customer_id',
    ownerKey: 'id'
),
```

#### RecordHasManyType
```php
'items' => new RecordHasManyType(
    table: 'invoice_items',
    foreignKey: 'invoice_id',
    localKey: 'id'
),
```

#### RecordHasManyThroughType
```php
'receivePayments' => new RecordHasManyThroughType(
    table: 'receive_payments',
    through: 'receive_payment_items',
    firstKey: 'invoice_id',
    secondKey: 'receive_payment_id',
    localKey: 'id',
    secondLocalKey: 'id'
),
```

#### RecordSpatiePermissionType (MorphToMany)
```php
'roles' => new RecordSpatiePermissionType(
    related: 'App\Models\Role',
    relation: 'roles',
    table: 'model_has_roles',
    foreignPivotKey: 'model_id',
    relatedPivotKey: 'role_id',
    parentKey: 'id',
    relatedKey: 'id'
),
```

#### RecordMetaBelongsToManyType
```php
'relationship' => new RecordMetaBelongsToManyType(
    related: 'App\Models\ReceiveNote',
    table: 'meta',
    foreignPivotKey: 'owner_id',
    relatedPivotKey: 'related_id',
    wherePivot: ['owner' => 'bill'],
    select: ['related_id', 'owner_id', 'owner', 'created_at']
),
```

### Configuration Options
- `per_page_max`: Maximum items per page (default: 100)
- `limit_max`: Maximum items for limit parameter (default: 1000)
- `bulk_max`: Maximum items per bulk operation (default: 100)
- `max_depth`: Maximum relationship nesting depth (default: 10)
- `cache_ttl`: Cache TTL for foreign key metadata in seconds (default: 3600)
- `lazy_cache_ttl`: Cache TTL for lazy loading operations in seconds (default: 1800)
- `lazy_enabled`: Global toggle for lazy loading functionality (default: true)

## Routes

### Standard CRUD Operations
- GET    `/api/v2/record/{table}` — List with filters, selection, embedding, sorting, pagination
- GET    `/api/v2/record/{table}/{id}` — Retrieve one by primary key
- POST   `/api/v2/record/{table}` — Create one
- PUT    `/api/v2/record/{table}/{id}` — Update one
- PATCH  `/api/v2/record/{table}/{id}` — Update one (partial)
- DELETE `/api/v2/record/{table}/{id}` — Delete (soft if supported, else hard)
- POST   `/api/v2/record/{table}/{id}/restore` — Restore soft-deleted
- DELETE `/api/v2/record/{table}/{id}/force` — Force delete (hard delete)

### Bulk Operations
- POST   `/api/v2/record/{table}/bulk` — Legacy bulk operations with auto-detection
- POST   `/api/v2/record/{table}/bulk/create` — Bulk create operations
- POST   `/api/v2/record/{table}/bulk/update` — Bulk update operations  
- POST   `/api/v2/record/{table}/bulk/delete` — Bulk delete operations

### Global Function Endpoints
- GET|POST|PUT|PATCH|DELETE `/api/v2/record/rpc/{functionName}` — Execute global custom functions

Global functions are configured in `config/record.php` under the `global_functions` array. These functions provide system-wide functionality that isn't tied to a specific table.

#### Supported Function Patterns
- **Simple functions**: `function_name` (e.g., `roles`, `report_estimate`)
- **Parameterized functions**: `function_name/{id}` (e.g., `report_invoice/123`, `roles/5`)

#### Examples
```bash
# Execute a simple global function
GET /api/v2/record/rpc/roles

# Execute a parameterized global function
GET /api/v2/record/rpc/report_invoice/123
POST /api/v2/record/rpc/report_estimate
```

### Table Function Endpoints
- GET|POST|PUT|PATCH|DELETE `/api/v2/record/{table}/{functionName}` — Execute table-specific custom functions

Table functions are configured in `config/record.php` under each table's `functions` array. These functions provide table-specific functionality and operations.

#### Supported Function Patterns
- **Simple functions**: `function_name` (e.g., `calculate_stats`, `export_data`)
- **Parameterized functions**: `function_name/{id}` (e.g., `user_profile/123`, `generate_report/456`)

#### Examples
```bash
# Execute a simple table function
GET /api/v2/record/users/calculate_stats
POST /api/v2/record/invoices/export_data

# Execute a parameterized table function
GET /api/v2/record/users/user_profile/123
POST /api/v2/record/products/generate_report/456
```

#### Function Configuration
Both global and table functions support multiple configuration types:

**Class-based functions:**
```php
'calculate_total' => new RecordFunctionType(
    type: 'class',
    class: 'App\\Services\\CalculationService',
    function_method: 'calculateTotal',
    method: ['POST'],
    description: 'Calculate total for given items'
),
```

**Query-based functions:**
```php
'get_active_users' => new RecordFunctionType(
    type: 'query',
    query: 'SELECT * FROM users WHERE active = 1 AND created_at >= ::since',
    method: ['GET'],
    required_params: ['since'],
    description: 'Get active users since a specific date'
),
```

**Closure-based functions:**
```php
'simple_operation' => new RecordFunctionType(
    type: 'closure',
    closure: function($request, $id = null) {
        return response()->json(['result' => 'success']);
    },
    method: ['GET', 'POST'],
    description: 'Simple operation example'
),
```

## AuthN/AuthZ
- Public access may be allowed per table/action via `config/record.php` `tables.{table}.public`
- Otherwise JWT via `auth:api` is required
- Permissions map to: `view_{resource}`, `create_{resource}`, `update_{resource}`, `delete_{resource}`
- Resource name uses `pms_name` from config when available, otherwise falls back to singular table name
- Special permissions like `viewOnlyCreateBy_{resource}`, `updateStatus_{resource}` are supported
- `restore` uses `update_{resource}` permission
- `force_delete` uses `delete_{resource}` permission

### Function Endpoint Authorization

#### Global Functions
- Global functions use the `pms_name` configured in their `RecordFunctionType` configuration
- If no `pms_name` is specified, the function name itself is used as the permission resource
- Permission required: `read_{pms_name}` (since functions are primarily read operations)
- Example: Function with `pms_name: 'report'` requires `read_report` permission

#### Table Functions
- Table functions inherit permissions from their parent table's `pms_name` configuration
- Permission required: `read_{table_pms_name}` where `table_pms_name` is from `config('record.tables.{table}.pms_name')`
- If the table has no `pms_name`, the singular table name is used
- Example: Function on `invoices` table with `pms_name: 'invoice'` requires `read_invoice` permission

#### Function Permission Examples
```php
// Global function configuration
'report_invoice' => new RecordFunctionType(
    pms_name: 'invoice_report',  // Requires 'read_invoice_report' permission
    type: 'class',
    // ... other config
),

// Table function inherits from table's pms_name
'tables' => [
    'users' => [
        'pms_name' => 'user',  // Table functions require 'read_user' permission
        'functions' => [
            'get_profile' => new RecordFunctionType(
                type: 'class',
                // ... config (inherits 'user' pms_name)
            ),
        ],
    ],
],
```

### Permission-Based Data Filtering
The API automatically applies permission-based filtering for enhanced data security:

#### viewOnlyCreateBy Permission
- When a user has the `viewOnlyCreateBy_{resource}` permission, they can only view records they created
- This filter is automatically applied to all listing and detail queries
- Uses the `created_by` column to match against the authenticated user's ID
- Only applies to tables that have a `pms_name` configured in `config/record.php`
- Example: User with `viewOnlyCreateBy_customer` permission can only see customers they created

#### Permission Name Resolution
- Permission names are constructed using the `pms_name` from `config('record.tables.{table}.pms_name')`
- If no `pms_name` is configured for a table, no permission-based filtering is applied
- This ensures consistent permission naming across the application

#### Examples
```php
// config/record.php
'tables' => [
    'customers' => [
        'pms_name' => 'customer',
        // other config...
    ],
    'invoices' => [
        'pms_name' => 'invoice', 
        // other config...
    ]
]

// Resulting permissions:
// - viewOnlyCreateBy_customer (for customers table)
// - viewOnlyCreateBy_invoice (for invoices table)
```

## Tenancy and Soft Deletes
- If a table has `tenant_id`, all queries are scoped to the request tenant
- If a table is soft-deleted (`deleted_at`), list/get exclude soft-deleted rows
- `restore` clears `deleted_at`; `force` permanently deletes

## Responses
- Success (paginated)
```json
{
  "success": true,
  "data": <payload>,
  "meta": { "request_id": "...", "page": 1, "per_page": 25, "total": 100 }
}
```
- Success (with limit)
```json
{
  "success": true,
  "data": <payload>,
  "meta": { "request_id": "...", "total": 50 }
}
```
- Error
```json
{
  "success": false,
  "message": "Error detail",
  "errors": {},
  "meta": { "request_id": "..." }
}
```

## Listing: filters, selection, sorting, pagination
Endpoint: `GET /api/v2/record/{table}`

### Search
- `s`: full-text-like keyword search across all columns (simple `LIKE` on each column)

### Select (main table)
- `select`: comma-separated list of columns; `*` expands to `{table}.*`
- Example: `select=id,ref_number,created_at`

### Relationship embedding (PostgREST-style)
- Embedding is always enabled and guarded by `config('record.max_depth')`
- Only related tables defined in `config('record.tables')` are fetched
- Syntax inside `select`:
  - `alias:table(col1,col2,...)`
  - `table(col1,col2,...)`
  - `alias(*)` or `table(*)` to select all columns
- Examples:
  - `select=id,ref_number,customer:customers(id,name),items:invoice_items(*)`
  - `select=id,name,roles:roles(id,name),department:departments(*)`

#### Supported Relationship Types

**1. BelongsTo Relationships** (`RecordBelongsToType`)
- Standard foreign key relationships
- Configuration: `table`, `foreignKey`, `ownerKey`
- Example: `customer:customers(id,name)` for `invoices.customer_id` → `customers.id`

**2. HasMany Relationships** (`RecordHasManyType`)
- One-to-many relationships
- Configuration: `table`, `foreignKey`, `localKey`
- Example: `items:invoice_items(*)` for `invoices.id` → `invoice_items.invoice_id`

**3. HasManyThrough Relationships** (`RecordHasManyThroughType`)
- Relationships through intermediate tables
- Configuration: `table`, `through`, `firstKey`, `secondKey`, `localKey`, `secondLocalKey`
- Example: `receivePayments:receive_payments(*)` through `receive_payment_items`

**4. MorphToMany Relationships** (`RecordSpatiePermissionType`)
- Polymorphic many-to-many relationships (primarily for Spatie permissions)
- Configuration: `related`, `relation`, `table`, `foreignPivotKey`, `relatedPivotKey`, `parentKey`, `relatedKey`
- Example: `roles:roles(*)` for user roles via `model_has_roles` pivot table
- Special handling for polymorphic relationships with `model_type` constraints

**5. BelongsToMany with Constraints** (`RecordMetaBelongsToManyType`)
- Many-to-many relationships with pivot table constraints
- Configuration: `related`, `table`, `foreignPivotKey`, `relatedPivotKey`, `wherePivot`, `select`
- Example: `relationship:receive_notes(*)` via `meta` table with `owner='bill'` constraint
- Supports complex pivot table filtering and custom column selection

#### Relationship Resolution
- **Static Configuration**: All relationships must be explicitly defined in `config/record.php`
- **No Auto-Detection**: No automatic foreign key inference for performance and reliability
- **Alias Matching**: Alias-only forms use configured relationship names
- **Depth Limiting**: Nested relationships are limited by `max_depth` configuration

#### Current Limitations
- **One-Level Embedding**: Only supports direct relationships (no nested relationship embedding)
- **No Child Filtering**: Cannot filter/sort/limit within relationship parentheses
- **MorphToMany Filtering**: Relationship filtering not supported for morphToMany relationships
- **BelongsToMany Filtering**: Relationship filtering not supported for belongsToMany relationships
- **Performance**: Complex relationships may impact query performance on large datasets

### Sorting
- `sortby`: column name; auto-prefixed with `{table}.` if missing
- `order`: `asc` | `desc` (default `desc`)
- Default sort is table primary key (or `id`)

### Pagination
- `page`: 1-based page index (default 1)
- `per_page`: page size; capped by `config('record.per_page_max', 100)`
- `limit`: alternative to pagination for non-paginated requests; capped by `config('record.limit_max', 1000)`
- When `limit` is used, `page` and `per_page` are ignored
- Headers for paginated requests: `Link` (RFC 5988), `X-Page`, `X-Per-Page`, `X-Total-Pages`, `X-Total-Count`
- Headers for limit requests: `X-Total-Count` only
- Body meta for paginated requests: `page`, `per_page`, `total`
- Body meta for limit requests: `total` only

## Filter Operators
Use as query parameters on any column key. Multiple columns can be ORed by comma-separating keys
(e.g., `name,code=like.shoe`).

### Basic Operators
- `is.null` — where column IS NULL
  - `deleted_at=is.null`
- `eq.{value}` — equals; comma-separated value becomes `IN`
  - `status=eq.PAID` or `status=eq.DRAFT,PAID`
- `neq.{value}` — not equals; comma-separated value becomes `NOT IN`
  - `status=neq.CANCELLED` or `status=neq.DRAFT,CANCELLED`
- `is_not.null` — where column IS NOT NULL
  - `email=is_not.null`

### Text Operators
- `like.{value}` / `contains.{value}` — `%value%`
  - `ref_number=like.INV-2025`
- `starts_with.{value}` — `value%`
  - `ref_number=starts_with.INV`
- `ends_with.{value}` — `%value`
  - `ref_number=ends_with.2025`
- `not_like.{value}` — NOT LIKE `%value%`
  - `description=not_like.draft`
- `regex.{pattern}` — MySQL REGEXP pattern matching
  - `phone=regex.^\+1[0-9]{10}$`

### Comparison Operators
- `gt.{v}` / `lt.{v}` / `gte.{v}` / `lte.{v}` — comparisons
  - `total=gt.1000`
- `between.a,b` / `not_between.a,b`
  - `created_at=between.2024-01-01,2024-12-31`

### List Operators
- `in.v1,v2,...` — value in list
  - `customer_id=in.1,2,3`
- `not_in.v1,v2,...` — value not in list
  - `status=not_in.DRAFT,CANCELLED`

### Date Operators
- `date_eq.{date}` — date equals (ignores time)
  - `created_at=date_eq.2024-01-01`
- `date_gt.{date}` / `date_lt.{date}` — date comparisons (ignores time)
  - `created_at=date_gt.2024-01-01`
- `date_gte.{date}` / `date_lte.{date}` — date comparisons (ignores time)
  - `created_at=date_gte.2024-01-01`

### Empty/Null Operators
- `empty` — column is null or empty string
  - `notes=empty`
- `not_empty` — column is not null and not empty string
  - `description=not_empty`

### Column-to-Column Comparisons
- `left=compare.{op}.{right}` where `op` in `eq|neq|gt|lt|gte|lte`
  - Example: `subtotal=compare.lte.total`

## Lazy Loading (Performance Optimization)

The API supports lazy loading for improved performance when dealing with complex queries or large datasets. Lazy loading defers the execution of filter operations until explicitly triggered, allowing for query optimization and caching.

### Enabling Lazy Loading
- Add `lazy=true` to any query parameter to enable lazy loading for that request
- When lazy loading is enabled, filter operations are cached and executed in an optimized manner
- Particularly beneficial for:
  - Complex queries with multiple filters
  - Large datasets with expensive operations
  - Repeated similar queries that can benefit from caching

### Lazy Loading Benefits
- **Deferred Execution**: Operations are queued and executed when needed
- **Query Optimization**: Multiple operations can be combined and optimized
- **Caching**: Results are cached to avoid redundant database queries
- **Performance Monitoring**: Built-in statistics for debugging and optimization
- **Operation Prioritization**: Filters are executed in optimized order (equality → range → text → complex)
- **Batch Processing**: Similar operations are grouped together for better database performance

### Performance Optimizations
The query builder implements several performance optimizations:

#### Operation Categorization
- **Equality Operations** (fastest): `eq`, `neq`, `is`, `is_not`, `in`, `not_in`
- **Range Operations** (indexed): `gt`, `lt`, `gte`, `lte`, `between`, `not_between`, `date_*`
- **Text Operations** (slower): `like`, `contains`, `starts_with`, `ends_with`, `not_like`
- **Complex Operations** (most expensive): `regex`, `empty`, `not_empty`

#### Execution Strategy
- Operations are automatically sorted by performance priority
- Fast operations (equality checks) are executed first
- Complex operations (regex, text searches) are executed last
- Similar operations are batched together for optimal database performance

#### Caching and Deduplication
- Query results are cached to avoid redundant database calls
- Duplicate filter operations are automatically deduplicated
- Cache invalidation is handled automatically when data changes

### Usage Examples

#### Basic Lazy Loading
```
GET /api/v2/record/invoices?status=eq.PAID&lazy=true
```

#### Complex Query with Lazy Loading
```
GET /api/v2/record/invoices?
  select=id,ref_number,customer:customers(id,name)&
  status=eq.PAID,PENDING&
  created_at=between.2024-01-01,2024-12-31&
  total=gt.1000&
  lazy=true&
  sortby=created_at&order=desc&page=1&per_page=25
```

#### Lazy Loading with Relationship Embedding
```
GET /api/v2/record/products?
  select=id,name,category:categories(*),supplier:vendors(id,name)&
  status=eq.ACTIVE&
  price=between.100,1000&
  lazy=true&
  limit=50
```

### Performance Considerations
- Lazy loading is most effective for:
  - Queries with 3+ filter conditions
  - Repeated queries with similar patterns
  - Large result sets that benefit from caching
- Standard execution may be faster for:
  - Simple single-filter queries
  - One-time queries
  - Small datasets

### Monitoring and Debugging
When lazy loading is enabled, additional performance metadata may be included in responses for debugging purposes (in development environments).

## Controller-Level Optimizations

The RecordController implements several advanced optimizations for enhanced performance:

### Caching Strategy
- **Request Fingerprinting**: Cache keys are generated based on request parameters, tenant context, and relationship data
- **Dynamic TTL**: Cache expiration times are calculated based on data volatility and query complexity
- **Intelligent Cache Invalidation**: Automatic cache clearing when related data changes
- **Schema Caching**: Database schema information is cached to reduce repeated lookups

### Pagination Enhancements
- **Cursor-Based Pagination**: Available for large datasets to improve performance over traditional offset pagination
- **Automatic Detection**: System automatically chooses optimal pagination method based on dataset size
- **Optimized Headers**: Efficient Link headers for navigation without redundant data

### Tenant Optimization
- **Cached Tenant Configuration**: Tenant settings are cached to avoid repeated config calls
- **Optimized Filtering**: Tenant-aware queries are applied efficiently at the database level
- **Isolation Guarantees**: Complete data isolation between tenants when enabled

### Relationship Loading
- **N+1 Query Prevention**: Intelligent eager loading to prevent performance bottlenecks
- **Selective Loading**: Only requested relationship data is fetched
- **Tenant-Aware Relationships**: Relationship queries respect tenant boundaries

### Bulk Operations
- **Transaction Safety**: All bulk operations are wrapped in database transactions
- **Operation Detection**: Automatic detection of create/update/delete operations based on data structure
- **Batch Size Limits**: Configurable limits to prevent memory exhaustion
- **Audit Logging**: Complete audit trail for all bulk operations

## Create
- `POST /api/v2/record/{table}`
- Body: JSON with known columns only; system columns are stripped
- `tenant_id` auto-filled if present; returns `{ "id": <newId> }`
- Validation: missing required non-nullable fields (without defaults) yield 422
- Requires `create_{resource}` permission

## Update
- `PUT|PATCH /api/v2/record/{table}/{id}`
- Body: JSON with known columns only; soft-deleted records cannot be updated
- Returns `{ "updated": <count> }` (404 if not found/no changes)
- Requires `update_{resource}` permission

## Delete
- `DELETE /api/v2/record/{table}/{id}`
- Soft delete if supported; otherwise hard delete
- Returns `{ "deleted": <count> }`
- Requires `delete_{resource}` permission

## Restore
- `POST /api/v2/record/{table}/{id}/restore`
- Only for soft-deleted tables; returns `{ "restored": <count> }`
- Requires `update_{resource}` permission

## Force Delete
- `DELETE /api/v2/record/{table}/{id}/force`
- Permanently deletes; returns `{ "deleted": <count> }`
- Requires `delete_{resource}` permission

## Bulk Operations

The API provides both legacy and new dedicated bulk endpoints for different operation types:

### Legacy Bulk Endpoint
- `POST /api/v2/record/{table}/bulk`
- **Automatic Operation Detection**: Operations are automatically determined based on data structure (presence of `id` field)
- **Direct Array Input**: Supports both legacy format with `items` wrapper and direct JSON array input
- **Enhanced Response Format**: Returns consolidated data with metadata

### New Dedicated Bulk Endpoints
- `POST /api/v2/record/{table}/bulk/create` — Dedicated bulk create operations
- `POST /api/v2/record/{table}/bulk/update` — Dedicated bulk update operations  
- `POST /api/v2/record/{table}/bulk/delete` — Dedicated bulk delete operations

### Request Formats

#### Legacy Format (still supported for `/bulk` endpoint)
```json
{
  "action": "insert|update|upsert|delete",
  "items": [ { /* row */ }, ... ]
}
```

#### Direct Array Format (recommended for all endpoints)
```json
[ { /* row */ }, ... ]
```

#### Single Object Format
```json
{ /* single row */ }
```

### New Bulk Endpoints Validation

#### Bulk Create (`/bulk/create`)
- **Validation**: Payload must be an array of objects
- **ID Field**: Primary key (`id`) field is automatically excluded from create operations
- **Required Permission**: `create_{resource}`

#### Bulk Update (`/bulk/update`)
- **Validation**: Payload must be an array of objects, each containing an `id` field
- **ID Field**: Required for identifying records to update
- **Required Permission**: `update_{resource}`

#### Bulk Delete (`/bulk/delete`)
- **Validation**: Payload must be an array of objects, each containing an `id` field
- **ID Field**: Required for identifying records to delete
- **Required Permission**: `delete_{resource}`

### Operation Detection Rules (Legacy `/bulk` endpoint only)
- **Create**: Records without `id` field or with `id: null`
- **Update**: Records with existing `id` field (non-null)
- **Delete**: Records with `id` field and `_delete: true` flag

### Limits and Validation
- Array length ≤ `config('record.bulk_max', 100)` (default: 100)
- All records must be valid JSON objects
- System columns are automatically filtered
- Tenant scoping applied automatically
- Primary key validation enforced for update/delete operations

### Response Format
```json
{
  "success": true,
  "data": [ /* consolidated created/updated records */ ],
  "meta": {
    "request_id": "...",
    "affected": 15
  }
}
```

### Response Details
- `data`: Contains all created, updated, and upserted records (deleted records excluded)
- `meta.affected`: Total number of records processed (including deletes)
- `meta.request_id`: Unique identifier for request tracking
- Deleted records are not included in the `data` array but count toward `affected`

### Authorization
- **Legacy endpoint**: Checks for `create_{resource}`, `update_{resource}`, and `delete_{resource}` permissions based on detected operations
- **New endpoints**: Require specific permissions for each operation type
- Operations are authorized based on actual data operations performed
- Requires appropriate permissions for the detected operations

### Error Handling
- **Validation Errors**: Returns 422 status with detailed validation messages
- **Permission Errors**: Returns 403 status for insufficient permissions
- **Database Errors**: Returns 500 status with error details
- **Transaction Safety**: All operations are wrapped in database transactions

## Advanced Usage Examples

### Complex Filtering with New Operators
```bash
# Find customers with names starting with "John" and non-empty email addresses
GET /api/v2/record/customers?name=starts_with.John&email=not_empty

# Find invoices created this year with amounts between $1000-$5000
GET /api/v2/record/invoices?
  created_at=date_gte.2024-01-01&
  created_at=date_lte.2024-12-31&
  total=between.1000,5000

# Find products with descriptions not containing "discontinued" and prices not in specific ranges
GET /api/v2/record/products?
  description=not_like.discontinued&
  price=not_in.0,99.99,199.99

# Find phone numbers matching a specific pattern
GET /api/v2/record/contacts?
  phone=regex.^\+1[0-9]{10}$
```

### MorphToMany Relationships (Spatie Permissions)

The API supports polymorphic many-to-many relationships, primarily used for Spatie permission system integration.

#### Configuration Example (RecordSpatiePermissionType)
```php
'users' => [
    'pms_name' => 'User',
    'soft_deletes' => true,
    'public' => false,
    'relationships' => [
        'roles' => new RecordSpatiePermissionType(
            related: 'App\Models\Role',
            relation: 'roles',
            table: 'model_has_roles',
            foreignPivotKey: 'model_id',
            relatedPivotKey: 'role_id',
            parentKey: 'id',
            relatedKey: 'id'
        ),
    ],
],
```

#### Usage Examples
```bash
# Get users with their roles
GET /api/v2/record/users?select=id,name,email,roles(*)

# Get users with specific role columns
GET /api/v2/record/users?select=id,name,roles(id,name,guard_name)

# Filter users (note: role filtering not supported in relationships)
GET /api/v2/record/users?name=like.admin&select=id,name,roles(*)
```

#### Response Format
```json
{
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "roles": [
        {
          "id": 1,
          "name": "admin",
          "guard_name": "web"
        },
        {
          "id": 2,
          "name": "manager",
          "guard_name": "web"
        }
      ]
    }
  ]
}
```

#### Key Features
- **Polymorphic Support**: Handles `model_type` constraints automatically
- **Pivot Table Management**: Manages complex pivot table relationships
- **Permission Integration**: Seamlessly integrates with Spatie permission system
- **Type Safety**: Ensures proper model type constraints in polymorphic relationships

#### Limitations
- **No Relationship Filtering**: Cannot filter by role properties within the relationship
- **No Nested Sorting**: Cannot sort by role properties
- **Performance Impact**: Complex queries may affect performance on large datasets

### BelongsToMany with Pivot Constraints (Meta Relationships)

The API supports many-to-many relationships with complex pivot table constraints, commonly used for meta-data relationships.

#### Configuration Example (RecordMetaBelongsToManyType)
```php
'bills' => [
    'pms_name' => 'Bill',
    'soft_deletes' => true,
    'public' => false,
    'relationships' => [
        'relationship' => new RecordMetaBelongsToManyType(
            related: 'App\Models\ReceiveNote',
            table: 'meta',
            foreignPivotKey: 'owner_id',
            relatedPivotKey: 'related_id',
            wherePivot: ['owner' => 'bill'],
            select: ['related_id', 'owner_id', 'owner', 'created_at']
        ),
    ],
],
```

#### Usage Examples
```bash
# Get bills with related receive notes
GET /api/v2/record/bills?select=id,ref_number,total,relationship(*)

# Get bills with specific receive note columns
GET /api/v2/record/bills?select=id,ref_number,relationship(id,ref_number,total)

# Filter bills (note: relationship filtering not supported)
GET /api/v2/record/bills?status=eq.pending&select=id,ref_number,relationship(*)
```

#### Response Format
```json
{
  "data": [
    {
      "id": 1,
      "ref_number": "BILL-001",
      "total": 1500.00,
      "relationship": [
        {
          "id": 5,
          "ref_number": "RN-001",
          "total": 1500.00,
          "status": "completed",
          "pivot": {
            "related_id": 5,
            "owner_id": 1,
            "owner": "bill",
            "created_at": "2024-01-15T10:30:00Z"
          }
        }
      ]
    }
  ]
}
```

#### Key Features
- **Pivot Constraints**: Supports complex `wherePivot` conditions for filtering pivot records
- **Custom Pivot Selection**: Allows selecting specific pivot table columns via `select` parameter
- **Meta Data Support**: Ideal for meta-data relationships with additional context
- **Flexible Constraints**: Supports multiple pivot constraints for complex business logic

#### Configuration Parameters
- **`related`**: Target model class for the relationship
- **`table`**: Pivot table name (e.g., 'meta')
- **`foreignPivotKey`**: Foreign key in pivot table pointing to parent model
- **`relatedPivotKey`**: Foreign key in pivot table pointing to related model
- **`wherePivot`**: Array of pivot table constraints (e.g., `['owner' => 'bill']`)
- **`select`**: Array of pivot columns to include in response

#### Limitations
- **No Relationship Filtering**: Cannot filter by related model properties within the relationship
- **No Nested Operations**: Cannot perform nested sorting or limiting
- **Pivot Constraints Only**: Filtering limited to predefined pivot constraints
- **Performance Considerations**: Complex pivot queries may impact performance

## Relationship Filtering Limitations

The API has specific limitations when working with complex relationship types. Understanding these constraints is crucial for proper implementation.

### Supported Filtering

#### Basic Relationships (BelongsTo, HasMany, HasManyThrough)
```bash
# ✅ SUPPORTED: Filter parent table, embed relationships
GET /api/v2/record/invoices?status=eq.pending&select=id,customer(*),items(*)

# ✅ SUPPORTED: Filter parent with relationship embedding
GET /api/v2/record/users?department_id=eq.5&select=id,name,department(*)
```

### Unsupported Filtering

#### MorphToMany Relationships (RecordSpatiePermissionType)
```bash
# ❌ NOT SUPPORTED: Cannot filter by role properties
GET /api/v2/record/users?roles.name=eq.admin&select=id,name,roles(*)

# ❌ NOT SUPPORTED: Cannot filter by pivot table properties
GET /api/v2/record/users?roles.pivot.created_at=gte.2024-01-01&select=id,roles(*)

# ✅ WORKAROUND: Filter users first, then check roles in application logic
GET /api/v2/record/users?select=id,name,roles(id,name,guard_name)
```

#### BelongsToMany with Constraints (RecordMetaBelongsToManyType)
```bash
# ❌ NOT SUPPORTED: Cannot filter by related model properties
GET /api/v2/record/bills?relationship.status=eq.completed&select=id,relationship(*)

# ❌ NOT SUPPORTED: Cannot filter by custom pivot properties
GET /api/v2/record/bills?relationship.pivot.owner=eq.bill&select=id,relationship(*)

# ✅ WORKAROUND: Use predefined pivot constraints in configuration
# The wherePivot constraints are applied automatically
GET /api/v2/record/bills?select=id,ref_number,relationship(*)
```

### Technical Reasons for Limitations

1. **Query Complexity**: Filtering across polymorphic relationships creates complex SQL queries that can impact performance
2. **Type Safety**: Polymorphic relationships involve multiple model types, making type-safe filtering challenging
3. **Pivot Table Constraints**: Dynamic pivot filtering requires runtime query modification that conflicts with static configuration
4. **Performance Optimization**: The API prioritizes query performance over complex filtering capabilities

### Recommended Workarounds

#### Client-Side Filtering
```bash
# Fetch all data and filter in application
GET /api/v2/record/users?select=id,name,roles(*)
# Then filter users with specific roles in your application logic
```

#### Multiple API Calls
```bash
# First, get role IDs
GET /api/v2/record/roles?name=eq.admin&select=id

# Then, use global functions or custom endpoints for complex filtering
GET /api/v2/record/rpc/users_with_role/1
```

#### Custom Functions
```php
// Define custom functions in config/record.php for complex filtering
'global_functions' => [
    'users_with_admin_role' => 'App\Http\Controllers\UserController@getUsersWithAdminRole',
],
```

#### Database Views
```sql
-- Create database views for complex relationship queries
CREATE VIEW users_with_roles AS
SELECT u.*, r.name as role_name
FROM users u
JOIN model_has_roles mhr ON u.id = mhr.model_id
JOIN roles r ON mhr.role_id = r.id
WHERE mhr.model_type = 'App\Models\User';
```

### Performance Considerations

- **Eager Loading**: Use relationship embedding sparingly for large datasets
- **Pagination**: Always use pagination when embedding relationships
- **Selective Fields**: Specify only required fields in relationship embedding
- **Caching**: Leverage the built-in caching for frequently accessed relationships

### Performance-Optimized Queries
```bash
# Use lazy loading for complex queries with multiple filters
GET /api/v2/record/invoices?
  select=id,ref_number,customer:customers(id,name)&
  status=eq.PAID,PENDING&
  created_at=date_gte.2024-01-01&
  total=gt.1000&
  customer_id=not_in.1,2,3&
  lazy=true&
  per_page=50

# Cursor-based pagination for large datasets
GET /api/v2/record/transactions?
  sortby=created_at&
  order=desc&
  per_page=100&
  cursor=eyJjcmVhdGVkX2F0IjoiMjAyNC0wMS0xNSJ9
```

### Permission-Based Data Access
```bash
# User with viewOnlyCreateBy_customer permission will only see their own customers
GET /api/v2/record/customers
# Automatically filtered to: WHERE created_by = {authenticated_user_id}

# User with full view_customer permission sees all customers
GET /api/v2/record/customers
# No additional filtering applied
```

### Multi-Column OR Filtering
```bash
# Search across multiple fields with OR logic
GET /api/v2/record/customers?
  name,company_name=like.tech&
  email,phone=contains.support
# Finds customers where (name LIKE '%tech%' OR company_name LIKE '%tech%') 
# AND (email LIKE '%support%' OR phone LIKE '%support%')
```

## Examples

### Bulk Operations Examples

#### Legacy Bulk Endpoint (Auto-Detection)

##### Create Multiple Records (Direct Array)
```json
POST /api/v2/record/customers/bulk
[
  { "name": "John Doe", "email": "john@example.com" },
  { "name": "Jane Smith", "email": "jane@example.com" }
]
```

##### Mixed Operations (Create, Update, Delete)
```json
POST /api/v2/record/products/bulk
[
  { "name": "New Product", "price": 99.99 },
  { "id": 123, "name": "Updated Product", "price": 149.99 },
  { "id": 456, "_delete": true }
]
```

#### New Dedicated Bulk Endpoints

##### Bulk Create
```json
POST /api/v2/record/customers/bulk/create
[
  { "name": "John Doe", "email": "john@example.com", "phone": "+1234567890" },
  { "name": "Jane Smith", "email": "jane@example.com", "phone": "+0987654321" },
  { "name": "Bob Johnson", "email": "bob@example.com", "phone": "+1122334455" }
]
```

##### Bulk Update
```json
POST /api/v2/record/products/bulk/update
[
  { "id": 123, "name": "Updated Product A", "price": 149.99 },
  { "id": 124, "name": "Updated Product B", "price": 199.99 },
  { "id": 125, "price": 89.99 }
]
```

##### Bulk Delete
```json
POST /api/v2/record/invoices/bulk/delete
[
  { "id": 456 },
  { "id": 457 },
  { "id": 458 }
]
```

#### Bulk Response Example
```json
{
  "success": true,
  "data": [
    { "id": 789, "name": "New Product", "price": 99.99, "created_at": "2024-01-15T10:30:00Z" },
    { "id": 123, "name": "Updated Product", "price": 149.99, "updated_at": "2024-01-15T10:30:00Z" }
  ],
  "meta": {
    "request_id": "req_abc123",
    "affected": 3
  }
}
```

#### Error Response Examples

##### Validation Error (Missing ID for Update)
```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "0.id": ["The id field is required."],
    "2.id": ["The id field is required."]
  },
  "meta": {
    "request_id": "req_def456"
  }
}
```

##### Permission Error
```json
{
  "success": false,
  "message": "Insufficient permissions for bulk update operation.",
  "errors": {},
  "meta": {
    "request_id": "req_ghi789"
  }
}
```

### List invoices with embedding, filters, sorting, pagination
```
GET /api/v2/record/invoices?
  select=id,ref_number,customer:customers(id,name),items:invoice_items(*)&
  status=eq.PAID&created_at=between.2024-01-01,2024-12-31&
  sortby=created_at&order=desc&page=1&per_page=25
```

### List invoices with limit (non-paginated)
```
GET /api/v2/record/invoices?
  select=id,ref_number,customer:customers(id,name)&
  status=eq.PAID&limit=100
```

### Show one invoice with embedded customer
```
GET /api/v2/record/invoices/123?select=id,ref_number,customer:customers(id,name)
```

### List invoices with lazy loading for performance optimization
```
GET /api/v2/record/invoices?
  select=id,ref_number,total,customer:customers(id,name),items:invoice_items(*)&
  status=eq.PAID,PENDING&
  created_at=between.2024-01-01,2024-12-31&
  total=gt.500&
  lazy=true&
  sortby=created_at&order=desc&page=1&per_page=50
```

### Permission Examples
- Table `invoices` with `pms_name: "invoice"` → permissions: `view_invoice`, `create_invoice`, etc.
- Table `estimates` with `pms_name: "estimateSo"` → permissions: `view_estimateSo`, `create_estimateSo`, etc.
- Table `deposit_to_accounts` without `pms_name` → permissions: `view_deposit_to_account`, `create_deposit_to_account`, etc.
- Special permissions: `viewOnlyCreateBy_invoice`, `updateStatus_invoice`

## Function Endpoint Examples

### Global Function Examples

#### Execute Global Report Functions
```bash
# Get all roles (simple global function)
GET /api/v2/record/rpc/roles
Authorization: Bearer {jwt_token}

# Generate estimate report (simple global function)
POST /api/v2/record/rpc/report_estimate
Content-Type: application/json
Authorization: Bearer {jwt_token}
{
  "date_from": "2024-01-01",
  "date_to": "2024-12-31"
}

# Generate invoice report for specific invoice (parameterized global function)
GET /api/v2/record/rpc/report_invoice/123
Authorization: Bearer {jwt_token}

# Get customer invoice report (parameterized global function)
GET /api/v2/record/rpc/report_customer_invoice/456
Authorization: Bearer {jwt_token}
```

#### Global Function Response Examples
```json
// GET /api/v2/record/rpc/roles
{
  "success": true,
  "data": [
    { "id": 1, "name": "Admin", "permissions": [...] },
    { "id": 2, "name": "User", "permissions": [...] }
  ],
  "meta": {
    "request_id": "req_abc123"
  }
}

// POST /api/v2/record/rpc/report_estimate
{
  "success": true,
  "data": {
    "report_url": "/storage/reports/estimate_2024.pdf",
    "total_estimates": 45,
    "total_amount": 125000.00,
    "generated_at": "2024-01-15T10:30:00Z"
  },
  "meta": {
    "request_id": "req_def456"
  }
}
```

### Table Function Examples

#### Execute Table-Specific Functions
```bash
# Calculate user statistics (simple table function)
POST /api/v2/record/users/calculate_stats
Content-Type: application/json
Authorization: Bearer {jwt_token}
{
  "period": "monthly",
  "year": 2024
}

# Get user profile (parameterized table function)
GET /api/v2/record/users/get_profile/123
Authorization: Bearer {jwt_token}

# Export invoice data (simple table function)
POST /api/v2/record/invoices/export_data
Content-Type: application/json
Authorization: Bearer {jwt_token}
{
  "format": "csv",
  "date_range": {
    "from": "2024-01-01",
    "to": "2024-12-31"
  }
}

# Generate product report (parameterized table function)
GET /api/v2/record/products/generate_report/789
Authorization: Bearer {jwt_token}
```

#### Table Function Response Examples
```json
// POST /api/v2/record/users/calculate_stats
{
  "success": true,
  "data": {
    "total_users": 1250,
    "active_users": 980,
    "new_users_this_month": 45,
    "user_growth_rate": 3.6,
    "calculated_at": "2024-01-15T10:30:00Z"
  },
  "meta": {
    "request_id": "req_ghi789"
  }
}

// GET /api/v2/record/users/get_profile/123
{
  "success": true,
  "data": {
    "user": {
      "id": 123,
      "name": "John Doe",
      "email": "john@example.com",
      "profile": {
        "avatar_url": "/storage/avatars/123.jpg",
        "last_login": "2024-01-15T09:15:00Z",
        "preferences": {...}
      }
    }
  },
  "meta": {
    "request_id": "req_jkl012"
  }
}
```

### Error Handling for Functions

#### Function Not Found
```json
// GET /api/v2/record/rpc/non_existent_function
{
  "success": false,
  "message": "Function 'non_existent_function' not found",
  "meta": {
    "request_id": "req_error123"
  }
}
```

#### Insufficient Permissions
```json
// GET /api/v2/record/rpc/admin_only_function
{
  "success": false,
  "message": "Forbidden",
  "meta": {
    "request_id": "req_error456"
  }
}
```

#### Function Execution Error
```json
// POST /api/v2/record/users/calculate_stats (with invalid parameters)
{
  "success": false,
  "message": "Function execution failed: Invalid period parameter",
  "meta": {
    "request_id": "req_error789"
  }
}
```