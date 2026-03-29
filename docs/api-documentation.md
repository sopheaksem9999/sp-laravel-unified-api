# API Documentation

This document provides comprehensive documentation for the SP Laravel API package endpoints, request/response formats, and usage examples.

For full interactive examples, visit: https://sp-laravel-api-docs.vercel.app/#/

## Architecture Overview

### Design Philosophy

SP Laravel API is a **config-driven dynamic CRUD package**. You describe a table once in `RecordTableType` and the package registers all endpoints, enforces auth, applies tenant scoping, runs audit logging, and handles caching — with zero boilerplate controllers or routes in your application.

**Core principles:**

- **Config over code** — behavior is declared in `RecordTableType`, not implemented in controller methods.
- **DB-first, not model-first** — the package queries the database directly via `QueryBuilder`. Eloquent models are never required (and never instantiated by the package).
- **Composable opt-ins** — every non-trivial feature (tenancy, audit, cache, permissions, broadcast, bulk) is off by default and activated per-table or globally via config.
- **Strict backward compatibility** — new config keys always have safe defaults so existing clients upgrade without changes.

---

### Request Lifecycle

A full lifecycle for `GET /api/v1/invoices?status=eq.open&per_page=25`:

```
1. HTTP Request arrives
       │
       ▼
2. Laravel Router
   routes/api.php  →  CoreRecordController@listRecords
   (route registered by CoreSpLaravelApiProvider::boot)
       │
       ▼
3. RecordRouteMiddleware  (aliased: record.route.middleware)
   - resolves the action string (e.g. "read")
   - looks up middleware map from config/record.php middleware_map
   - pipes request through the resolved middleware stack
   - e.g. [auth:api, throttle:api-reads, subscription.check]
       │
       ▼
4. RequestId middleware
   - generates / propagates X-Request-ID header
   - stored in request attributes for response inclusion
       │
       ▼
5. CoreRecordController  (HasControllerHelpers trait)
   authorizeAction():
   - PermissionUtils checks if action is public
   - auth($guard)->user() resolves the authenticated user
   - Gate::forUser($user)->allows($perm) or custom authorizer
       │
       ▼
6. RecordService::listRecords()
   - SchemaRegistryUtils::getTable($table) → RecordTableType
   - tenant ID resolved from request header / attributes
   - QueryBuilder assembled with filters, sort, pagination
   - applyRequestFilters() applies all query params
   - relationships loaded via select= syntax
   - cache check (QueryCacheService) — return cached if hit
       │
       ▼
7. RecordApiResponseService::successWrapped()
   - wraps data in { success, error_code, data, meta }
   - injects request_id into meta
       │
       ▼
8. HTTP Response → client
```

For **write** operations (POST / PUT / PATCH / DELETE), step 6 expands:

```
6a. tableValidator runs (if defined)
6b. beforeCreate / beforeUpdate / beforeDelete trigger fires
6c. DB write (INSERT / UPDATE / DELETE)
6d. cache invalidated
6e. afterCreate / afterUpdate / afterDelete trigger fires
6f. processPostWriteLogic():
    → AuditLogService::insertAuditLog (queued)
    → RecordMutated::dispatch (if broadcast enabled)
```

---

### Component Map

| Component | Location | Responsibility |
|---|---|---|
| `CoreSpLaravelApiProvider` | `src/` | Registers routes, middleware aliases, Artisan commands, singleton bindings |
| `CoreRecordController` | `src/Http/Controllers/` | Single controller — composes all 4 traits |
| `HasControllerHelpers` | `Concerns/` | Auth check, tenant resolution, validator runner, transaction wrapper |
| `HasCrudOperations` | `Concerns/` | List, show, create, update, delete, restore, force-delete |
| `HasBulkOperations` | `Concerns/` | Bulk create / update / delete / upsert |
| `HasFunctionOperations` | `Concerns/` | Table RPC and global RPC dispatch |
| `RecordService` | `src/Services/` | All DB operations, trigger execution, post-write logic orchestration |
| `RecordConfigService` | `src/Services/` | Static accessors for all config values (`authGuard`, `tenantColumn`, `broadcastEventsEnabled`, …) |
| `RecordApiResponseService` | `src/Services/` | Unified response envelope (`success`, `error_code`, `data`, `meta`) |
| `AuditLogService` | `src/Services/` | Builds and persists audit log entries, field-level change tracking |
| `QueryCacheService` | `src/Services/` | Cache key generation, cache read/write, per-table TTL |
| `OpenApiService` | `src/Services/` | Generates OpenAPI 3.0 spec dynamically from registry |
| `AttributeDiscoveryService` | `src/Services/` | Scans model paths for `#[RecordTable]` attributes, builds `RecordTableType` instances |
| `SchemaRegistryUtils` | `src/Utilities/` | In-memory registry of all `RecordTableType` configs (file-based + attribute-discovered) |
| `RecordRouteMiddleware` | `src/Http/Middleware/` | Resolves and pipelines per-action middleware from `record.middleware_map` |
| `RequestId` | `src/Http/Middleware/` | Generates/propagates `X-Request-ID`, injects into response |
| `RecordMutated` | `src/Events/` | Broadcast event dispatched after every mutation |
| `RecordTableType` | `src/Types/` | The central config object — all table behavior is declared here |

---

### Schema Registry

`SchemaRegistryUtils` is the single source of truth for all table configurations at runtime.

```
config/record.php  ──────────────────────────────┐
config/records/tables/*.php  ────────────────────▶  SchemaRegistryUtils::get()
#[RecordTable] attribute discovery (opt-in)  ────┘         │
                                                            │  in-memory map:
                                                            │  [ 'invoices' => RecordTableType, ... ]
                                                            ▼
                                                     RecordService
                                                     OpenApiService
                                                     HasControllerHelpers
                                                     ListTablesCommand
```

**Priority order (highest wins):**
1. `config/record.php` inline `tables` array
2. Per-file `config/records/tables/{name}.php`
3. `#[RecordTable]` attribute discovery (if `SP_ATTRIBUTE_DISCOVERY=true`)

**`SchemaRegistryUtils` key methods:**

| Method | Purpose |
|---|---|
| `get()` | Returns full registry map, merging attribute-discovered tables |
| `getTable(string $key)` | Returns a single `RecordTableType` or `null` |
| `refresh()` | Clears the in-memory cache and forces a reload |

The registry is loaded once per request and held in a static property — no repeated config reads.

---

### Service Layer

**`RecordService`** is the core orchestration layer. The controller traits never touch the database directly — they delegate everything to `RecordService`.

```
Controller Trait  →  RecordService  →  QueryBuilder  →  Database
                           │
                           ├── executeTableTrigger()   (before/after hooks)
                           ├── executeGlobalTrigger()  (global before/after hooks)
                           ├── sanitizePayload()       (strips write-disabled columns)
                           ├── applyTimestampsAndAuditFields()
                           └── processPostWriteLogic() (audit + broadcast)
```

**`RecordConfigService`** is a pure static accessor layer — no state, no DB, just `config()` calls with typed return values. All other classes read config through it, never via raw `config()` calls, so config key names are centralized in one place.

**`RecordApiResponseService`** is bound as `api.response` singleton. Every response in the package goes through it so the envelope (`success`, `error_code`, `meta.request_id`) is guaranteed consistent.

---

### Controller Traits

`CoreRecordController` is a thin shell that composes four traits:

```php
class CoreRecordController extends Controller
{
    use HasControllerHelpers;   // auth, tenant, validators, transaction
    use HasCrudOperations;      // list, show, create, update, delete, restore, force-delete
    use HasBulkOperations;      // bulk create/update/delete/upsert
    use HasFunctionOperations;  // table RPC + global RPC
}
```

**Trait responsibilities:**

`HasControllerHelpers` — shared infrastructure used by all other traits:
- `authorizeAction()` — resolves user, maps permissions, calls Gate or custom authorizer
- `resolveTenantContext()` — extracts tenant ID from header / request attributes
- `runTableValidators()` — runs `createValidator` / `updateValidator` / `deleteValidator`
- `withinTransaction()` — wraps write operations in a DB transaction

`HasCrudOperations` — one method per HTTP verb:
- Each method follows: resolve schema → authorize → resolve tenant → run before-trigger → write → invalidate cache → run after-trigger → processPostWriteLogic → return response

`HasBulkOperations` — per-item loop with per-item trigger execution and post-write logic.

`HasFunctionOperations` — delegates to `RecordService::executeTableFunction` / `executeGlobalFunction`, which resolve function config from `RecordTableType::$functions` or `record.global_functions`.

---

### Middleware Pipeline

`RecordRouteMiddleware` uses Laravel's `Pipeline` to pipe each request through a dynamically resolved middleware stack.

```
config/record.php:
  middleware_map:
    read:   [auth:api, throttle:api-reads]
    write:  [auth:api, throttle:api-writes]
    public: [throttle:api-reads]
```

