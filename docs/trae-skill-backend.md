---
name: sp-laravel-api
description: Use when working on a Laravel project that uses sopheak/sp-laravel-api — creating new modules/tables, configuring RecordTableType, relationships, RPC functions, validators, triggers, audit logging, or understanding the package architecture.
---

# Agent Context: sopheak/sp-laravel-api (Backend / Laravel)

## What This Package Is

`sopheak/sp-laravel-api` is a config-driven REST API layer for Laravel.
It auto-generates CRUD, bulk, upsert, RPC, and audit endpoints from PHP configuration objects.
No per-table controllers or routes are written by hand.

## How It Works

1. Tables are declared as `RecordTableType` objects in `config/record.php`.
2. The service provider reads this config and registers all routes automatically.
3. HTTP requests flow through `CoreRecordController` → `RecordService` → hook callbacks.
4. All responses are wrapped by `RecordApiResponseService` into a standard envelope.

## Live Discovery

Always fetch `GET /api/v1/docs/openapi.json` to discover registered tables, columns,
relationships, and functions at runtime. Do not assume or hardcode names.

---

## Architecture Philosophy

### The single principle: Config is the source of truth

Everything flows from `RecordTableType` in `config/record.php`.
One config object per table gives you a complete, secured, documented API with zero boilerplate.

```
RecordTableType
  ├─ CRUD endpoints      auto-registered
  ├─ bulk / upsert       auto-registered
  ├─ RPC functions       auto-registered
  ├─ relationships       auto-embedded
  ├─ validators          on create / update / delete
  ├─ lifecycle hooks     business logic layer
  ├─ permissions         Spatie guard
  ├─ audit trail         automatic
  ├─ caching             automatic
  └─ multi-tenancy       automatic
```

---

## Architecture Rules

| Task | Use this | Never do this |
|------|----------|---------------|
| Expose a DB table as API | `RecordTableType` in config | Manual controller + route |
| Validate create / update | `createValidator` / `updateValidator` in config | Form Request, middleware |
| Add business logic before/after write | `beforeCreate` / `afterCreate` / `beforeUpdate` / `afterUpdate` hooks | Override controller methods |
| Add a custom action (non-CRUD) | `RecordFunctionType` (RPC) in config | Standalone controller route |
| Build a list endpoint (custom query) | `RecordService::applyRequestFilters()` or Builder macro | Manual pagination + response |
| Return a JSON response | `RecordApiResponseService` static methods | `response()->json()` |
| Queries inside controllers / actions | `DB::table()` (query builder) | Eloquent model queries |
| Guard an endpoint | `pmsName` on config | Route middleware `can:` |
| Multi-tenant isolation | `hasTenantId: true` | Manual `where('tenant_id', ...)` |
| Hide sensitive columns | `columnHiddens: [...]` | `makeHidden()` or manual unset |

---

## When Asked to Create a New Module or Table

Generate only the artifacts listed. Nothing else.

### What to generate

| Artifact | Generate? | Notes |
|----------|-----------|-------|
| Database migration | **Yes** | Standard Laravel `Schema::create` |
| Eloquent Model | **Yes (minimal)** | Only for `AuditableTrait` — no scopes, no queries |
| `RecordTableType` config | **Yes** | `app/Api/Tables/` (class-based) or inline in config |
| Controller | **No** | Package registers all CRUD routes automatically |
| Route | **No** | Package registers all routes automatically |
| Service class | **No** | Use lifecycle hooks instead |
| Form Request | **No** | Use `createValidator` / `updateValidator` in config |

### Step-by-step

**1. Migration**
```php
// database/migrations/YYYY_MM_DD_create_products_table.php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->decimal('price', 10, 2);
    $table->softDeletes();   // if softDeletes: true in config
    $table->timestamps();
});
```

