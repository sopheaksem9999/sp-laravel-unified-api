# API Documentation

This document provides comprehensive documentation for the SP Laravel API package endpoints, request/response formats, and usage examples.

## Record CRUD API Documentation

This section documents the record CRUD endpoints provided by this package, including request/response formats, filtering, pagination, and error handling.

### Query Filtering (applyRequestFilters macro)
The package extends Laravel's `Illuminate\Database\Query\Builder` with a macro `applyRequestFilters`. This is the same filtering/pagination mechanism used by the record CRUD endpoints when listing records.

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

public function index(Request $request)
{
    // Start with any base query
    $query = DB::table('invoices')->where('active', true);

    // Apply filters from request (e.g. ?status=eq.paid&sortby=created_at)
    $result = $query->applyRequestFilters($request);

    return response()->json($result);
}
```

The `applyRequestFilters` method returns an array containing:
- `data`: The result set
- `meta`: Pagination metadata
- `headers`: Response headers
- `filters`: Applied filters
- `request`: Original request object
- `cursor_meta`: Cursor pagination metadata (if applicable)

### Relationship Selection & Filtering

Relationship loading uses the `select` query parameter (not `with`). This supports nested relationships and filtering within those relationships.

**Syntax:**
`?select=column1,column2,relationship(column1,column2,filter)`

**Examples:**

1. **Basic Inclusion:**
   `GET /api/v1/invoices?select=*,customer(*)`
   Fetches all columns from invoices and all columns from the `customer` relationship.

2. **Nested Inclusion:**
   `GET /api/v1/customers?select=*,orders(*,items(*))`
   Fetches customers with their orders and order items.

3. **Filtering Nested Records (Embedding):**
   You can apply filters to related records using the `column=operator.value` syntax inside the relationship parenthesis.

   `GET /api/v1/projects?select=*,tasks(*,assignees(*,name=eq.admin))`
   
   This fetches:
   - All columns from `projects`
   - All columns from `tasks`
   - All columns from `assignees` (users) WHERE `name` equals `admin`.

   **Supported Operators in Nested Filters:**
   - `eq`: Equal (`name=eq.John`)
   - `neq`: Not equal (`status=neq.archived`)
   - `gt`, `gte`: Greater than (or equal) (`age=gte.18`)
   - `lt`, `lte`: Less than (or equal) (`price=lt.100`)
   - `like`: Pattern matching (`name=like.%Smith%`)
   - `in`: In list (`status=in.active,pending`)
   
   **Note:** If no operator is specified (e.g., `name=admin`), it defaults to equality (`eq`).

4. **Filtering by Relationship (Top-Level):**
   You can filter the main result set based on criteria in related tables using the dot notation `relationship.column=operator.value`.

   `GET /api/v1/users?select=*,posts(*)&roles.name=eq.admin`

   This fetches:
   - Users who have a role named 'admin'.
   - Includes their posts (if requested via `select`).

   **Supported Relationships:**
   - `belongsTo`
   - `hasMany` (uses EXISTS subquery)
   - `hasManyThrough`
   - `belongsToMany` (uses pivot table)

   **Example:**
   `GET /api/v1/posts?author.name=eq.John`
   Fetches posts where the author's name is 'John'.

### Base Configuration

### API Prefix
All endpoints are served under a configurable prefix defined in `config/record.php`:

```php
'api_prefix' => 'api/v1',
'rpc_prefix' => 'rpc',
```

**Default**:
- CRUD API: `/api/v1`
- Global RPC: `/api/v1/rpc`

**Examples**: `/api`, `/api/v1`, `/api/v2`

### Global RPC
Global functions can be executed via the configured RPC prefix. Nested function names are supported.

`POST /api/v1/rpc/{functionName}`

Example:
- `POST /api/v1/rpc/auth/login`
- `POST /api/v1/rpc/system/status`


### Authentication
Record endpoints use table-level access rules from `config/record.php`:

- If a table/action is configured as public (`RecordTablePublic`), the endpoint is accessible without authentication.
- Otherwise, the controller requires an authenticated user from the guard configured in `config/sp-laravel-api.php` (`sp-laravel-api.auth.guard`, default: `api`) and checks permissions.

### Middleware Stack
- `api` - API middleware group
- `auth:{guard}` - Authentication (for routes that enforce middleware)
- `request.id` - Request ID tracking for audit trails
- Rate limiting with different throttles for different operation types

### Table-Level Validation
Each table configured in `config/record.php` (or in per-table files under `config/record/tables`) can define event-specific validators using the `RecordTableType` configuration:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Sopheak\Core\Types\RecordTableType;

return [
    'invoices' => new RecordTableType(
        pms_name: 'invoice',
        createValidator: function (Request $request, ?int $id = null): ValidatorContract {
            return Validator::make($request->all(), [
                'invoice_number' => 'required|string|max:50',
                'customer_id' => 'required|integer',
                'total' => 'required|numeric|min:0',
            ]);
        },
        updateValidator: function (Request $request, ?int $id = null): ValidatorContract {
            return Validator::make($request->all(), [
                'status' => 'sometimes|required|in:draft,pending,paid,cancelled',
            ]);
        },
        deleteValidator: function (Request $request, ?int $id = null): ValidatorContract {
            return Validator::make(['id' => $id], [
                'id' => 'required|integer',
            ]);
        },
    ),
];
```

- `createValidator` runs before `POST /{api_prefix}/{table}`.
- `updateValidator` runs before `PUT`/`PATCH /{api_prefix}/{table}/{id}`.
- `deleteValidator` runs before `DELETE /{api_prefix}/{table}/{id}`.

If a validator fails, the API returns a `422 Validation Error` with the standard error format described in the **Error Responses** section.

### Table-Level Triggers
In addition to validators, you can configure lifecycle triggers per table using `RecordTableTriggerType`. Triggers allow you to run custom code before and after core CRUD operations.