The middleware key (`read`, `write`, `public`, etc.) is passed as a route action parameter. `RecordRouteMiddleware::resolveMiddlewares()` looks up the key and returns the middleware array. If the table overrides middleware via `RecordTableType::$middlewareMap`, the table-level config takes precedence.

This means you can apply subscription checks, IP allow-lists, or custom rate limiters to specific action types without modifying the controller.

```
Route definition (routes/api.php):
  ->middleware('record.route.middleware:read')   ← key passed here
       │
       ▼
  RecordRouteMiddleware::handle()
       │
       ├── resolveMiddlewares($request, 'read')
       │     → checks RecordTableType::$middlewareMap first
       │     → falls back to config('record.middleware_map.read')
       │
       └── Pipeline::through($middlewares)->then($next)
```

## Record CRUD API Documentation

This section documents the record CRUD endpoints provided by this package, including request/response formats, filtering, pagination, and error handling.

### API Docs Access Mode

The bundled docs UI endpoint is:

- `GET /api-docs`

Use `config/record.php` to control visibility:

```php
'api_docs' => [
    'is_private' => env('SP_LARAVEL_API_DOCS_PRIVATE', false),
    'access_token_key' => 'access_token',
    'login_api' => '/v1/auth/login',
    'email' => env('SP_LARAVEL_API_DOCS_EMAIL'),
],
```

Behavior:

- If `api_docs` config is missing, docs stay public by default.
- If `is_private=false`, `/api-docs` loads Scalar directly.
- If `is_private=true`, `/api-docs` shows a custom login form first.
- If `is_private=true`, Scalar uses secure web routes:
  - `POST /api-docs/auth/login`
  - `POST /api-docs/auth/logout`
  - `GET /api-docs/openapi.json`
- In private mode, API endpoints `/{api_prefix}/docs/openapi(.json)` and `/{api_prefix}/docs/llms.*` are hidden with `404` to avoid schema leakage.
- `login_api` supports relative route or absolute URL, so each client project can point docs login to its own auth endpoint.
- `access_token_key` controls token extraction key from login response payload.
- `email` is optional and enforces a fixed docs login account.

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

**Builder macro signature:**

```php
public function applyRequestFilters(
    \Illuminate\Http\Request $request,
    bool $isArray = false,
    string $orderBy = 'id',
    ?string $tenantColumn = ''
): array
```

Because the macro has named parameters, you can also call it using named arguments (PHP 8+):

```php
$result = DB::table('invoices')->applyRequestFilters(
    request: $request,
    isArray: true,
    orderBy: 'created_at',
    tenantColumn: 'company_id',
);
```

### Relationship Selection & Filtering

Relationship loading uses the `select` query parameter for nested inclusion and filtering. The legacy `with` parameter is still supported for simple eager loading without column filtering.

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

5. **Legacy `with` Parameter (still supported):**
   - `GET /api/v1/customers?with=invoices`
   - `GET /api/v1/customers?with=invoices,contacts`
   This eagerly loads relationships but does not support nested filters inside `with`.

### Supported Relationship Types

The dynamic API understands all relationship types declared in `RecordRelationshipsEnum`. These relationships are configured per table via the `relationships` array on `RecordTableType` and are available to `select` and filter expressions.

#### Belongs To (`belongsTo`)

Use `RecordBelongsToType` when the current table has a foreign key pointing to a parent table.

Example configuration:

```php
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Types\RecordBelongsToType;

'invoices' => new RecordTableType(
    table: 'invoices',
    relationships: [
        'customer' => new RecordBelongsToType(
            table: 'customers',
            type: RecordRelationshipsEnum::BELONGS_TO,
            foreignKey: 'customer_id',
            ownerKey: 'id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/invoices?select=*,customer(*)`
- `GET /api/v1/invoices?customer.name=like.%Acme%`

#### Has Many (`hasMany`)

Use `RecordHasManyType` when the current table is the parent and the related table has the foreign key.

Example configuration:

```php
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Types\RecordHasManyType;

'customers' => new RecordTableType(
    table: 'customers',
    relationships: [
        'invoices' => new RecordHasManyType(
            table: 'invoices',
            type: RecordRelationshipsEnum::HAS_MANY,
            foreignKey: 'customer_id',
            localKey: 'id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/customers?select=*,invoices(*)`
- `GET /api/v1/customers?invoices.status=eq.paid`

#### Has One (`hasOne`)

Use `RecordHasManyType` with `RecordRelationshipsEnum::HAS_ONE` when the related table has a unique row per parent (semantically has-one, loaded via the same optimized path as has-many).

Example configuration:

```php
'users' => new RecordTableType(
    table: 'users',
    relationships: [
        'profile' => new RecordHasManyType(
            table: 'user_profiles',
            type: RecordRelationshipsEnum::HAS_ONE,
            foreignKey: 'user_id',
            localKey: 'id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/users?select=*,profile(*)`

#### Belongs To Many (`belongsToMany`)

Use `RecordMetaBelongsToManyType` for many-to-many relationships backed by a pivot table. This cannot be replaced by `RecordAassociationType` because `RecordAassociationType` only supports has-many-through over a meta table with owner/target columns and does not support pivot semantics (extra pivot columns, timestamps, morph pivots, or arbitrary pivot keys).

Example configuration:

```php
use Sopheak\Core\Types\RecordMetaBelongsToManyType;

'users' => new RecordTableType(
    table: 'users',
    relationships: [
        'roles' => new RecordMetaBelongsToManyType(
            related: 'roles',
            type: RecordRelationshipsEnum::BELONGS_TO_MANY,
            table: 'role_user',
            foreignPivotKey: 'user_id',
            relatedPivotKey: 'role_id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/users?select=*,roles(*)`
- `GET /api/v1/users?roles.name=eq.admin`

#### Has Many Through (`hasManyThrough`)

Three variants are supported:

1. **Standard has-many-through** using `RecordHasManyThroughType`.
2. **Global meta-table has-many-through** using `RecordMetaHasManyThroughType`.
3. **Association has-many-through** using `RecordAassociationType` with simplified parameters.

`RecordAassociationType` can replace `RecordMetaHasManyThroughType` only when your meta table uses the standard columns (`owner`, `owner_id`, `target`, `target_id`) and `owner` stores the source table name while `target` stores the related table name. If your meta table uses different column names or needs ownerColumn customization, keep `RecordMetaHasManyThroughType`.

Standard example:

```php
use Sopheak\Core\Types\RecordHasManyThroughType;

'projects' => new RecordTableType(
    table: 'projects',
    relationships: [
        'tasks' => new RecordHasManyThroughType(
            table: 'tasks',
            through: 'project_tasks',
            firstKey: 'project_id',
            secondKey: 'id',
            localKey: 'id',
            secondLocalKey: 'task_id',
        ),
    ],
),
```

Global meta-table example:

```php
use Sopheak\Core\Types\RecordMetaHasManyThroughType;

'packages' => new RecordTableType(
    table: 'packages',
    relationships: [
        'modules' => new RecordMetaHasManyThroughType(
            table: 'modules',
            through: 'meta',
            firstKey: 'owner_id',
            secondKey: 'id',
            localKey: 'id',
            secondLocalKey: 'target_id',
            ownerColumn: 'owner',
            owner: 'package',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/projects?select=*,tasks(*)`
- `GET /api/v1/packages?select=*,modules(*)`

Association example (simplified parameters with meta table):

```php
use Sopheak\Core\Types\RecordAassociationType;

'packages' => new RecordTableType(
    table: 'packages',
    relationships: [
        'modules' => new RecordAassociationType(
            related: 'meta',
            type: RecordRelationshipsEnum::HAS_MANY_THROUGH,
            fromObjectType: 'packages',
            fromObjectId: 'owner_id',
            toObjectType: 'modules',
            toObjectId: 'target_id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/packages?select=*,modules(*)`

#### Has One Through (`hasOneThrough`)

`RecordHasManyThroughType` also supports the `HAS_ONE_THROUGH` semantic. In most cases, you configure it the same way as has-many-through but use the enum to indicate the expected cardinality.

Example configuration:

```php
'users' => new RecordTableType(
    table: 'users',
    relationships: [
        'latestInvoice' => new RecordHasManyThroughType(
            table: 'invoices',
            through: 'invoice_logs',
            firstKey: 'user_id',
            secondKey: 'id',
            localKey: 'id',
            secondLocalKey: 'invoice_id',
            orderBy: ['created_at' => 'desc'],
            type: RecordRelationshipsEnum::HAS_ONE_THROUGH,
        ),
    ],
),
```

Example usage:

- `GET /api/v1/users?select=*,latestInvoice(*)`

#### Morph Relationships

Morph relationships are detected via `RecordRelationshipsEnum::isMorphRelationship()` and are supported anywhere relationship selection is supported.

##### morphTo / morphOne / morphMany

These are typically configured via specialized resource classes or custom loaders. The enum types are:

- `MORPH_TO`
- `MORPH_ONE`
- `MORPH_MANY`