**2. Model** — minimal, no query logic
```php
// app/Models/Product.php
class Product extends Model
{
    use \Sopheak\Core\Traits\AuditableTrait; // only if model-event audit needed
    protected $table    = 'products';
    protected $fillable = ['name', 'price'];
}
// Do NOT add: scopes, relationships, accessors, or business logic.
// All queries go through DB::table() inside the package pipeline.
```

**3. RecordTableType** — class-based preferred
```php
// app/Api/Tables/ProductTable.php
class ProductTable extends \Sopheak\Core\Resources\RecordResource
{
    public function configure(): RecordTableType
    {
        return new RecordTableType(
            table:        'products',
            softDeletes:  true,
            hasTenantId:  true,
            columnHiddens:  ['cost_price'],
            columnIndexes:  ['name'],
            createValidator: fn($req, $id) => Validator::make($req->all(), [
                'name'  => 'required|string|max:255',
                'price' => 'required|numeric|min:0',
            ]),
            updateValidator: fn($req, $id) => Validator::make($req->all(), [
                'price' => 'sometimes|numeric|min:0',
            ]),
            relationships: [
                new RecordBelongsToType(
                    name: 'category', foreignTable: 'categories',
                    foreignKey: 'category_id', localKey: 'id',
                ),
            ],
        );
    }
}
```

**4. Register** in `config/record.php`
```php
'tables' => [
    'products' => \App\Api\Tables\ProductTable::class,
],
```

Done. All CRUD + bulk + upsert endpoints are live. No controller, no route.

---

## When a Controller IS Required

Only for: custom reporting endpoints, global RPC functions, or table RPC functions.

### Mandatory rules inside any controller or action class

```
Query data   → DB::table()                          — never Eloquent model
List result  → RecordService::applyRequestFilters() — all list/search endpoints
Single row   → DB::table('{table}')->find($id)
Response     → RecordApiResponseService::{method}() — never response()->json()
Registration → RecordFunctionType in config          — never a standalone Route::
```

```php
// List: query builder + applyRequestFilters
$result = RecordService::applyRequestFilters(
    request:        $request,
    tableOrBuilder: DB::table('orders')->where('status', 'paid'),
    isArray:        true,
    orderBy:        'created_at',
);
return RecordApiResponseService::successWrapped(
    data: $result['data'], meta: $result['meta'], headers: $result['headers'],
);

// Single record
$record = DB::table('products')->find($id);
if (! $record) throw new RecordNotFoundException();
return RecordApiResponseService::successWrapped(data: $record);

// Write
$id = DB::table('products')->insertGetId($data);
return RecordApiResponseService::created(DB::table('products')->find($id));
```

---

## Config File Layout

```
config/
  record.php              master config — table registry + global functions
  records/tables/         optional per-table class files (lazy-loaded)
  audit.php               audit logging settings
  sp-laravel-api.php      auth guard, OpenAPI output path
```

`config/record.php` top-level keys:

```php
return [
    'tables' => [
        'products'   => \App\Api\Tables\ProductTable::class,  // class-based (lazy)
        'categories' => new RecordTableType(...),              // inline
    ],
    'global_functions' => [
        'health-check' => \App\Api\Functions\HealthCheckFunction::class,
        'auth/login'   => \App\Api\Functions\Auth\LoginFunction::class,
    ],
];
```

---

## RecordTableType — Full Property Reference