```php
use Illuminate\Http\Request;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableTriggerType;

return [
    'users' => new RecordTableType(
        pms_name: 'user',
        public: new RecordTablePublic(
            read: false,
            write: false,
        ),
        beforeRead: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'beforeRead',
        ),
        afterRead: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'afterRead',
        ),
        beforeCreate: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'beforeCreate',
        ),
        afterCreate: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'afterCreate',
        ),
        beforeUpdate: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'beforeUpdate',
        ),
        afterUpdate: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'afterUpdate',
        ),
        beforeDelete: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'beforeDelete',
        ),
        afterDelete: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'afterDelete',
        ),
    ),
];
```

Each trigger method is called with the following signature:

```php
public static function someTrigger(Request $request, string $table, mixed ...$args): Request|array|null
```

Triggers are invoked with `call_user_func_array([$class, $method], $params)` where `$params` always starts with:
- `Request $request`
- `string $table`

Additional arguments depend on the event/endpoint. Common patterns:

- `beforeRead` on list: `[$request, $table, ['type' => 'index', 'tenant_id' => mixed]]`
- `afterRead` on list: `[$request, $table, ['type' => 'index', 'filters' => [...], 'data' => [...], 'meta' => [...], 'tenant_id' => mixed, 'response' => JsonResponse]]`
- `beforeCreate` (single): `[$request, $table, ['tenant_id' => mixed]]`
- `beforeUpdate` (single): `[$request, $table, ['id' => mixed, 'tenant_id' => mixed]]`
- `beforeDelete` (single): `[$request, $table, ['id' => mixed, 'tenant_id' => mixed]]`
- Bulk endpoints may pass different params per item (e.g. `[$request, $table, $item]`, `[$request, $table, $id, $item]`, `[$request, $table, $id]`)

Return values:
- Return a `Request` to replace the current request (used mainly by `beforeRead`/`beforeCreate`/`beforeUpdate`/`beforeDelete`)
- Return an `array` to merge into the request input (merged into `$request->merge($result)`)
- Return `null` / no return to leave the request unchanged

Trigger handlers are strict: invalid trigger config, missing class/method, or any runtime error will abort the request and surface as an API error response. Use validators when you want user-friendly `422` validation errors.

### RecordTableType Parameters
`RecordTableType` configures a table’s routing, access control, caching, tenancy, validation, and lifecycle hooks.

Constructor (named arguments recommended):

```php
new RecordTableType(
    pms_name: 'invoice',
    table: null,
    has_tenant_id: false,
    soft_deletes: false,
    disable_auditLog: false,
    disable_cache: false,
    can_read: true,
    can_create: true,
    can_update: true,
    can_delete: true,
    public: new RecordTablePublic(),
    relationships: [],
    functions: [],
    primary_key: 'id',
    columns: [],
    column_hiddens: [],
    fulltext_indexes: [],
    auditLogFn: null,
    createValidator: null,
    updateValidator: null,
    deleteValidator: null,
    beforeRead: null,
    afterRead: null,
    beforeCreate: null,
    afterCreate: null,
    beforeUpdate: null,
    afterUpdate: null,
    beforeDelete: null,
    afterDelete: null,
);
```

#### Identity & Routing
- `pms_name` (?string, default: `null`): Used for permission mapping (e.g. `view:{pms_name}`). If `null`, it falls back to the table name (singular, snake_case) for permission generation.
- `table` (?string, default: `null`): Physical database table name. When `null`, the route table name is used as the DB table name.
- `primary_key` (?string, default: `'id'`): Primary key column name used by show/update/delete endpoints.

#### Tenancy
- `has_tenant_id` (bool, default: `false`): Marks this table as tenant-scoped when `record.enable_tenant_id` is enabled. When enabled and `has_tenant_id` is true, requests must include the tenant header (default: `X-Tenant-ID`) and queries are automatically filtered by tenant.

#### Access Control & Endpoint Availability
- `public` (RecordTablePublic, default: `new RecordTablePublic()`): Public access flags for grouped actions:
  - `read`: Allows unauthenticated access to read actions (`read`, `view`).
  - `write`: Allows unauthenticated access to write actions (`create`, `update`, `delete`, `restore`).
- `can_read` (bool, default: `true`): Enables/disables read endpoints for this table (list/show). When false, read routes respond as “not found”.
- `can_create` (bool, default: `true`): Enables/disables create endpoint.
- `can_update` (bool, default: `true`): Enables/disables update and restore endpoints.
- `can_delete` (bool, default: `true`): Enables/disables delete and force-delete endpoints.

#### Soft Deletes
- `soft_deletes` (bool, default: `false`): When true, list endpoints exclude `deleted_at` rows by default and restore/force-delete endpoints become relevant.

#### Caching & Audit
- `disable_cache` (bool, default: `false`): Disables query caching for this table (even if `record.cache.enabled` is true).
- `disable_auditLog` (bool, default: `false`): Disables audit log inserts for create/update/delete on this table.
- `auditLogFn` (?string, default: `null`): Reserved for custom audit log behavior; not used by the current runtime.

#### Schema & Search Metadata
- `columns` (?array, default: `[]`): Column metadata map. In normal usage this is populated at runtime from the database schema; leaving it empty is expected. It is used to whitelist payload fields and to detect audit columns like `created_by` / `updated_by`.
- `column_hiddens` (?array, default: `[]`): List of column names to always hide from API responses. This is applied recursively to nested relationships as well. Hidden columns are removed even if their value is `null`.
- `fulltext_indexes` (?array, default: `[]`): Declares full-text index column sets for search optimization. Format: a list of column name arrays, e.g. `[['name', 'description'], ['content']]`.

#### Relationships & Table RPC Functions
- `relationships` (?array, default: `[]`): Map of relationship name => relationship config object (e.g. `RecordHasManyType`, `RecordBelongsToType`, `RecordMetaBelongsToManyType`, etc.). Used by `select` relationship includes and nested relationship selections.
- `functions` (?array, default: `[]`): Map of function route name => function config (`RecordFunctionType` or array config). These are exposed under the table RPC route (e.g. `/{api_prefix}/{table}/rpc/{function}`) and can enforce permissions via `pms_name`.