Example conceptual usage (comments only):

- A `comments` table with `commentable_type` and `commentable_id` can be exposed as a morphTo relationship from `comments` to multiple parent tables (e.g. posts, invoices).
- In the API, you can select nested comments using `?select=*,comments(*)` regardless of the underlying parent model.

##### morphToMany / morphByMany

Many-to-many morph relationships use a pivot table and are treated as pivot-supporting morph types.

Example using `RecordMetaBelongsToManyType` with a morph relation:

```php
'models' => new RecordTableType(
    table: 'models',
    relationships: [
        'roles' => new RecordMetaBelongsToManyType(
            related: config('permission.models.role'),
            type: RecordRelationshipsEnum::MORPH_TO_MANY,
            table: config('permission.table_names.model_has_roles'),
            foreignPivotKey: config('permission.column_names.model_morph_key'),
            relatedPivotKey: 'role_id',
            relation: 'model',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/models?select=*,roles(*)`

##### Spatie Permission (`spatiePermission`)

The package includes a dedicated `RecordSpatiePermissionType` to integrate with `spatie/laravel-permission` using a morphToMany pattern.

Example configuration:

```php
use Sopheak\Core\Types\RecordSpatiePermissionType;

'users' => new RecordTableType(
    table: 'users',
    relationships: [
        'roles' => new RecordSpatiePermissionType(
            related: config('permission.models.role'),
            relation: 'model',
            recordRelationshipsEnum: RecordRelationshipsEnum::SPATIE_PERMISSION,
            table: config('permission.table_names.model_has_roles'),
            foreignPivotKey: config('permission.column_names.model_morph_key'),
            relatedPivotKey: 'role_id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/users?select=*,roles(*)`
- `GET /api/v1/users?roles.name=eq.admin`

### Relationship Write Payload Guide

For `POST` / `PUT` / `PATCH`, relationship input is type-driven and should follow the config in `RecordTableType->relationships`.

#### What can be sent in payload

| Enum type (`RecordRelationshipsEnum`) | Payload support | Payload shape |
|---|---|---|
| `BELONGS_TO` | ✅ FK scalar only | `customer_id: 10` |
| `HAS_MANY` | ✅ alias array | `items: [1, {"id": 2}, {"name": "Line A"}]` |
| `BELONGS_TO_MANY` | ✅ alias array | `roles: [1, {"id": 2}]` |
| `HAS_MANY_THROUGH` | ✅ alias array | `tasks: [3, {"id": 4}]` |
| `MORPH_MANY` | ✅ alias array | `comments: [1, {"id": 2}]` |
| `MORPH_TO_MANY` | ✅ alias array | `roles: [1, {"id": 2}]` |
| `MORPH_BY_MANY` | ✅ alias array | `tags: [1, {"id": 2}]` |
| `SPATIE_PERMISSION` | ✅ alias array | `roles: [1, {"id": 2}]` |
| `HAS_ONE` | ⚠️ use FK style of your schema | Prefer scalar FK field in root payload |
| `HAS_ONE_THROUGH` | ⚠️ not a direct write alias | Use main table fields / custom function |
| `MORPH_TO` | ⚠️ use morph columns in root payload | `commentable_type`, `commentable_id` |
| `MORPH_ONE` | ⚠️ use FK style of your schema | Prefer scalar FK field in root payload |

#### FK-style examples (`BELONGS_TO`)

```json
{
  "ref_number": "INV-1001",
  "customer_id": 10
}
```

Do not send:

```json
{
  "customer": { "id": 10, "name": "Acme" }
}
```

#### Many-type alias examples (`*Many`)

```json
{
  "items": [
    1,
    { "id": 2 },
    { "name": "Line A", "qty": 1 },
    { "id": 5, "_delete": true }
  ]
}
```

#### Pivot-style examples (`BELONGS_TO_MANY`, `MORPH_TO_MANY`, `SPATIE_PERMISSION`)

```json
{
  "roles": [
    1,
    { "id": 2 },
    { "id": 3, "_delete": true }
  ]
}
```

#### Notes

- Array relationship aliases are accepted only when declared in table `relationships` config.
- For `BELONGS_TO`, the payload should use root FK scalar fields, not nested objects.
- `_delete` / `_destroy` can be used on alias-array items where relationship handling supports detach/remove.

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

### Table Configuration Files

Record behavior is driven by `RecordTableType` configurations defined in `config/record.php` and (optionally) in per-table files under `config/records/tables`.

- `config/record.php` contains global options and can inline smaller schemas.
- `config/records/tables/{name}.php` can return a single `RecordTableType` or an array of `[table_name => RecordTableType]` for large schemas.

You can scaffold a new per-table configuration file via Artisan:

```bash
php artisan sp-laravel-api:record customers
```

This creates `config/records/tables/customers.php` with a basic `RecordTableType` definition for the `customers` table. After creating the file and the corresponding database table, you can populate the `columns` metadata from the database schema:

```bash
php artisan sp-laravel-api:sync-record-columns --force
```

Record endpoints use table-level access rules from `config/record.php`:

- If a table/action is configured as public (`RecordTablePublic`), the endpoint is accessible without authentication.
- Otherwise, the controller requires an authenticated user from the guard configured in `config/sp-laravel-api.php` (`sp-laravel-api.auth.guard`, default: `api`) and checks permissions.
- Permission checks support a custom authorization handler via `record.authorization`.

Custom authorization handler (`record.authorization`) options:

- `null` (default): use `Gate::forUser($user)->allows($permission)`
- class-string: resolved from container and called as `handle($user, $permission, $table, $action): bool`
- closure/callable: called as `fn($user, string $permission, string $table, string $action): bool`

Example:

```php
// config/record.php
'authorization' => \App\Security\RecordAuthorization::class,
```

```php
<?php

namespace App\Security;

final class RecordAuthorization
{
    public function handle(mixed $user, string $permission, string $table, string $action): bool
    {
        return \Illuminate\Support\Facades\Gate::forUser($user)->allows($permission);
    }
}
```

### Middleware Stack

- `api` - API middleware group
- `request.id` - Request ID tracking for audit trails
- Rate limiting with different throttles for different operation types
- `record.route.middleware:{action}` - Dynamic middleware dispatcher resolved from `config/record.php` `middleware_map`

### Middleware Map (Public / Auth / Auth+Subscription)

Use `middleware_map` in `config/record.php` to apply middleware by endpoint group/action and per table.

```php
'middleware_map' => [
    'default' => [
        '*' => [],
        'read' => [],
        'write' => ['auth:sanctum'],
        'function' => ['auth:sanctum'],
    ],
    'tables' => [
        'customers' => [
            'read' => [],
        ],
        'orders' => [
            'write' => ['auth:sanctum', 'subscribed'],
            'table_function' => ['auth:sanctum', 'subscribed'],
        ],
    ],
],
```

How this matches common client requirements:

- Public query route: keep `read` empty (or only safe middleware like throttling).
- Auth-only route: use `write => ['auth:sanctum']` or per-action `create`, `update`, `delete`.
- Auth + subscription route: add `subscribed` in table/action stack (e.g. `orders.write`).

### Request Context in Hooks and Custom Audit

Request context is available to hooks and custom audit callback via:

- `$context['request_context']` in trigger/audit callback params
- `request()->attributes->get('record_context')`

Built-in `request_context` payload:

- `tenant_id` (`string|int|null`)
- `tenant_column` (`string`, usually `tenant_id`)
- `tenant_source` (`attribute|header|null`)
- `user` (`array|null`) with keys:
  - `id` (`mixed`)
  - `guard` (`string`)
- `request_id` (`string|null`)
- `table` (`string`)
- `action` (`string|null`)

Source priority behavior (built-in):

- `attribute`: recommended for trusted middleware-populated tenant (`resolved_tenant_id`) or context tenant (`record_context.tenant_id`)
- `header`: fallback to tenant header (`X-Tenant-ID` by default)

This same priority is also used by Eloquent trait filtering (`QueryHelpersTrait::scopeApplyRequestFilters`), so model queries remain aligned with dynamic CRUD tenant behavior.

Tenant filtering in `QueryHelpersTrait` is applied when tenant mode is enabled and tenant column exists by any of:

- model `fillable`
- registered `RecordTableType` columns
- database schema column check