```php
new RecordTableType(
    // Identity
    table:               'products',   // database table name (required)
    primaryKey:          'id',         // PK column, default: 'id'
    pmsName:             'products',   // Spatie permission prefix; auto-derived if omitted

    // Endpoint control
    canRead:             true,         // expose GET endpoints
    canCreate:           true,         // expose POST
    canUpdate:           true,         // expose PUT / PATCH
    canDelete:           true,         // expose DELETE / restore / force

    // Public access — bypasses authentication
    public:              ['read'],     // 'read' | 'write'; omit = all endpoints require auth

    // Features
    softDeletes:         true,         // enables soft-delete, restore, force-delete
    hasTenantId:         true,         // auto-filters all queries by X-Tenant-ID header
    disableCache:        false,        // skip query caching for this table
    disableAuditLog:     false,        // skip audit trail for this table

    // Column control
    columnHiddens:       ['cost_price'],          // never returned in responses
    columnWriteDisabled: ['sku'],                 // silently ignored on create/update
    columnIndexes:       ['name','description'],  // included in full-text search

    // Composed config (detailed below)
    relationships:       [...],
    functions:           [...],
    createValidator:     fn(Request $request, ?int $id): Validator => ...,
    updateValidator:     fn(Request $request, ?int $id): Validator => ...,
    deleteValidator:     fn(Request $request, ?int $id): Validator => ...,
    beforeRead:          fn(Request $request, string $table): Request|null => ...,
    afterRead:           fn(Request $request, string $table, mixed $results): void => ...,
    beforeCreate:        fn(Request $request, string $table): Request|array|null => ...,
    afterCreate:         fn(Request $request, string $table, mixed $record): void => ...,
    beforeUpdate:        fn(Request $request, string $table, mixed $record): Request|array|null => ...,
    afterUpdate:         fn(Request $request, string $table, mixed $record): void => ...,
    beforeDelete:        fn(Request $request, string $table, mixed $record): Request|array|null => ...,
    afterDelete:         fn(Request $request, string $table, mixed $record): void => ...,
    customAuditLog:      fn($event, $entityClass, array $data, $tenantId, $ctx): void => ...,
);
```

---

## Class-Based Table Config

```php
class ProductTable extends \Sopheak\Core\Resources\RecordResource
{
    public function configure(): RecordTableType
    {
        return new RecordTableType(table: 'products', ...);
    }
}
```

Class-based configs are lazy-loaded — only instantiated when that table is accessed.

---

## Relationship Types

### BelongsTo
```php
new RecordBelongsToType(
    name: 'category', foreignTable: 'categories',
    foreignKey: 'category_id', localKey: 'id',
)
```
Write: set FK in root payload — `{ "category_id": 3 }`.

### HasMany
```php
new RecordHasManyType(
    name: 'variants', foreignTable: 'product_variants',
    foreignKey: 'product_id', localKey: 'id',
)
```

### HasOne
```php
new RecordHasManyType(
    name: 'thumbnail', foreignTable: 'product_images',
    foreignKey: 'product_id', localKey: 'id',
    type: RecordRelationshipsEnum::HAS_ONE,
)
```

### BelongsToMany
```php
new RecordMetaBelongsToManyType(
    name: 'tags', foreignTable: 'tags', pivotTable: 'product_tags',
    foreignPivotKey: 'product_id', relatedPivotKey: 'tag_id',
    pivotColumns: ['order'], withTimestamps: true,
)
```

### HasManyThrough
```php
new RecordHasManyThroughType(
    name: 'reviews', foreignTable: 'reviews', throughTable: 'order_items',
    firstKey: 'product_id', secondKey: 'order_item_id',
    localKey: 'id', secondLocalKey: 'id',
)
```

### Morph
```php
new RecordHasManyType(
    name: 'comments', foreignTable: 'comments',
    foreignKey: 'commentable_id', localKey: 'id',
    type: RecordRelationshipsEnum::MORPH_MANY,
    morphType: 'commentable_type', morphClass: 'App\\Models\\Product',
)
```

### Spatie Permission
```php
new RecordSpatiePermissionType(name: 'roles', type: 'roles', guardName: 'api')
```

### Relationship Write Payload Summary