#### Validators
- `createValidator` (callable|null, default: `null`): Runs before create. Must return an `Illuminate\Contracts\Validation\Validator`.
- `updateValidator` (callable|null, default: `null`): Runs before update. Must return an `Illuminate\Contracts\Validation\Validator`.
- `deleteValidator` (callable|null, default: `null`): Runs before delete. Must return an `Illuminate\Contracts\Validation\Validator`.

Validator signature:

```php
fn(\Illuminate\Http\Request $request, ?int $id = null): \Illuminate\Contracts\Validation\Validator
```

#### Triggers
- `beforeRead`, `afterRead`, `beforeCreate`, `afterCreate`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete` (`RecordTableTriggerType|array|null`, default: `null`): Lifecycle triggers. Each value can be:
  - a `RecordTableTriggerType` instance,
  - a single array trigger config (`['class' => ..., 'function_method' => ..., 'description' => ...]`),
  - or an array of trigger configs to run sequentially.

### Class-Based Configuration (Lazy Loading)

For large applications with many tables or complex schemas, you can define configurations in separate classes. This improves performance by only loading the necessary configuration for the requested endpoint (Lazy Loading).

#### 1. Table Configuration
Create a class extending `Sopheak\Core\Resources\RecordResource`:

```php
namespace App\Api\Tables;

use Sopheak\Core\Resources\RecordResource;
use Sopheak\Core\Types\RecordTableType;

class UserTable extends RecordResource
{
    public function configure(): RecordTableType
    {
        return new RecordTableType(
            table: 'users',
            can_create: true,
            can_update: true,
            can_delete: false,
            // ...
        );
    }
}
```

#### 2. Global Function Configuration
Create a class extending `Sopheak\Core\Resources\GlobalFunction`:

```php
namespace App\Api\Functions\Auth;

use Sopheak\Core\Resources\GlobalFunction;
use Sopheak\Core\Types\RecordFunctionType;

class LoginFunction extends GlobalFunction
{
    public function configure(): RecordFunctionType
    {
        return new RecordFunctionType(
            method: 'POST',
            class: \App\Services\AuthService::class,
            function_method: 'login',
            payload_schema: [ ... ]
        );
    }
}
```

#### 3. Registration
Register your classes in `config/record.php` or via `SchemaRegistry::register()`:

```php
// config/record.php
return [
    'tables' => [
        'users' => \App\Api\Tables\UserTable::class,
    ],
    'global_functions' => [
        'auth/login' => \App\Api\Functions\Auth\LoginFunction::class,
    ],
];
```

### Type Reference

#### RecordTablePublic
Public access flags for a table.

- `read` (bool, default: `false`): Allows unauthenticated read actions (`read`, `view`).
- `write` (bool, default: `false`): Allows unauthenticated write actions (`create`, `update`, `delete`, `restore`).

```php
use Sopheak\Core\Types\RecordTablePublic;

$public = new RecordTablePublic(
    read: true,
    write: false,
);
```

#### RecordTableTriggerType
Trigger configuration for table lifecycle events.

- `class` (string, required): Trigger handler class name.
- `function_method` (string, required): Static method to call on the class.
- `description` (?string, default: `null`): Optional description.

```php
use Sopheak\Core\Types\RecordTableTriggerType;

$beforeCreate = new RecordTableTriggerType(
    class: \App\Record\Triggers\InvoiceTriggers::class,
    function_method: 'beforeCreate',
);
```

#### RecordFunctionType
Defines a callable RPC endpoint config (table RPC or global RPC).

- `method` (array|string|RecordFunctionMethodEnum, required): Allowed HTTP methods.
- `class` (string, required): Handler class.
- `function_method` (string, required): Method name on handler class.
- `pms_name` (array|string|null, default: `null`): Permission(s). When `null`, the function is public (no permission check).
- `description` (?string, default: `null`): Optional description.
- `query_schema`, `payload_schema`, `response_schema` (?array, default: `null`): Optional schema metadata used by OpenAPI generation.

```php
use Sopheak\Core\Types\RecordFunctionType;

$function = new RecordFunctionType(
    method: ['POST'],
    class: \App\Services\ReportService::class,
    function_method: 'generate',
    pms_name: 'view_report',
    description: 'Generate a report',
);
```

#### RecordBelongsToType
Belongs-to relationship configuration.

- `table` (string, required): Related table name.
- `type` (RecordRelationshipsEnum, default: `RecordRelationshipsEnum::BELONGS_TO`)
- `foreignKey` (?string, default: `null`): FK column on the source table.
- `ownerKey` (?string, default: `'id'`): PK column on the target table.

```php
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

$customer = new RecordBelongsToType(
    table: 'customers',
    type: RecordRelationshipsEnum::BELONGS_TO,
    foreignKey: 'customer_id',
    ownerKey: 'id',
);
```

#### RecordHasManyType
Has-many relationship configuration (also used for nested writes when enabled).

- `table` (string, required): Related table name.
- `foreignKey` (string, required): FK column on the related table pointing back to parent.
- `type` (RecordRelationshipsEnum, default: `RecordRelationshipsEnum::HAS_MANY`)
- `localKey` (string, default: `'id'`): Parent key column.
- `with` (?array, default: `[]`): Default nested includes hint.
- `allowCreate`, `allowUpdate`, `allowDelete` (bool, default: `true`): Controls nested write operations for this relationship.

```php
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