Example middleware to set trusted tenant (`resolved_tenant_id`) and enrich `record_context`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->user()?->tenant_id;

        if ($tenantId !== null && $tenantId !== '') {
            $request->attributes->set('resolved_tenant_id', $tenantId);
        }

        $context = $request->attributes->get('record_context', []);
        if (!is_array($context)) {
            $context = [];
        }

        $context['tenant_id'] = $tenantId;
        $context['tenant_source'] = 'attribute';
        $context['client_app'] = 'backoffice';
        $request->attributes->set('record_context', $context);

        return $next($request);
    }
}
```

Then register middleware in `record.middleware_map` for table/action route groups so CRUD/trigger flow can consume the context.

Example trigger (`beforeCreate`) using context for auto value:

```php
public static function beforeCreate(\Illuminate\Http\Request $request, string $table, array $context): array
{
    $ctx = $context['request_context'] ?? $request->attributes->get('record_context', []);
    $tenantId = $ctx['tenant_id'] ?? null;
    $userId = $ctx['user']['id'] ?? null;

    $payload = $request->all();
    $payload['tenant_id'] = $tenantId;
    $payload['created_by'] = $userId;
    $request->replace($payload);

    return [$request, $table, $context];
}
```

### Table-Level Validation

Each table configured in `config/record.php` (or in per-table files under `config/record/tables`) can define event-specific validators using the `RecordTableType` configuration. Validators support:

- Callable arrays (e.g. `[ClassName::class, 'method']`)
- `RecordValidationType` objects
- Arrays of validator configs (multiple validators per event)

```php
use App\Record\Validators\RecordValidator;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordValidationType;

return [
    'invoices' => new RecordTableType(
        pmsName: 'invoice',
        createValidator: [
            [RecordValidator::class, 'createInvoice'],
            new RecordValidationType(
                class: RecordValidator::class,
                functionName: 'createInvoice',
            ),
        ],
        updateValidator: [
            new RecordValidationType(
                class: RecordValidator::class,
                functionName: 'updateInvoice',
            ),
        ],
        deleteValidator: [
            [RecordValidator::class, 'deleteInvoice'],
        ],
    ),
];
```

If you run `php artisan config:cache`, avoid closures (including `fn (...) => ...`) in config files. Use callable arrays like `[ClassName::class, 'method']` (or a `'ClassName::method'` callable string).

Example validator class:

```php
<?php

declare(strict_types=1);

namespace App\Record\Validators;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class RecordValidator
{
    public static function createInvoice(Request $request, ?int $id = null): ValidatorContract
    {
        return Validator::make($request->all(), [
            'invoice_number' => 'required|string|max:50',
            'customer_id' => 'required|integer',
            'total' => 'required|numeric|min:0',
        ]);
    }

    public static function updateInvoice(Request $request, ?int $id = null): ValidatorContract
    {
        return Validator::make($request->all(), [
            'status' => 'sometimes|required|in:draft,pending,paid,cancelled',
        ]);
    }

    public static function deleteInvoice(Request $request, ?int $id = null): ValidatorContract
    {
        return Validator::make(['id' => $id], [
            'id' => 'required|integer',
        ]);
    }

    public static function deletePackage(Request $request, ?int $id = null): ValidatorContract
    {
        return Validator::make(['id' => $id], [
            'id' => 'required|integer',
        ]);
    }
}
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
        pmsName: 'user',
        public: new RecordTablePublic(
            read: false,
            write: false,
        ),
        beforeRead: [
          [
            new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            functionName: 'beforeRead',
          )
          ]
        ],
        afterRead: [
          [
            new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            functionName: 'afterRead',
          )
          ]
        ],
        beforeCreate: [
          [
            new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            functionName: 'beforeCreate',
          )
          ]
        ],
        afterCreate: [
          [
            new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            functionName: 'afterCreate',
          )
          ]
        ],
        beforeUpdate: [
          [
            new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            functionName: 'beforeUpdate',
          )
          ]
        ],
        afterUpdate: [
          [
            new RecordTableTriggerType(
              class: \App\Record\Triggers\UserTriggers::class,
              functionName: 'afterUpdate',
          )
          ]
        ],
        beforeDelete: [
          [
            new RecordTableTriggerType(
              class: \App\Record\Triggers\UserTriggers::class,
              functionName: 'beforeDelete',
          )
          ]
        ],
        afterDelete: [
          [
            new RecordTableTriggerType(
              class: \App\Record\Triggers\UserTriggers::class,
              functionName: 'afterDelete',
          )
          ]
        ],
    ),
];
```

### Global Triggers

You can register global triggers that apply to all tables and run in addition to per-table triggers.

```php
use Sopheak\Core\Types\RecordTableTriggerType;

return [
    'global_triggers' => [
        'beforeRead' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'beforeRead'
        ),
        'afterRead' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'afterRead'
        ),
        'beforeCreate' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'beforeCreate'
        ),
        'afterCreate' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'afterCreate'
        ),
        'beforeUpdate' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'beforeUpdate'
        ),
        'afterUpdate' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'afterUpdate'
        ),
        'beforeDelete' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'beforeDelete'
        ),
        'afterDelete' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'afterDelete'
        ),
    ],
];
```

Order:

- `before*` global triggers run before table-level `before*` triggers.
- `after*` global triggers run after table-level `after*` triggers.

### Default Validation (Schema-Based)

You can enable automatic validation rules derived from table columns. This is optional and disabled by default.

```php
return [
    'default_validation' => [
        'enabled' => true,
        'only_when_missing' => true,
        'required' => true,
        'types' => true,
        'unique' => true,
        'foreign_keys' => true,
    ],
];
```

Rules generated:

- **required**: non-nullable columns without defaults (excluding system columns and tenant column)
- **types**: basic mapping (`uuid`, `integer`, `numeric`, `boolean`, `date`, `string`, `array`)
- **unique**: single-column unique indexes
- **foreign_keys**: `exists:{table},{column}` based on DB constraints

Notes:

- Only applies on **create** and **update** endpoints (including bulk create/update).
- When `only_when_missing` is `true`, table validators still take priority.

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
- `beforeDelete` (single): `[$request, $table, ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object]]`
- `afterDelete` (single): `[$request, $table, ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object, 'soft_deleted' => bool, 'response' => JsonResponse]]`
- `beforeRestore` (single): `[$request, $table, ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object]]`
- `afterRestore` (single): `[$request, $table, ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object, 'restored' => int, 'response' => JsonResponse]]`
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
    pmsName: 'invoice',
    table: null,
    hasTenantId: false,
    softDeletes: false,
    disableAuditLog: false,
    disableCache: false,
    disableBroadcast: false,
    canRead: true,
    canCreate: true,
    canUpdate: true,
    canDelete: true,
    isAuthRead: true,
    isAuthWrite: true,
    public: new RecordTablePublic(),
    relationships: [],
    functions: [],
    primaryKey: 'id',
    columns: [],
    columnHiddens: [],
    columnWriteDisabled: [],
    columnIndexes: [],
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

- `pmsName` (?string, default: `null`): Used for permission mapping (e.g. `view:{pmsName}`). If `null`, it falls back to the table name (singular, snake_case) for permission generation.
- `table` (?string, default: `null`): Physical database table name. When `null`, the route table name is used as the DB table name.
- `primaryKey` (?string, default: `'id'`): Primary key column name used by show/update/delete endpoints.

#### Tenancy

- `hasTenantId` (bool, default: `false`): Marks this table as tenant-scoped when `record.enable_tenant_id` is enabled. When enabled and `hasTenantId` is true, requests must include the tenant header (default: `X-Tenant-ID`) and queries are automatically filtered by tenant.

#### Access Control & Endpoint Availability

- `isAuthRead` (bool, default: `true`): Auth requirement flag for read endpoints. `true` forces authentication, `false` makes read endpoints public.
- `isAuthWrite` (bool, default: `true`): Auth requirement flag for write endpoints. `true` forces authentication, `false` makes write endpoints public.
- `public` (RecordTablePublic|bool, legacy compatibility): Derived from `isAuthRead`/`isAuthWrite` for backward compatibility. New config should prefer auth flags directly.
- `canRead` (bool, default: `true`): Enables/disables read endpoints for this table (list/show). When false, read routes respond as “not found”.
- `canCreate` (bool, default: `true`): Enables/disables create endpoint.
- `canUpdate` (bool, default: `true`): Enables/disables update and restore endpoints.
- `canDelete` (bool, default: `true`): Enables/disables delete and force-delete endpoints.

#### Soft Deletes

- `softDeletes` (bool, default: `false`): When true, list endpoints exclude `deleted_at` rows by default and restore/force-delete endpoints become relevant.

#### Caching & Audit

- `disableCache` (bool, default: `false`): Disables query caching for this table (even if `record.cache.enabled` is true).
- `disableAuditLog` (bool, default: `false`): Disables audit log inserts for create/update/delete on this table.
- `disableBroadcast` (bool, default: `false`): Suppresses `RecordMutated` broadcast events for this table even when `record.broadcast_events` is globally enabled. Useful for high-volume tables where real-time broadcasting is not needed.
- `auditLogFn` (?string, default: `null`): Reserved for custom audit log behavior; not used by the current runtime.

#### Schema & Search Metadata

- `columns` (?array, default: `[]`): Column metadata map. In normal usage this is populated at runtime from the database schema; leaving it empty is expected. It is used to whitelist payload fields and to detect audit columns like `created_by`, `created_by_id`, `updated_by`, `last_updated_by`, and `last_updated_by_id`.
- `columnHiddens` (?array, default: `[]`): List of column names to always hide from API responses. This is applied recursively to nested relationships as well. Hidden columns are removed even if their value is `null`.
- `columnWriteDisabled` (?array, default: `[]`): List of column names that are not writable via API payloads (create, update, upsert, and nested relationship writes). These columns are stripped from incoming payloads even if provided by the client.
- `columnIndexes` (?array, default: `[]`): Declares full-text index column sets for search optimization. Format: a list of column name arrays, e.g. `[['name', 'description'], ['content']]`.

#### Relationships & Table RPC Functions

- `relationships` (?array, default: `[]`): Map of relationship name => relationship config object (e.g. `RecordHasManyType`, `RecordBelongsToType`, `RecordMetaBelongsToManyType`, etc.). Used by `select` relationship includes and nested relationship selections.
- `functions` (?array, default: `[]`): Map of function route name => function config (`RecordFunctionType` or array config). These are exposed under the table RPC route (e.g. `/{api_prefix}/{table}/rpc/{function}`) and can enforce permissions via `pmsName`. Use `disableCache` and `cacheTTL` to control function caching.

#### Validators

- `createValidator` (callable|null, default: `null`): Runs before create. Must return an `Illuminate\Contracts\Validation\Validator`.
- `updateValidator` (callable|null, default: `null`): Runs before update. Must return an `Illuminate\Contracts\Validation\Validator`.
- `deleteValidator` (callable|null, default: `null`): Runs before delete. Must return an `Illuminate\Contracts\Validation\Validator`.

Validator signature:

```php
fn(\Illuminate\Http\Request $request, ?int $id = null): \Illuminate\Contracts\Validation\Validator
```

#### Computed Attributes

- `attributes` (?array, default: `null`): Map of computed field name => callable resolver. Resolvers are **lazy** — they only execute when the field name is explicitly listed in the `?select=` query parameter. When no `?select=` is provided, or the field is not in the requested columns, the resolver is never called.

Supported resolver formats:

| Format | Example |
|---|---|
| Closure | `fn($row, $table) => value` |
| `[Class, method]` array | `[BrandAttribute::class, 'getLogoUrl']` |
| `'Class@method'` string | `BrandAttribute::class . '@getLogoUrl'` |
| `'ClassName'` string | `BrandAttribute::class` → calls `handle($row, $table)` |

The resolver class is resolved via the Laravel container (`app()`), so constructor injection works. `$row` is the raw DB row (`stdClass` in most cases).

**Config example:**

```php
use Sopheak\Core\Types\RecordTableType;