| Relationship   | Payload shape                 | Remove child            |
|----------------|-------------------------------|-------------------------|
| BelongsTo      | `"fk_col": id` in root        | —                       |
| HasMany        | `"rel": [id, {id,...}, {...}]` | `{id, "_delete": true}` |
| HasOne         | `"rel": {fields}`             | `{"_delete": true}`     |
| BelongsToMany  | `"rel": [id, {id, pivot:{}}]` | `{id, "_delete": true}` |
| HasManyThrough | `"rel": [id, {id}]`           | `{id, "_delete": true}` |
| MorphMany      | `"rel": [{data}]`             | `{id, "_delete": true}` |
| MorphToMany    | `"rel": [id, {id}]`           | `{id, "_delete": true}` |
| MorphTo        | type + id in root payload     | —                       |
| Spatie         | `"rel": [id, name, {id}]`     | `{id, "_delete": true}` |

---

## Validators

Signature: `fn(Request $request, ?int $id): \Illuminate\Validation\Validator`
Bulk operations bypass validators.

```php
createValidator: fn($request, $id) => Validator::make($request->all(), [
    'name'  => 'required|string|max:255',
    'sku'   => 'required|string|unique:products,sku',
    'price' => 'required|numeric|min:0',
]),
updateValidator: fn($request, $id) => Validator::make($request->all(), [
    'sku'   => "sometimes|string|unique:products,sku,{$id}",
    'price' => 'sometimes|numeric|min:0',
]),
```

---

## Lifecycle Triggers

| Hook           | Fires             | Extra args | Can return             |
|----------------|-------------------|------------|------------------------|
| `beforeRead`   | Before list/get   | —          | `Request\|null`        |
| `afterRead`    | After list/get    | `$results` | `void`                 |
| `beforeCreate` | Before INSERT     | —          | `Request\|array\|null` |
| `afterCreate`  | After INSERT      | `$record`  | `void`                 |
| `beforeUpdate` | Before UPDATE     | `$record`  | `Request\|array\|null` |
| `afterUpdate`  | After UPDATE      | `$record`  | `void`                 |
| `beforeDelete`  | Before DELETE     | `$record`  | `Request\|array\|null` |
| `afterDelete`   | After DELETE      | `$record`  | `void`                 |
| `beforeRestore` | Before RESTORE    | `$record`  | `Request\|array\|null` |
| `afterRestore`  | After RESTORE     | `$record`  | `void`                 |

---

## RPC Functions — Config (RecordFunctionType)

```php
new RecordFunctionType(
    httpMethod:       ['POST'],           // array — GET | POST | PUT | PATCH | DELETE
    class:            PublishProductAction::class,
    functionName:     'handle',           // method name to call on the class
    pmsName:          'products.publish', // Spatie permission; null = public
    disableCache:     true,
    cacheTTL:         3600,               // seconds; GET requests only
    clearCacheTables: ['products'],       // tables to invalidate after write
    description:      'Publish a product',
    querySchema:      [...],              // optional OpenAPI query param schema
    payloadSchema:    [...],              // optional OpenAPI request body schema
    responseSchema:   [...],             // optional OpenAPI response schema
)
```

- Global function registered as `auth/login` → endpoint: `POST /api/v1/rpc/auth/login`
- Table function `publish` on `products` → endpoint: `POST /api/v1/products/rpc/publish`

---

## RPC Action Class — Return Types & Response

The action method receives `Request $request` and returns either an **array** (auto-wrapped)
or a **JsonResponse** (passed through as-is).

### Option A — return array (recommended, auto-wrapped into standard envelope)

```php
use Illuminate\Http\Request;
use Sopheak\Core\Exceptions\RecordNotFoundException;

class PublishProductAction
{
    public function handle(Request $request): array
    {
        $id = $request->route('id'); // available for table-scoped functions

        $product = DB::table('products')->find($id);
        if (! $product) throw new RecordNotFoundException();

        DB::table('products')->where('id', $id)->update(['status' => 'published']);

        return ['published' => true, 'id' => $id];
        // Auto-wrapped → { "success": true, "error_code": 0, "data": {...}, "meta": {...} }
    }
}
```

### Option B — return JsonResponse (full control)