$items = new RecordHasManyType(
    table: 'invoice_items',
    foreignKey: 'invoice_id',
    type: RecordRelationshipsEnum::HAS_MANY,
    localKey: 'id',
    with: [],
    allowCreate: true,
    allowUpdate: true,
    allowDelete: true,
);
```

#### RecordHasManyThroughType
Has-many-through relationship configuration.

- `table` (string, required): Target table name.
- `through` (string, required): Intermediate table name.
- `firstKey` (string, required): FK on intermediate table referencing the source model.
- `secondLocalKey` (string, default: `''`): FK on intermediate table referencing the target model.
- `secondKey` (string, default: `'id'`): PK on the target table.
- `localKey` (string, default: `'id'`): PK on the source table.
- `orderBy` (array, default: `['date' => 'desc']`): Sort configuration.
- `type` (RecordRelationshipsEnum, default: `RecordRelationshipsEnum::HAS_MANY_THROUGH`)
- `allowCreate`, `allowUpdate`, `allowDelete` (bool, default: `true`): Controls nested write operations for this relationship.

```php
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

$payments = new RecordHasManyThroughType(
    table: 'payments',
    through: 'invoice_payments',
    firstKey: 'invoice_id',
    secondKey: 'id',
    localKey: 'id',
    secondLocalKey: 'payment_id',
    orderBy: ['payment_date' => 'desc'],
    type: RecordRelationshipsEnum::HAS_MANY_THROUGH,
    allowCreate: true,
    allowUpdate: true,
    allowDelete: false, // Prevent deleting payments via this relationship
);
```

#### RecordMetaBelongsToManyType
Many-to-many relationship configuration with optional pivot details.

- `related` (string, required): Related model class name or related table name.
- `type` (RecordRelationshipsEnum, default: `RecordRelationshipsEnum::BELONGS_TO_MANY`)
- `table` (?string, default: `null`): Pivot table.
- `foreignPivotKey`, `relatedPivotKey` (?string, default: `null`): Pivot key columns.
- `parentKey`, `relatedKey` (?string, default: `null`): Key columns on source/target tables.
- `relation` (?string, default: `null`): Morph relation name (when using morph pivot patterns).
- `withPivot` (array, default: `[]`): Extra pivot columns to return.
- `wherePivot` (array, default: `[]`): Pivot constraints as `['pivot_col' => value]`.
- `withTimestamps` (bool, default: `false`): Include pivot timestamps.
- `select` (array, default: `[]`): Columns to select from related table.
- `pivotWhere` (array, default: `[]`): Legacy format; converted into `wherePivot` if `wherePivot` is empty.
- `allowCreate`, `allowUpdate`, `allowDelete` (bool, default: `true`): Controls nested write operations (attaching/detaching/updating).

```php
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

$roles = new RecordMetaBelongsToManyType(
    related: 'roles',
    type: RecordRelationshipsEnum::BELONGS_TO_MANY,
    table: 'user_roles',
    foreignPivotKey: 'user_id',
    relatedPivotKey: 'role_id',
    withPivot: ['assigned_at'],
    wherePivot: [],
    withTimestamps: true,
    select: ['roles.id', 'roles.name'],
    allowCreate: true,  // Allow attaching roles
    allowUpdate: false, // Prevent updating role details
    allowDelete: true,  // Allow detaching roles
);
```

#### RecordSpatiePermissionType
Specialized relationship config for `spatie/laravel-permission` morph pivot tables.

- Requires `spatie/laravel-permission` to be installed; the constructor throws if it is missing.
- Automatically ensures `model_type` is included in `withPivot`. If `teamsEnabled` is true, it also adds the team key to `withPivot`.

```php
use Sopheak\Core\Types\RecordSpatiePermissionType;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

$userRoles = new RecordSpatiePermissionType(
    related: config('permission.models.role'),
    relation: 'model',
    type: RecordRelationshipsEnum::SPATIE_PERMISSION,
    table: config('permission.table_names.model_has_roles'),
    foreignPivotKey: config('permission.column_names.model_morph_key'),
    relatedPivotKey: 'role_id',
    teamsEnabled: true,
);
```

### Standard CRUD Operations

#### List Records
```http
GET /{api_prefix}/{table}
```

Retrieve a paginated list of records with filtering, sorting, and relationship loading.

#### Query Parameters

**Pagination**
- `per_page` (integer, max: 100) - Items per page
- `page` (integer) - Page number (offset pagination)

**Search**
- `s` (string) - Search across searchable columns (uses full-text index when available, otherwise LIKE)

**Selection & Relationships**
- `select` (string) - Select main columns and include relationships using parentheses syntax.
  - Example: `?select=*,customer(*),items(*,product(*))`

**Sorting**
- `sortby` (string) - Field to sort by
- `order` (string: `asc`|`desc`) - Sort direction

**Limiting**
- `limit` (integer, max: 1000) - Limit results (only applied when `per_page` is not provided)

**Result Shape & Aggregation**
- `distinct` (boolean) - Apply SQL `DISTINCT` to the main query.
- `only_trashed` (boolean) - For soft-deleted tables, return only rows where `deleted_at` is not null.
- `aggregate` (string) - One or more aggregate expressions (comma-separated):
  - Supported functions: `count`, `sum`, `avg`, `min`, `max`.
  - Syntax:
    - `count` (no column) ⇒ `COUNT(*)`
    - `count:column` ⇒ `COUNT(column)`
    - `sum:column`, `avg:column`, `min:column`, `max:column`
  - Column names are validated against the table schema.
- `group_by` (string) - Comma-separated list of columns to group by. Column names are validated against the table schema.

**Debugging**
- `X-Debug` (HTTP header, boolean) - When sent as `true`, `1`, `yes`, or `on`, responses include lazy-loading diagnostics:
  - `meta.debug.lazy_stats` with the output of `QueryBuilderFilters::getLazyStats()`:
    - `total_operations`
    - `executed_operations`
    - `pending_operations`
    - `cache_hits`
    - `cache_efficiency`

**Filter Operators**
Filters are passed as `{column}={operator}.{value}` (operators validated against the table schema):
- `is.null`, `is_not.null`
- `eq.{value}`, `neq.{value}`, `in.{a,b,c}`, `not_in.{a,b,c}`
- `like.{value}`, `contains.{value}`, `not_like.{value}`, `starts_with.{value}`, `ends_with.{value}`, `regex.{pattern}`
- `gt.{value}`, `gte.{value}`, `lt.{value}`, `lte.{value}`
- `between.{start,end}`, `not_between.{start,end}`
- `date_eq.{YYYY-MM-DD}`, `date_gt.{YYYY-MM-DD}`, `date_gte.{YYYY-MM-DD}`, `date_lt.{YYYY-MM-DD}`, `date_lte.{YYYY-MM-DD}`
- `empty.null`, `not_empty.null`

When `aggregate` is present and valid, the list endpoint returns aggregated rows instead of paginated records. The response still follows the standard shape, with:
- `data`: Aggregated rows (including `group_by` columns and aggregate aliases like `count_id`).
- `meta.total`: Number of aggregated rows.
- `meta.group_by`: Grouped columns (when provided).
- `meta.aggregate`: List of aggregate operations with function, column, and alias metadata.

#### Example Request
```http
GET /api/v1/invoices?per_page=25&sortby=created_at&order=desc&select=*,customer(*),items(*,product(*))&status=eq.pending&total=gte.100&s=invoice
Authorization: Bearer {access_token}
```

#### Response Format
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "invoice_number": "INV-001",
      "total": 150.00,
      "status": "pending",
      "created_at": "2024-01-15T10:30:00Z",
      "customer": {
        "id": 5,
        "name": "John Doe",
        "email": "john@example.com"
      },
      "items": [
        {
          "id": 10,
          "description": "Product A",
          "quantity": 2,
          "price": 75.00
        }
      ]
    }
  ],
  "meta": {
    "request_id": "req_abc123def456",
    "page": 1,
    "per_page": 25,
    "total": 150
  }
}
```