'brands' => new RecordTableType(
    table: 'brands',
    attributes: [
        'full_label' => [\App\Attributes\BrandAttribute::class, 'getFullLabel'],
        'logo_url'   => \App\Attributes\BrandAttribute::class . '@getLogoUrl',
        'is_premium' => fn($row, $table) => ($row->tier ?? null) === 'premium',
    ],
),
```

**Attribute class example:**

```php
namespace App\Attributes;

class BrandAttribute
{
    public function getFullLabel(mixed $row, string $table): string
    {
        return ($row->name ?? '') . ' (' . ($row->code ?? '') . ')';
    }

    public function getLogoUrl(mixed $row, string $table): ?string
    {
        $path = $row->logo_path ?? null;
        return $path ? config('app.url') . '/storage/' . $path : null;
    }
}
```

**Requesting computed attributes via `?select=`:**

```
GET /api/v1/brands                               → no resolvers called
GET /api/v1/brands?select=id,name                → no resolvers called
GET /api/v1/brands?select=id,name,full_label     → only full_label resolver fires
GET /api/v1/brands?select=id,full_label,logo_url → both resolvers fire
GET /api/v1/brands/1?select=id,logo_url          → logo_url fires on single-record endpoint
GET /api/v1/brands?select=*,logo_url             → logo_url fires; * fetches all DB columns
```

> **Note**: Attribute keys are not database columns. They are automatically excluded from the SQL `SELECT` to prevent "Unknown column" errors, while still being resolved and injected into the response after the query.

#### Column Casts

`RecordTableType` accepts a top-level `casting` property — a `[column => cast]` map that mirrors Laravel's `$casts` on Eloquent models. Casting is **opt-in** — only columns listed in `casting` are transformed; all others pass through unchanged. `null` values are always preserved as-is. Columns that use `compositeFields` are automatically skipped.

Flat keys target main-table columns. **Dot-notation keys** target columns inside eagerly-loaded relationships — `'relation.column'` casts `column` on every row of that relation, regardless of whether the relation is a single object (`belongsTo`/`hasOne`) or a collection (`hasMany`/`hasManyThrough`).

Supported built-in cast strings (Laravel-compatible names):

| Cast | PHP transformation |
|---|---|
| `int` / `integer` | `(int) $value` |
| `float` / `double` / `real` | `(float) $value` |
| `decimal` | `(float) $value` |
| `decimal:N` | `number_format((float) $value, N, '.', '')` |
| `string` | `(string) $value` |
| `bool` / `boolean` | `(bool) $value` |
| `array` / `json` | `json_decode($value, true)` |
| `object` | `json_decode($value)` |
| `date` | `Carbon::parse($value)->toDateString()` |
| `datetime` | `Carbon::parse($value)->toISOString()` |
| `timestamp` | `Carbon::parse($value)->getTimestamp()` |

Custom cast forms — the callable receives `($value, $column, $row)`:

| Format | Example |
|---|---|
| Closure | `fn($v, $col, $row) => strtoupper($v)` |
| `[Class, 'method']` array | `[GlobalCasting::class, 'bool']` — static or instance |
| `'Class@method'` string | `'App\\Casts\\MoneyCast@get'` |
| `'ClassName'` string | `MoneyCast::class` → calls `->get($value, $column, $row)` |

> Static methods are preferred automatically — the implementation checks `is_callable([ClassName, method])` first before falling back to container instantiation.

**Config example:**

```php
use App\Record\Casts\GlobalCasting;

// config/record.php
'casting' => [
    'is_active' => 'bool',
    'amount' => 'decimal:2',
],

// table config
'brands' => new RecordTableType(
    table: 'brands',
    columns: [
        'is_active' => ['type' => 'boolean'],
        'quantity' => ['type' => 'bigint'],
        'price' => ['type' => 'decimal(12,2)'],
        'name' => ['type' => 'varchar', 'nullable' => false],
    ],
    casting: [
        // explicit override (custom output format)
        'price'      => fn($v) => number_format((float) $v, 2, '.', ''),
        // overrides global record.casting['amount'] when table-level is defined
        'amount'     => 'string',
        // explicit override from inferred integer
        'quantity'   => 'string',
        // explicit override from inferred boolean
        'is_active'  => 'bool',
        'metadata'   => 'array',
        'score'      => 'decimal:4',
        'created_at' => 'datetime',
        // custom static method
        'is_cloud'   => [GlobalCasting::class, 'bool'],
        // inline Closure
        'status'     => fn($v) => strtoupper($v),
    ],
),
```

**Custom cast class example** (static methods work; no interface required):

```php
namespace App\Record\Casts;

class GlobalCasting
{
    public static function bool(mixed $value, string $column, mixed $row): bool
    {
        return (bool) $value;
    }
}
```

> **Note**: Columns that also define `compositeFields` are skipped by cast processing — composite type conversion takes precedence.

**Relationship (dot-notation) casting example:**

```php
'orders' => new RecordTableType(
    table: 'orders',
    casting: [
        // flat main-table casts
        'total'       => 'float',
        'placed_at'   => 'datetime',

        // hasMany — cast each item row
        'items.price'    => 'float',
        'items.qty'      => 'int',
        'items.metadata' => 'array',

        // belongsTo — cast the single related object
        'customer.is_verified' => 'bool',
        'customer.score'       => 'decimal:2',

        // Closure on a relation column
        'customer.tier' => fn($v) => strtoupper($v),
    ],
),
```

> The relation key (`items`, `customer`) must match the property name returned in the JSON response — i.e. the key used in `with()` / `$appends`. Nesting deeper than one level (e.g. `'order.items.price'`) is not supported; handle deeper nesting with a Closure on the intermediate relation.

#### Per-Action Permission Map

- `permissions` (?array, default: `null`): Per-table map that overrides the auto-generated `pmsName`-based permissions for specific actions. Only the actions listed in this map are affected — all other actions still fall back to the standard `PermissionUtils::mapPermissions()` logic using `pmsName` and `permission_separator`.

Supported action keys:

| Key | Applied by |
|---|---|
| `'read'` | `listRecords` (GET list) and `getRecordById` (GET single) |
| `'create'` | `createRecord` and the create-check in `upsertRecord` |
| `'update'` | `updateRecord` and the update-check in `upsertRecord` |
| `'delete'` | `destroyRecord` (soft-delete) |
| `'force_delete'` | `forceDeleteRecord` (permanent delete — independent, no fallback to `'delete'`) |
| `'restore'` | `restoreRecord` |

The value for each action can be a single permission string or an array of strings. Any one matching permission grants access (same OR logic used by the standard permission resolver).

**Config example:**

```php
'items' => new RecordTableType(
    table: 'items',
    pmsName: 'item',  // still used for actions not listed in permissions
    permissions: [
        'read'    => 'view_list_item',
        'create'  => 'insert_new_item',
        'update'  => 'update_existing_item',
        'delete'       => 'remove_item',
        'force_delete' => 'permanently_remove_item',
        'restore'      => 'restore_item',
        // partial overrides also work — e.g. only override 'read':
        // 'read' => ['view_item', 'admin_access'],
    ],
),
```

> **Backward compatibility**: When `permissions` is `null` (the default), all actions use the existing `pmsName`-based permission generation unchanged.

#### Triggers

- `beforeRead`, `afterRead`, `beforeCreate`, `afterCreate`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`, `beforeRestore`, `afterRestore` (`RecordTableTriggerType|array|null`, default: `null`): Lifecycle triggers. Each value can be:
  - a `RecordTableTriggerType` instance,
  - a single array trigger config (`['class' => ..., 'functionName' => ..., 'description' => ...]`),
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
            canCreate: true,
            canUpdate: true,
            canDelete: false,
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
            httpMethod: 'POST',
            class: \App\Services\AuthService::class,
            functionName: 'login',
            payloadSchema: [ ... ]
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