```php
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sopheak\Core\Services\RecordApiResponseService;

class LoginAction
{
    public function handle(Request $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! $token = auth()->attempt($credentials)) {
            return RecordApiResponseService::unauthorized('Invalid credentials');
        }

        return RecordApiResponseService::successWrapped(
            data: ['token' => $token, 'type' => 'bearer'],
            meta: ['expires_in' => 3600],
        );
    }
}
```

---

## RecordApiResponseService — Full Method Reference

All methods are **static**. `use Sopheak\Core\Services\RecordApiResponseService;`

### Wrapped responses (standard envelope)

```php
RecordApiResponseService::successWrapped(
    data:       $data,   // mixed
    meta:       [],      // array — merged into meta
    status:     200,     // HTTP status
    headers:    [],      // extra HTTP headers
    error_code: null,    // override error_code (default 0)
): JsonResponse

RecordApiResponseService::errorWrapped(
    message:    'Something failed',
    status:     500,
    errors:     [],      // ['field' => ['message']]
    error_code: null,
): JsonResponse
```

### Shorthand responses

```php
RecordApiResponseService::success($data)           // 200, error_code 0
RecordApiResponseService::created($data)           // 201, error_code 0
RecordApiResponseService::updated($data)           // 200, error_code 0
RecordApiResponseService::deleted($data)           // 200, error_code 0
RecordApiResponseService::notFound($message)       // 404, error_code 10009
RecordApiResponseService::unauthorized($message)   // 401, error_code 10002
RecordApiResponseService::forbidden($message)      // 403, error_code 10008
RecordApiResponseService::validationError($errors) // 422, error_code 10004
RecordApiResponseService::serverError($message)    // 500, error_code 10010
```

### Data helpers

```php
RecordApiResponseService::removeHiddenFields($data, $table): mixed
RecordApiResponseService::removeDeletedAtFields($data): mixed
```

---

## applyRequestFilters — Full Signature

```php
RecordService::applyRequestFilters(
    request:        Request $request,
    tableOrBuilder: Builder|RecordTableType|string,  // Builder, type, or table name
    tanentColumn:   ?string = '',    // tenant column if filtering manually
    isArray:        bool    = true,  // true = array; false = collection
    orderBy:        string  = 'id',  // default sort when ?sortby not in request
): array  // keys: data, meta, headers, filters, request
```

Via Builder macro (same result):

```php
DB::table('orders')
    ->where('status', 'paid')
    ->applyRequestFilters($request, isArray: true, orderBy: 'created_at');
```

---

## Audit Logging

```php
// config/audit.php
'enabled'           => true,
'queue_enabled'     => true,
'subject_fields'    => ['name', 'title', 'email'],
'entity_labels'     => ['products' => 'Product'],
'recap_max_fields'  => 5,

// Per-table in RecordTableType:
disableAuditLog: false,
customAuditLog: fn($event, $entityClass, $data, $tenantId, $ctx) => ...,
```

---

## Standard Response

```
success:    bool
error_code: int      0 = ok
data:       object | array
meta:       { request_id, page, per_page, total }
message:    string   errors only
errors:     { field: [string] }  validation only
```

---

## Artisan Commands

```
sp-laravel-api:record {table}         scaffold table config
sp-laravel-api:sync-record-columns    sync DB schema → column metadata
sp-laravel-api:setup                  publish configs and migrations
sp-laravel-api:validate-setup --fix   validate, auto-repair
sp-laravel-api:clean-audit-logs       purge expired audit records
```

---

## Environment

```
SP_LARAVEL_API_AUTH_GUARD=api    # api | web | sanctum | jwt
```

---

## Project Conventions

| Path                    | Purpose                         |
|-------------------------|---------------------------------|
| `config/record.php`     | Table and function registry     |
| `config/records/tables/`| Per-table class-based configs   |
| `app/Api/Tables/`       | RecordResource subclasses       |
| `app/Api/Functions/`    | GlobalFunction subclasses       |
| `app/Actions/`          | RPC action classes              |