#### Status Codes
- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Resource not available (table not configured or disabled)
- `500` - Server error

#### Get Single Record
```http
GET /{api_prefix}/{table}/{id}
```

Retrieve a single record by its primary key.

#### Query Parameters
- `select` (string) - Select main columns and include relationships using parentheses syntax
  - Example: `?select=*,customer(*),items(*,product(*))`

#### Example Request
```http
GET /api/v1/invoices/123?select=*,customer(*),items(*),payments(*)
Authorization: Bearer {access_token}
```

#### Response Format
```json
{
  "success": true,
  "data": {
    "id": 123,
    "invoice_number": "INV-123",
    "total": 250.00,
    "status": "paid",
    "customer": {
      "id": 5,
      "name": "John Doe"
    },
    "items": [...],
    "payments": [...]
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes
- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found (table not configured/disabled or record not found)
- `500` - Server error

#### Create Record
```http
POST /{api_prefix}/{table}
```

Create a new record.

If a `createValidator` is defined for the target table in `config/record.php`, the request body is validated using that validator before any database changes. On validation failure, the endpoint returns `422` with detailed error messages.

#### Create With Relationships In Response
To return related records in the response of the create call, pass a nested `select` query parameter. Relationship selection uses parentheses:
`relationship(columns,childRelationship(...))`.

Example (return `customer` and `items.product` after creating an `orders` record):

```bash
curl --location 'http://127.0.0.1:8000/api/v1/orders?select=*,customer(*),items(*,product(*))' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer {access_token}' \
  --data '{
    "customer_id": 5,
    "status": "draft",
    "total": 300.00
  }'