Legacy public access flags for a table. New configurations should use `isAuthRead` and `isAuthWrite` on `RecordTableType`.

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
- `functionName` (string, required): Static method to call on the class.
- `description` (?string, default: `null`): Optional description.

```php
use Sopheak\Core\Types\RecordTableTriggerType;

$beforeCreate = new RecordTableTriggerType(
    class: \App\Record\Triggers\InvoiceTriggers::class,
    functionName: 'beforeCreate',
);
```

#### RecordFunctionType

Defines a callable RPC endpoint config (table RPC or global RPC).

- `httpMethod` (array|string|RecordFunctionMethodEnum, required): Allowed HTTP methods.
- `class` (string, required): Handler class.
- `functionName` (string, required): Method name on handler class.
- `pmsName` (array|string|null, default: `null`): Permission(s). When `null`, the function is public (no permission check).
- `disableCache` (bool, default: `false`): Disable caching for this function.
- `cacheTTL` (?int, default: `null`): Custom cache TTL (seconds). When set, overrides the default cache TTL.
- `clearCacheTables` (array|string|null, default: `null`): Tables to clear after successful write methods (`POST`, `PUT`, `PATCH`, `DELETE`). If omitted for table functions, the current table is cleared.
- `description` (?string, default: `null`): Optional description.
- `querySchema`, `payloadSchema`, `responseSchema` (?array, default: `null`): Optional schema metadata used by OpenAPI generation.

```php
use Sopheak\Core\Types\RecordFunctionType;

$function = new RecordFunctionType(
    httpMethod: ['POST'],
    class: \App\Services\ReportService::class,
    functionName: 'generate',
    pmsName: 'view_report',
    disableCache: false,
    clearCacheTables: ['reports'],
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
- `with_trashed` (boolean) - For soft-deleted tables, include soft-deleted rows alongside active rows. Takes precedence over the default exclusion of deleted records.
- `aggregate` (string) - One or more aggregate expressions (comma-separated):
  - Supported functions: `count`, `sum`, `avg`, `min`, `max`.
  - Syntax:
    - `count` (no column) ⇒ `COUNT(*)`
    - `count:column` ⇒ `COUNT(column)`
    - `sum:column`, `avg:column`, `min:column`, `max:column`
  - Column names are validated against the table schema.
- `group_by` (string) - Comma-separated list of columns to group by. Column names are validated against the table schema.

**Debugging**

- `X-Debug` (HTTP header, boolean) - When sent as `true`, `1`, `yes`, or `on`, responses include lazy-loading diagnostics and error debug details:
  - `meta.debug.lazy_stats` with the output of `QueryBuilderFilters::getLazyStats()`:
    - `total_operations`
    - `executed_operations`
    - `pending_operations`
    - `cache_hits`
    - `cache_efficiency`
  - on error responses, `meta.debug` may include exception context (`exception`, `exception_message`, `file`, `line`).
- `record.debug` (config, boolean, default: `false`) also enables error debug details globally without needing `X-Debug`.
- When debug mode is enabled (via config or header), error responses are also written to Laravel log (`Log::error`) with request context and error metadata.

**Filter Operators**
Filters are usually passed as `{column}={operator}.{value}` (operators validated against the table schema). Some operators support a value-less shorthand form for `null`:

- `is.null`, `is_not.null` or simply `is`, `is_not` (equivalent to `IS NULL` / `IS NOT NULL`)
- `eq.{value}`, `neq.{value}`, `in.{a,b,c}`, `not_in.{a,b,c}`
- `like.{value}`, `contains.{value}`, `not_like.{value}`, `starts_with.{value}`, `ends_with.{value}`, `regex.{pattern}`
- `ilike.{value}`, `match.{pattern}`, `imatch.{pattern}`
- `gt.{value}`, `gte.{value}`, `lt.{value}`, `lte.{value}`
- `between.{start,end}`, `not_between.{start,end}`
- `date_eq.{YYYY-MM-DD}`, `date_gt.{YYYY-MM-DD}`, `date_gte.{YYYY-MM-DD}`, `date_lt.{YYYY-MM-DD}`, `date_lte.{YYYY-MM-DD}`
- Full-text operators: `fts.{query}`, `plfts.{query}`, `phfts.{query}`, `wfts.{query}`
- Native Postgres operators: `cs.{value}`, `cd.{value}`, `ov.{value}`, `sl.{value}`, `sr.{value}`, `nxl.{value}`, `nxr.{value}`, `adj.{value}`
- `empty.null`, `not_empty.null` or simply `empty`, `not_empty`:
  - On text columns, `empty` ⇔ `IS NULL OR = ''`, `not_empty` ⇔ `IS NOT NULL AND != ''`.
  - On non-text columns (e.g. integers), `empty` ⇔ `IS NULL`, `not_empty` ⇔ `IS NOT NULL`.
- Negated style is also supported using expression syntax:
  - `not.eq.5`, `not.in.(1,2,3)`, `not.like.ACME`, `not.fts.invoice`
- `any` / `all` modifiers are supported in expression syntax:
  - `name=like(any).{ACME,SHOP}`
  - `name=ilike(all).{spx,admin}`

**Grouped Logic**

- Top-level query params continue to behave as `AND`.
- You can add grouped logic params:
  - `or=(...)`
  - `and=(...)`
- Inside grouped logic, each condition uses expression syntax:
  - `{column}.{operator}.{value}`
  - Example: `id.eq.5`, `balance_due.gt.0`, `id.in.(5,6,9)`

Examples:

- `vendor_id=eq.27&or=(balance_due.gt.0,id.eq.5)`
  - Interpreted as: `vendor_id = 27 AND (balance_due > 0 OR id = 5)`
- `vendor_id=eq.27&and=(or(balance_due.gt.0,id.eq.5),id.neq.2)`
  - Interpreted as: `vendor_id = 27 AND ((balance_due > 0 OR id = 5) AND id != 2)`
- `id=in.(5,6,9)` and `id=not_in.(5,6,9)` are supported in addition to legacy list style (`id=in.5,6,9`).
- `status=eq.open&and=(or(total_amount.gte.1000,total_amount.is.null),or(currency.eq.USD,currency.eq.KHR),issued_at.date_gte.2026-01-01,issued_at.date_lte.2026-12-31)`
  - Interpreted as: `status = 'open' AND ((total_amount >= 1000 OR total_amount IS NULL) AND (currency = 'USD' OR currency = 'KHR') AND issued_at >= '2026-01-01' AND issued_at <= '2026-12-31')`
- `customer_id=eq.18&or=(and(balance_due.gt.0,due_date.lt.2026-03-31),and(id.in.(5,6,9),ref_number.like.BILL-2026))`
  - Interpreted as: `customer_id = 18 AND ((balance_due > 0 AND due_date < '2026-03-31') OR (id IN (5,6,9) AND ref_number LIKE '%BILL-2026%'))`
- `vendor_id=eq.27&or=(vendor.display_name.like.Acme,items.account_code.in.(4000,4010),items.amount.gt.0)`
  - Example of grouped logic including relationship filters (`vendor.*`, `items.*`) in the same OR expression.

Notes:

- For grouped logic, use comma-separated expressions inside the group.
- Do not use `=` or `&` inside `or=(...)` / `and=(...)`.
- For URL safety, grouped logic can also be sent in decoded form:
  - `or=(balance_due.gt.0,id.in.(5,6,9))`
- Complex grouped examples are best URL-encoded when sent from frontend clients.
- If an operator is not supported by the current database driver, API returns validation error with an explicit message.

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
  "error_code": 0,
  "data": [
    {
      "id": 1,
      "invoice_number": "INV-001",
      "total": 150.0,
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
          "price": 75.0
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
- `with_trashed` (boolean) - For soft-deleted tables, fetch the record even if it has been soft-deleted.

#### Example Request

```http
GET /api/v1/invoices/123?select=*,customer(*),items(*),payments(*)
Authorization: Bearer {access_token}
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
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
  "total": 300.0,
  "status": "draft",
  "items": [
    {
      "description": "Product B",
      "quantity": 3,
      "price": 100.0
    }
  ]
}
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "id": 124,
    "invoice_number": "INV-124",
    "customer_id": 5,
    "total": 300.0,
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