```

#### Request Body
JSON object with field values:

```json
{
  "invoice_number": "INV-124",
  "customer_id": 5,
  "total": 300.00,
  "status": "draft",
  "items": [
    {
      "description": "Product B",
      "quantity": 3,
      "price": 100.00
    }
  ]
}
```

#### Response Format
```json
{
  "success": true,
  "data": {
    "id": 124,
    "invoice_number": "INV-124",
    "customer_id": 5,
    "total": 300.00,
    "status": "draft",
    "created_at": "2024-01-15T11:00:00Z",
    "updated_at": "2024-01-15T11:00:00Z"
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes
- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Resource not available (table not configured or disabled)
- `422` - Validation error (table validator or request validation)
- `500` - Server error

#### Update Record
```http
PUT /{api_prefix}/{table}/{id}
PATCH /{api_prefix}/{table}/{id}
```

Update an existing record. `PUT` expects complete data, `PATCH` allows partial updates.

If an `updateValidator` is defined for the target table, the request is validated with access to both the incoming payload and the current record ID. Validation failures return `422` with error details.

#### Request Body
```json
{
  "status": "sent",
  "total": 275.00
}
```

#### Response Format
```json
{
  "success": true,
  "data": {
    "id": 124,
    "invoice_number": "INV-124",
    "status": "sent",
    "total": 275.00,
    "updated_at": "2024-01-15T11:30:00Z"
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes
- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found (table not configured/disabled or record not found)
- `422` - Validation error (table validator or request validation)
- `500` - Server error

#### Delete Record
```http
DELETE /{api_prefix}/{table}/{id}
```

Delete a record (soft delete if enabled, otherwise hard delete).

If a `deleteValidator` is defined for the target table, the request is validated (typically against the ID and context) before the record is deleted. Validation failures return `422` with error details.

#### Response Format
```json
{
  "success": true,
  "data": {
    "deleted": 1
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes
- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found (table not configured/disabled or record not found)
- `422` - Validation error (table validator or request validation)
- `500` - Server error

#### Restore Record
```http
POST /{api_prefix}/{table}/{id}/restore
```

Restore a soft-deleted record (only available for tables with soft deletes enabled).

#### Response Format
```json
{
  "success": true,
  "data": {
    "restored": 1
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes
- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found (table not configured/disabled or record not found)
- `500` - Server error

#### Force Delete Record
```http
DELETE /{api_prefix}/{table}/{id}/force
```

Permanently delete a record (bypasses soft delete).

#### Response Format
```json
{
  "success": true,
  "data": {
    "deleted": 1
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes
- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found (table not configured/disabled or record not found)
- `500` - Server error

### Global RPC Functions

Global RPC functions allow you to define custom endpoints that are not tied to a specific table. These are useful for system-wide operations like authentication, reporting, or utility functions.

Global functions are configured in `config/record.php` under the `global_functions` key.

### Public Global Functions
You can create public endpoints by setting `pms_name` to `null`. These functions can be accessed without authentication.

**Configuration Example:**
```php
'global_functions' => [
    'login' => [
        'method' => ['POST'],
        'class' => \App\Http\Controllers\AuthController::class,
        'function_method' => 'login',
        'description' => 'User login',
        'pms_name' => null, // Public access
        'payload_schema' => [ ... ],
        'response_schema' => [ ... ],
    ],
],
```

**Request:**
```bash
curl -X POST http://localhost:8000/api/v1/rpc/login \
  -H "Content-Type: application/json" \
  -d '{"email": "user@example.com", "password": "password"}'
```

### Protected Global Functions
By providing a `pms_name`, the function requires authentication and the user must have the specified permission(s).

**Configuration Example:**
```php
'global_functions' => [
    'system_stats' => [
        'method' => ['GET'],
        'class' => \App\Services\StatsService::class,
        'function_method' => 'getSystemStats',
        'description' => 'Get system statistics',
        'pms_name' => 'view_system_stats', // Requires auth & permission
    ],
],
```

**Request:**
```bash
curl -X GET http://localhost:8000/api/v1/rpc/system_stats \
  -H "Authorization: Bearer {token}"
```

### Routing
Global functions are registered with high priority, so a global function named `login` will take precedence over a table named `login`. However, they are constrained to the configured keys to avoid shadowing valid table routes unnecessarily.

---

### Nested Relationships

You can perform Create and Update operations on a record and its related records in a single request. This is supported for `hasMany` relationships configured in `config/record.php`.

### Nested Create
Create a parent record along with its related child records.

#### Request Body
```json
{
  "customer_name": "Tech Corp",
  "date": "2023-12-23",
  "total": 1500.00,
  "status": "draft",
  "items": [
    {
      "product_name": "Laptop",
      "quantity": 1,
      "price": 1200.00,
      "total": 1200.00
    },
    {
      "product_name": "Mouse",
      "quantity": 2,
      "price": 150.00,
      "total": 300.00
    }
  ]
}
```

#### Example Request
```bash
curl --location 'http://127.0.0.1:8000/api/v1/invoices' \
--header 'Content-Type: application/json' \
--header 'Authorization: Bearer {token}' \
--data '{
    "customer_name": "Tech Corp",
    "date": "2023-12-23",
    "total": 1500.00,
    "status": "draft",
    "items": [
        {
            "product_name": "Laptop",
            "quantity": 1,
            "price": 1200.00,
            "total": 1200.00
        }
    ]
}'
```

### Nested Update
Update a parent record and manage its relationships simultaneously. You can:
- **Update** existing children (provide `id`).
- **Create** new children (omit `id`).
- **Delete** existing children (provide `id` and `_delete: true` or `_destroy: true`).

#### Request Body
```json
{
  "total": 1750.00,
  "items": [
    {
      "id": 1,
      "quantity": 2,
      "total": 2400.00
    },
    {
      "id": 2,
      "_delete": true
    },
    {
      "product_name": "Keyboard",
      "quantity": 5,
      "price": 50.00,
      "total": 250.00
    }
  ]
}
```

#### Example Request
```bash
curl --location --request PUT 'http://127.0.0.1:8000/api/v1/invoices/123' \
--header 'Content-Type: application/json' \
--header 'Authorization: Bearer {token}' \
--data '{
    "total": 1750.00,
    "items": [
        {
            "id": 1,
            "quantity": 2,
            "total": 2400.00
        },
        {
            "id": 2,
            "_delete": true
        },
        {
            "product_name": "Keyboard",
            "quantity": 5,
            "price": 50.00,
            "total": 250.00
        }
    ]
}'
```

### Bulk Operations

Bulk operations allow you to perform Create, Update, or Delete actions on multiple records in a single HTTP request. This is significantly more efficient than sending individual requests for large datasets.

For performance considerations and best practices when using bulk operations, please refer to the [Performance & Scalability](performance.md) guide.

### Triggers & Validation in Bulk Operations

- **Validation**: Table-level validators (`createValidator`, `updateValidator`, `deleteValidator`) are currently **not** automatically applied to bulk operations. You should validate your payload before sending.
- **Triggers**: Table-level triggers (`beforeCreate`, `afterCreate`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`) **are executed** for each individual item in the bulk batch.
    - This allows you to maintain consistent business logic (e.g., setting default values, syncing with external systems) regardless of whether a record is created individually or in bulk.

### Legacy Bulk Operation
```http
POST /{api_prefix}/{table}/bulk
```

Auto-detects operation type based on request data structure.

### Bulk Create
```http
POST /{api_prefix}/{table}/bulk/create
```

Create multiple records in a single request.

#### Request Body
```json
{
  "data": [
    {
      "name": "Product A",
      "price": 100.00
    },
    {
      "name": "Product B", 
      "price": 150.00
    }
  ]
}
```

#### Response Format
```json
{
  "success": true,
  "data": {
    "created": 2,
    "failed": 0,
    "records": [
      {
        "id": 10,
        "name": "Product A",
        "price": 100.00
      },
      {
        "id": 11,
        "name": "Product B",
        "price": 150.00
      }
    ]
  },
  "message": "Bulk create completed: 2 created, 0 failed",
  "request_id": "req_abc123def456"
}
```

### Bulk Update
```http
POST /{api_prefix}/{table}/bulk/update
```

Update multiple records by ID.

#### Request Body
```json
{
  "data": [
    {
      "id": 10,
      "price": 110.00
    },
    {
      "id": 11,
      "price": 160.00
    }
  ]
}
```

### Bulk Delete
```http
POST /{api_prefix}/{table}/bulk/delete
```

Delete multiple records by ID.

#### Request Body
```json
{
  "ids": [10, 11, 12]
}
```

### Audit Management

### Get Audit Logs
```http
GET /{api_prefix}/audit/logs
```

Retrieve audit logs with filtering options.

#### Query Parameters
- `entity_type` (string, required) - Filter by entity type (table name, e.g. `invoices`)
- `entity_id` (integer, required) - Filter by specific entity ID
- `limit` (integer) - Max results (default: 50, max: 100)

#### Example Request
```http
GET /api/v1/audit/logs?entity_type=invoices&entity_id=123&limit=20
Authorization: Bearer {access_token}
```

#### Response Format
```json
{
  "success": true,
  "data": [
    {
      "id": 1001,
      "entity_type": "invoices",
      "entity_id": 123,
      "event": "updated",
      "old_data": "{\\n  \\\"id\\\": 123,\\n  \\\"status\\\": \\\"draft\\\"\\n}",
      "new_data": "{\\n  \\\"id\\\": 123,\\n  \\\"status\\\": \\\"sent\\\"\\n}",
      "subject": "INV-001",
      "recap": "",
      "user_id": 5,
      "entity_name": "invoices",
      "metadata": "{\\n  \\\"change_summary\\\": { ... },\\n  \\\"field_changes\\\": { ... }\\n}",
      "created_at": "2024-01-15T11:30:00Z"
    }
  ]
}
```

**Note:** `old_data`, `new_data`, and `metadata` are stored as JSON strings in the database. Clients can `JSON.parse` / `json_decode` them when needed.

### Get Audit Statistics
```http
GET /{api_prefix}/audit/stats
```

Get audit statistics and metrics.

#### Query Parameters
- `entity_type` (string) - Filter by entity type (table name)
- `entity_id` (integer) - Filter by specific entity ID
- `start_date` (date: Y-m-d) - Filter from date
- `end_date` (date: Y-m-d) - Filter to date (must be >= start_date)
- `event` (string) - Filter by event type (`created`, `updated`, `deleted`, `login`, `logout`, `failed_login`)

#### Response Format
```json
{
  "success": true,
  "data": {
    "total_logs": 45,
    "actions_breakdown": {
      "created": 10,
      "updated": 30,
      "deleted": 5
    },
    "top_users": [
      { "user_name": "John Admin", "count": 20 }
    ],
    "entity_types": {
      "invoices": 45
    }
  }
}
```

### Get Field Timeline
```http
GET /{api_prefix}/audit/field-timeline
```

Get timeline of changes for a specific field.

#### Query Parameters (Required)
- `entity_type` (string) - Entity type
- `entity_id` (integer) - Entity ID
- `field` (string) - Field name

#### Optional Parameters
- `limit` (integer) - Max results (default: 50, max: 50)

#### Response Format
```json
{
  "success": true,
  "data": [
    {
      "id": 1001,
      "changed_at": "2024-01-15T11:30:00Z",
      "old_value": "draft",
      "new_value": "sent",
      "change_type": "updated",
      "data_type": "string",
      "user_name": "John Admin",
      "event": "updated"
    },
    {
      "id": 990,
      "changed_at": "2024-01-10T09:15:00Z",
      "old_value": null,
      "new_value": "draft",
      "change_type": "created",
      "data_type": "string",
      "user_name": "Jane User",
      "event": "created"
    }
  ]
}
```

### Get Field Statistics
```http
GET /{api_prefix}/audit/field-stats
```

Get statistics for a specific field across entities.

#### Query Parameters (Required)
- `entity_type` (string) - Entity type
- `entity_id` (integer) - Entity ID
- `field` (string) - Field name

#### Response Format
```json
{
  "success": true,
  "data": {
    "total_changes": 150,
    "first_changed_at": "2024-01-01T08:00:00Z",
    "last_changed_at": "2024-01-15T11:30:00Z",
    "changes_by_user": {
      "John Admin": 80,
      "Jane User": 70
    },
    "field_name": "status"
  }
}
```

### Create Audit Log
```http
POST /{api_prefix}/audit/logs
```

Manually create an audit log entry.

#### Request Body
```json
{
  "event": "updated",
  "entity_type": "invoices",
  "entity_name": "invoices",
  "entity_id": 123,
  "subject": "INV-001",
  "recap": "Status changed via API",
  "metadata": {
    "id": 123,
    "old_data": { "status": "draft" },
    "new_data": { "status": "sent" }
  }
}
```

**Note:** If `metadata.old_data` and `metadata.new_data` are provided, they are used as the explicit old/new snapshots for the audit record. If omitted for updates, the system may infer `old_data` from the most recent `new_data` stored for the same entity.

### Get Specific Audit Log
```http
GET /{api_prefix}/audit/logs/{id}
```

Retrieve a specific audit log by ID.

### Cleanup Audit Logs
```http
DELETE /{api_prefix}/audit/cleanup
```

Clean up old audit logs (admin only - requires `can:manage-audit-logs` permission).

#### Query Parameters
- `days` (integer) - Retention period in days
- `dry_run` (boolean) - Preview what would be deleted

### Custom Functions

### Global Functions
```http
GET|POST|PUT|PATCH|DELETE /{api_prefix}/rpc/{functionName}
```

Execute global custom functions defined in `config/record.php`.

#### Examples
```http
# Simple global function
GET /api/v1/rpc/system_stats

# Parameterized global function  
POST /api/v1/rpc/generate_report
Content-Type: application/json
{
  "report_type": "monthly",
  "date_range": "2024-01"
}
```

### Table Functions
```http
GET|POST|PUT|PATCH|DELETE /{api_prefix}/{table}/rpc/{functionName}
```

Execute table-specific custom functions.

#### Examples
```http
# Simple table function
GET /api/v1/invoices/rpc/calculate_totals

# Parameterized table function
POST /api/v1/users/rpc/send_notification
Content-Type: application/json
{
  "message": "Welcome to our platform!",
  "type": "welcome"
}
```

### Error Responses

#### Standard Success Format
```json
{
  "success": true,
  "data": {},
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Standard Error Format
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."],
    "price": ["The price must be a number."]
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### HTTP Status Codes
- `200` - Success
- `201` - Created
- `204` - Deleted (no content)
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `429` - Rate Limited (throttle middleware)
- `500` - Server Error

### Rate Limiting

Different endpoints have different rate limits:

- **API Reads** (`throttle:api-reads`) - GET operations
- **API Writes** (`throttle:api-writes`) - POST, PUT, PATCH, DELETE operations  
- **API Functions** (`throttle:api-functions`) - Custom function calls

Rate limits are configurable in your Laravel application's rate limiting configuration.

### Security Considerations

### Authentication
- All endpoints require valid JWT tokens unless configured as public
- Tokens should be included in the `Authorization: Bearer {token}` header

### Authorization
- Permission-based access control using Spatie Laravel Permission
- Automatic tenant isolation when `tenant_id` column is present
- Special permissions for restricted access patterns

### Data Protection
- Automatic SQL injection prevention
- Input validation and sanitization
- Audit trail for all operations
- Configurable field exclusion for sensitive data

### Best Practices
- Use HTTPS in production
- Implement proper CORS policies
- Monitor rate limits and adjust as needed
- Regularly review audit logs
- Keep JWT secrets secure and rotate them periodically

## Trait QueryHelpers Documentation

`Sopheak\Core\Traits\QueryHelpers` is an Eloquent-only utility for building custom ORM-based endpoints. It is not part of the Record CRUD table endpoints and does not affect how `/api/v1/{table}` works.

### Purpose
- Provide a single Eloquent scope (`applyRequestFilters`) that converts request query parameters into Eloquent query constraints.
- Standardize filtering, sorting, pagination, and eager-loading patterns for custom controllers/services using Eloquent models.

### Usage (Clean Architecture)

**Model**
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Sopheak\Core\Traits\QueryHelpers;

class Invoice extends Model
{
    use QueryHelpers;
}
```

**Service**
```php
namespace App\Services;

use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceService
{
    public function list(Request $request)
    {
        return Invoice::query()->applyRequestFilters($request);
    }
}
```

**Controller**
```php
namespace App\Http\Controllers;

use App\Services\InvoiceService;
use Illuminate\Http\Request;

class InvoiceController
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request)
    {
        $result = $this->invoices->list($request);

        if ($result instanceof \Illuminate\Database\Eloquent\Builder) {
            return response()->json($result->get());
        }

        return response()->json($result);
    }
}
```

### Public API

#### applyRequestFilters (Eloquent scope)
Signature:
```php
public function scopeApplyRequestFilters(
    \Illuminate\Database\Eloquent\Builder $builder,
    \Illuminate\Http\Request $request,
    bool $isArray = false,
    string $orderBy = 'id'
)
```

Return behavior:
- Returns `LengthAwarePaginator` when `per_page` is present.
- Returns `LazyCollection` when `lazy=true`.
- Returns `array{data: mixed, total: int}` when `total_record=true`.
- Returns a `Collection` when `$isArray=true`.
- Otherwise returns the modified `Eloquent\Builder`.

### Supported Query Parameters

**Search**
- `s` or `search`: Searches across all columns in the model table. Example: `?s=invoice`.

**Select (main table only)**
- `select`: Selects only columns from the main table (table-prefixed). Example: `?select=id,invoice_number,total`.

**Eager Loading**
- `with`: Eloquent eager-loading. Examples:
  - `?with=customer,items`
  - `?with=posts.comments`
  - Column-constrained: `?with=customer(id,name)` or `?with=customer(*)`
  - JSON array is accepted: `?with=["customer(id,name)","items"]`

**Sorting**
- `sortby`: Sort column (defaults to `$orderBy`, default `id`)
- `order`: Sort direction (`asc` or `desc`, default `desc`)

**Result Shape**
- `per_page`: Enables pagination.
- `lazy=true`: Returns a `LazyCollection`.
- `limit`: Limits results when `$isArray=true` (max 20000).
- `total_record=true`: Returns `{ data, total }` (uses `limit` for the data size).

**Join Helpers**
- `join`: Table join by name. Example: `?join=customer` joins `customer.id = {main_table}.customer_id`.
- `select_join`: JSON map of `{table: [columns...]}`. Example: `?select_join={"customers":["name","email"]}`.

### Filter Operators

Operators are passed as `{column}={operator}.{value}`:
- `is.null`
- `eq.{value}`, `neq.{value}`
- `like.{value}`, `contains.{value}`
- `gt.{value}`, `gte.{value}`, `lt.{value}`, `lte.{value}`
- `in.{a,b,c}`
- `between.{start,end}`, `not_between.{start,end}`

Multiple columns OR (comma-separated keys):
- `?invoice_number,reference=contains.ACME`

Compare two columns:
- `?total=compare.gt.balance`

### Helper Methods (Internal)

These are private methods used by the trait implementation:

#### applyFilterOperator
```php
private function applyFilterOperator(
    \Illuminate\Database\Eloquent\Builder $builder,
    string $key,
    string $operator,
    string $queryValue,
    string $tableName
): void
```

#### parseSelectColumns
```php
private function parseSelectColumns(string $selectParam, string $tableName): array
```

#### parseWithRelations
```php
private function parseWithRelations(string $withParam): array
```

#### castStringToArray
```php
private function castStringToArray(string $value): array
```

#### handleOptimizedQuery
```php
private function handleOptimizedQuery(
    \Illuminate\Database\Eloquent\Builder $builder,
    \Illuminate\Http\Request $request
)
```