#### Upsert Record

```http
POST /{api_prefix}/{table}/upsert
```

Create a new record or update an existing one based on matching columns.

#### Query Parameters

- `match_on` (string, required) - Comma-separated list of columns to use for matching records.
  - Example: `?match_on=sku` or `?match_on=email,tenant_id`

#### Request Body

JSON object with field values:

```json
{
  "sku": "PROD-001",
  "name": "Wireless Mouse",
  "price": 29.99
}
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "id": 125,
    "payload": {
      "sku": "PROD-001",
      "name": "Wireless Mouse",
      "price": 29.99
    }
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes

- `200` - Success
- `422` - Validation error (missing `match_on` or invalid payload)

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
  "total": 275.0
}
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "id": 124,
    "invoice_number": "INV-124",
    "status": "sent",
    "total": 275.0,
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
DELETE /{api_prefix}/{table}/{id}?force=true
```

Delete a record (soft delete if enabled, otherwise hard delete).

Adding `?force=true` is a shortcut that permanently deletes the record regardless of soft-delete configuration — identical in behavior to the dedicated `DELETE /{api_prefix}/{table}/{id}/force` endpoint. Useful when you want to conditionally force-delete without changing the URL path.

If a `deleteValidator` is defined for the target table, the request is validated (typically against the ID and context) before the record is deleted. Validation failures return `422` with error details.

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
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
  "error_code": 0,
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
  "error_code": 0,
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

You can create public endpoints by setting `pmsName` to `null`. These functions can be accessed without authentication.

**Configuration Example:**

```php
'global_functions' => [
    'login' => [
        'httpMethod' => ['POST'],
        'class' => \App\Http\Controllers\AuthController::class,
        'functionName' => 'login',
        'description' => 'User login',
        'pmsName' => null, // Public access
        'payloadSchema' => [ ... ],
        'responseSchema' => [ ... ],
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

By providing a `pmsName`, the function requires authentication and the user must have the specified permission(s).

**Configuration Example:**

```php
'global_functions' => [
    'system_stats' => [
        'httpMethod' => ['GET'],
        'class' => \App\Services\StatsService::class,
        'functionName' => 'getSystemStats',
        'description' => 'Get system statistics',
        'pmsName' => 'view_system_stats', // Requires auth & permission
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
  "total": 1500.0,
  "status": "draft",
  "items": [
    {
      "product_name": "Laptop",
      "quantity": 1,
      "price": 1200.0,
      "total": 1200.0
    },
    {
      "product_name": "Mouse",
      "quantity": 2,
      "price": 150.0,
      "total": 300.0
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
  "total": 1750.0,
  "items": [
    {
      "id": 1,
      "quantity": 2,
      "total": 2400.0
    },
    {
      "id": 2,
      "_delete": true
    },
    {
      "product_name": "Keyboard",
      "quantity": 5,
      "price": 50.0,
      "total": 250.0
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

> **Opt-in flag:** Bulk routes are registered only when `record.bulk_operations` is `true` (the default). Set `SP_BULK_OPERATIONS=false` in your `.env` to disable all bulk endpoints entirely.

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
      "price": 100.0
    },
    {
      "name": "Product B",
      "price": 150.0
    }
  ]
}
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "created": 2,
    "failed": 0,
    "records": [
      {
        "id": 10,
        "name": "Product A",
        "price": 100.0
      },
      {
        "id": 11,
        "name": "Product B",
        "price": 150.0
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
      "price": 110.0
    },
    {
      "id": 11,
      "price": 160.0
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

### Bulk Upsert

```http
POST /{api_prefix}/{table}/bulk/upsert
```

Bulk create or update records based on matching columns.

#### Query Parameters

- `match_on` (string, required) - Comma-separated list of columns to use for matching records.
  - Example: `?match_on=sku`

#### Request Body

JSON array of objects.

```json
[
  {
    "sku": "PROD-001",
    "name": "Wireless Mouse",
    "price": 29.99
  },
  {
    "sku": "PROD-002",
    "name": "Mechanical Keyboard",
    "price": 89.99
  }
]
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "count": 2
  },
  "meta": {
    "request_id": "req_abc123def456",
    "total": 2
  }
}
```

### Audit Management

#### Audit Formatting

Audit title, subject, and recap labels are configurable via `config/audit.php`:

- `audit.subject_fields`: Ordered list of fields used as the subject (empty list yields blank subject).
- `audit.entity_labels`: Per-entity label overrides (falls back to auto-generated labels).
- `audit.recap_entities`: Entities that use the detailed recap formatter.
- `audit.main_field_labels`: Field label map used by recap output.
- `audit.recap_max_fields`: Limits generic recap length and appends “and N more”.
- `audit.log_relationships`: Includes relationship snapshots in audit data when enabled.

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
  "error_code": 0,
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
  "error_code": 0,
  "data": {
    "total_logs": 45,
    "actions_breakdown": {
      "created": 10,
      "updated": 30,
      "deleted": 5
    },
    "top_users": [{ "user_name": "John Admin", "count": 20 }],
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
  "error_code": 0,
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
  "error_code": 0,
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

Custom functions (RPC endpoints) let you expose arbitrary logic under `/{api_prefix}/rpc/{name}` (global) or `/{api_prefix}/{table}/rpc/{name}` (table-scoped). They are configured via `RecordFunctionType` in `record.global_functions` or `RecordTableType::$functions`.

> **Tip — generate OpenAPI schemas for custom functions:**
> The package cannot auto-infer request/response shapes for custom functions. Use **[wk-tools.vercel.app/json-to-openapi](https://wk-tools.vercel.app/json-to-openapi)** to convert a sample JSON payload/response into an OpenAPI `schema` object, then attach it to `RecordFunctionType::$requestSchema` / `$responseSchema`. The exported schema and live `/docs/openapi.json` will include it automatically.

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

### Function Caching

Caching is only applied for `GET` requests and when `record.cache.enabled` is true.

**Table functions**

- Caching is enabled by default (`disableCache: false`).
- Table cache can be disabled globally for a table using `record.cache.per_table[table] = false`.
- Default TTL uses `record.cache.per_table_ttl[table]` when set; otherwise `record.cache.ttl`.
- Set `cacheTTL` in the function config to override the computed TTL for this function.
- For write methods (`POST`, `PUT`, `PATCH`, `DELETE`), table function cache for the executed function is invalidated automatically.
- For write methods (`POST`, `PUT`, `PATCH`, `DELETE`), table functions automatically clear cache for the current table after a successful response. Use `clearCacheTables` to clear additional tables.

**Global functions**

- Caching is enabled by default (`disableCache: false`).
- Default TTL uses `record.cache.ttl`.
- Set `cacheTTL` in the function config to override the default TTL for this function.
- For write methods (`POST`, `PUT`, `PATCH`, `DELETE`), global function cache for the executed function is invalidated automatically.
- Global functions can additionally clear table caches by setting `clearCacheTables`.

**Manual cache clear**

You can clear a table cache manually from any controller, job, or command:

```php
use Sopheak\Core\Services\RecordCacheService;

$service = app(RecordCacheService::class);
$service->clearTableCache('settings', $tenantId);
$service->clearCacheForTables(['settings', 'users'], $tenantId);
```

**Controller cache example**

```php
use Illuminate\Http\Request;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordCacheService;
use Sopheak\Core\Services\RecordService;

public function list(Request $request)
{
    $filters = $request->query();
    $cacheKey = QueryCacheService::tableKey('settings', $filters, [], 1, 50);

    return QueryCacheService::remember($cacheKey, function () use ($request) {
        return app(RecordService::class)->listRecords($request, 'settings');
    }, 600);
}

public function update(Request $request, RecordCacheService $cacheService)
{
    app(RecordService::class)->updateRecord($request, 'settings', 1);
    $tenantId = $request->header('X-Tenant-Id');
    $cacheService->clearTableCache('settings', $tenantId);

    return response()->json(['ok' => true]);
}
```

### Broadcast Events (Real-Time Mutations)

When `record.broadcast_events` is enabled, the package fires a `RecordMutated` event over Laravel's broadcasting system after every successful mutation (create, update, upsert, delete, restore, force-delete, bulk).

#### Enabling Broadcasting

```php
// config/record.php
'broadcast_events' => env('SP_BROADCAST_EVENTS', false),

// Optional: restrict to specific tables (empty = all tables)
'broadcast_tables' => ['invoices', 'payments'],
```

Set `SP_BROADCAST_EVENTS=true` in your `.env`. Configure your broadcast driver (`BROADCAST_DRIVER`) as usual — Pusher, Soketi, Reverb, etc.

#### Event Details

| Property | Value |
|---|---|
| Class | `Sopheak\Core\Events\RecordMutated` |
| Interface | `Illuminate\Contracts\Broadcasting\ShouldBroadcast` |
| Channel | `private-tenant.{tenantId}` (falls back to `private-tenant.global`) |
| Event name | `{table}.{action}` — e.g. `invoices.created`, `payments.deleted` |

#### Broadcast Payload

```json
{
  "table": "invoices",
  "action": "created",
  "record": { "id": 42, "status": "draft", ... },
  "tenant_id": "tenant_abc",
  "timestamp": "2026-03-29T10:00:00.000000Z"
}
```

`action` is one of: `created`, `updated`, `upserted`, `deleted`, `restored`, `force_deleted`.

#### Per-Table Opt-Out

```php
new RecordTableType(
    table: 'audit_snapshots',
    disableBroadcast: true,  // never broadcast this table
);
```

#### Authorization

Private channels use standard Laravel channel authorization. Register the channel in `routes/channels.php`:

```php
Broadcast::channel('tenant.{tenantId}', function ($user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});
```

> **Note:** Broadcasting failures are silently swallowed so they never break the HTTP response.

---

### OpenAPI Export Command

Export the package-generated OpenAPI 3.0 schema to a local file.

```bash
php artisan sp-laravel-api:export-openapi
```

#### Options

| Option | Default | Description |
|---|---|---|
| `--output` | `openapi-schema.json` (from `sp-laravel-api.openapi.output`) | Output file path (relative to project root) |
| `--format` | `json` | Output format: `json` or `yaml` |
| `--pretty` | `false` | Pretty-print JSON output |

#### Examples

```bash
# Export as JSON
php artisan sp-laravel-api:export-openapi

# Export as pretty-printed JSON
php artisan sp-laravel-api:export-openapi --pretty

# Export as YAML to a custom path
php artisan sp-laravel-api:export-openapi --format=yaml --output=docs/openapi.yaml

# JSON to a specific path
php artisan sp-laravel-api:export-openapi --output=public/api-schema.json --pretty
```

The default output path is configurable via `config/sp-laravel-api.php`:

```php
'openapi' => [
    'output' => 'openapi-schema.json',
],
```

The command uses the same `OpenApiService::generateInternal()` that powers the runtime `/docs/openapi.json` endpoint, so the exported file is always consistent with the live API schema.

#### Documenting Custom Function Endpoints

The auto-generated schema covers all dynamic CRUD and bulk routes, but **custom RPC functions** (`RecordFunctionType`) require you to provide the request/response schema manually. Use the **JSON to OpenAPI** tool to generate the schema from a sample JSON payload or response:

👉 **[wk-tools.vercel.app/json-to-openapi](https://wk-tools.vercel.app/json-to-openapi)**

Workflow:

1. Run your custom function and capture the JSON request body and response.
2. Paste each into the tool — it generates an OpenAPI-compatible `schema` object.
3. Use the output in `RecordFunctionType::$requestSchema` and `$responseSchema` to annotate your function config.

```php
use Sopheak\Core\Types\RecordFunctionType;

'functions' => [
    'calculate_totals' => new RecordFunctionType(
        handler: CalculateTotalsFunction::class,
        // Paste the schema generated by wk-tools here:
        requestSchema: [
            'type' => 'object',
            'properties' => [
                'invoice_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'currency'    => ['type' => 'string', 'example' => 'USD'],
            ],
            'required' => ['invoice_ids'],
        ],
        responseSchema: [
            'type' => 'object',
            'properties' => [
                'total'    => ['type' => 'number'],
                'currency' => ['type' => 'string'],
            ],
        ],
    ),
],
```

Once set, the schema appears in the exported OpenAPI file and in the live `/docs/openapi.json` endpoint automatically.

---

### PHP 8.3 Attribute-Based Config

As an alternative to file-based `RecordTableType` configuration, you can annotate Eloquent models directly with PHP 8 attributes. This keeps table configuration co-located with the model class.

#### Enabling Discovery

```php
// config/sp-laravel-api.php
'attribute_discovery' => [
    'enabled' => env('SP_ATTRIBUTE_DISCOVERY', false),
    'paths'   => ['app/Models'],
],
```

Set `SP_ATTRIBUTE_DISCOVERY=true` in your `.env`.

> File-based config (config/record.php and config/records/tables/) **always takes precedence** over attribute-discovered tables. Attribute discovery only fills in tables that have no file-based entry.

#### `#[RecordTable]` Attribute

```php
use Sopheak\Core\Attributes\RecordTable;
use Sopheak\Core\Attributes\RecordRelationship;

#[RecordTable(
    pmsName: 'invoice',
    table: 'invoices',
    hasTenantId: true,
    softDeletes: true,
    isAuthRead: true,
    isAuthWrite: true,
    canRead: true,
    canCreate: true,
    canUpdate: true,
    canDelete: true,
    canUpsert: true,
    disableAuditLog: false,
    disableCache: false,
    disableBroadcast: false,
)]
#[RecordRelationship(
    name: 'customer',
    type: 'belongs_to',
    foreignKey: 'customer_id',
    relatedTable: 'customers',
)]
#[RecordRelationship(
    name: 'items',
    type: 'has_many',
    foreignKey: 'invoice_id',
    relatedTable: 'invoice_items',
)]
class Invoice extends Model
{
    // ...
}
```

All parameters from `RecordTableType` are available as named arguments on `#[RecordTable]`. `#[RecordRelationship]` is repeatable and maps to the relationship types supported by the package.

#### Listing Discovered Tables

```bash
# All tables (file-based + attribute-discovered)
php artisan sp-laravel-api:list-tables

# Only file-based tables
php artisan sp-laravel-api:list-tables --source=file

# Only attribute-discovered tables
php artisan sp-laravel-api:list-tables --source=attributes
```

Output columns: `Key`, `Table`, `PMS Name`, `Auth R/W`, `Soft Del`, `Tenant`, `Source`, `Note` (where `Note` shows `⚠ overridden by file` for attribute tables that are shadowed by a file-based entry).

---

### Error Responses

#### Standard Success Format

```json
{
  "success": true,
  "error_code": 0,
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
  "error_code": 1000,
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

`error_code` is a stable, machine-friendly code that complements the HTTP status:

- On success responses it is always the success code.
- On error responses it is derived from the HTTP status (unauthorized, forbidden, not found, validation, server error, etc.), unless a downstream handler explicitly sets `error_code` in its JSON body, in which case that value is preserved.

#### Error Codes

The package uses a fixed set of numeric `error_code` values to make client-side handling and analytics easier. These codes are stable across versions and map to logical error categories:

- `0` – **SUCCESS**: Request completed successfully.
- `10000` – **GENERAL_ERROR**: Generic error when no more specific category applies.
- `10001` – **INVALID_TENANT_ID**: Tenant identifier is missing, malformed, or does not match the current context.
- `10002` – **INVALID_ACCESS**: Unauthorized access (typically HTTP 401) – missing or invalid authentication for the requested resource.
- `10003` – **INVALID_TOKEN**: Authentication token is invalid (bad signature, malformed, or otherwise unusable).
- `10004` – **INVALID_REQUEST**: Request payload or query parameters are invalid (commonly used for validation errors / HTTP 422).
- `10005` – **INVALID_RESOURCE**: Reference to an invalid resource (e.g. invalid foreign key or unsupported table/endpoint).
- `10006` – **INVALID_PERMISSION**: Permission configuration is invalid or inconsistent.
- `10007` – **INVALID_CREDENTIAL**: User credentials are incorrect (login/auth failures).
- `10008` – **PERMISSION_DENIED**: Authenticated user is forbidden from performing this action (typically HTTP 403).
- `10009` – **RESOURCE_NOT_FOUND**: Requested resource cannot be found (table, record, or function – typically HTTP 404).
- `10010` – **INTERNAL_SERVER_ERROR**: Unhandled server-side error (HTTP 500).
- `10011` – **UNKNOWN_ERROR**: Error that cannot be mapped to a known category.
- `10012` – **TENANT_NOT_FOUND**: Tenant does not exist or is not registered.
- `10013` – **TENANT_DISABLED**: Tenant exists but is disabled / suspended.
- `10014` – **NO_TENANT_PMS_ACCESS**: Current user/application has no PMS access for this tenant.
- `10015` – **TOKEN_EXPIRED**: Authentication token is valid but expired.

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

You can also use named arguments when calling the scope (PHP 8+):

```php
$result = Invoice::query()->applyRequestFilters(
    request: $request,
    isArray: true,
    orderBy: 'created_at',
);
```

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
