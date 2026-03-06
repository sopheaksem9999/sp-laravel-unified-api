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

## Architecture Philosophy (Read This First)

### The single principle: Config is the source of truth

Everything flows from `RecordTableType` declared in `config/record.php`.
One config object per table gives you a complete, secured, documented API with zero boilerplate.

```
config/record.php
  └─ RecordTableType         ← declare a table here
       ├─ CRUD endpoints      auto-registered
       ├─ bulk / upsert       auto-registered
       ├─ RPC functions       auto-registered
       ├─ relationships       auto-embedded in responses
       ├─ validators          run on create / update / delete
       ├─ lifecycle hooks     business logic layer
       ├─ permissions         Spatie permission guard
       ├─ audit trail         automatic
       ├─ caching             automatic
       └─ multi-tenancy       automatic
```

The goal: adding a new API resource means adding one `RecordTableType` — never a controller, a route, or a model.

---

## Architecture Rules — Follow These Always

| Task | Use this | Never do this |
|------|----------|---------------|
| Expose a DB table as API | `RecordTableType` in `config/record.php` | Manual controller + route |
| Validate create / update / delete | `createValidator` / `updateValidator` / `deleteValidator` in config | Form Request, middleware, or manual `$request->validate()` |
| Add business logic before/after write | `beforeCreate` / `afterCreate` / `beforeUpdate` / `afterUpdate` hooks | Override controller methods |
| Add a custom action (non-CRUD) | `RecordFunctionType` (RPC) in `functions:` or `global_functions` | A dedicated controller route |
| Build a list endpoint (custom query) | `RecordService::applyRequestFilters()` or Builder macro | Manual pagination + response shaping |
| Return a JSON response | `RecordApiResponseService` static methods | `response()->json()` directly |
| Guard an endpoint by permission | `pmsName` on `RecordTableType` or `RecordFunctionType` | Route middleware `can:` or manual `Gate::check()` |
| Multi-tenant isolation | `hasTenantId: true` on `RecordTableType` | Manual `where('tenant_id', ...)` in hooks |
| Soft-delete + restore + force-delete | `softDeletes: true` on `RecordTableType` | Custom delete logic |
| Hide sensitive columns | `columnHiddens: [...]` in config | `makeHidden()` on model or manual unset |
| Protect columns from being written | `columnWriteDisabled: [...]` in config | Unset fields in hooks or middleware |

---

## When Asked to Create a New Module or Table

This is the exact scaffolding pattern. Generate only what is listed here.

### What to generate

| Artifact | Generate? | Notes |
|----------|-----------|-------|
| Database migration | **Yes** | Standard Laravel `Schema::create` |
| Eloquent Model | **Yes (minimal)** | Only for `AuditableTrait` — no scopes, no queries, no business logic |
| `RecordTableType` config | **Yes** | In `app/Api/Tables/` (class-based) or inline in `config/record.php` |
| Controller | **No** | Package registers all CRUD routes automatically |
| Route | **No** | Package registers all routes automatically |
| Service class | **No** | Use lifecycle hooks in `RecordTableType` instead |
| Form Request | **No** | Use `createValidator` / `updateValidator` in config |
| API Resource class | **No** | Package handles all response shaping |

### Step-by-step

**1. Migration** — standard Laravel:
```php
// database/migrations/YYYY_MM_DD_create_products_table.php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->decimal('price', 10, 2);
    $table->softDeletes();   // add if softDeletes: true in config
    $table->timestamps();
});
```

**2. Model** — minimal, no query logic:
```php
// app/Models/Product.php
class Product extends Model
{
    use \Sopheak\Core\Traits\AuditableTrait; // only if model-event audit is needed
    protected $table    = 'products';
    protected $fillable = ['name', 'price'];
}
// Do NOT add: query scopes, relationships, business logic, or accessors.
// All queries go through DB::table() inside the package pipeline, not Eloquent.
```

**3. RecordTableType** — class-based (preferred for any non-trivial table):
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

**4. Register** in `config/record.php`:
```php
'tables' => [
    'products' => \App\Api\Tables\ProductTable::class,
],
```

Done. All CRUD + bulk + upsert endpoints are live. No controller, no route.

---

## When a Controller IS Required

Only create a controller for:
- Custom reporting / aggregation not achievable through config
- Global RPC functions (registered in `global_functions`)
- Table RPC functions (registered in `RecordTableType::functions`)

### Mandatory rules inside any controller or action class

```
Query data   → DB::table()                          — never Eloquent model
List result  → RecordService::applyRequestFilters() — for all list/search endpoints
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
    data:    $result['data'],
    meta:    $result['meta'],
    headers: $result['headers'],
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

## Powerful Features

### 1. Zero-code full API from config

One `RecordTableType` generates 12+ endpoints automatically:

```
GET    /api/v1/{table}                  list with filtering, sorting, pagination, aggregation
GET    /api/v1/{table}/{id}             get one
POST   /api/v1/{table}                  create
PUT    /api/v1/{table}/{id}             full update
PATCH  /api/v1/{table}/{id}             partial update
DELETE /api/v1/{table}/{id}             soft delete
POST   /api/v1/{table}/{id}/restore     restore soft-deleted
DELETE /api/v1/{table}/{id}/force       permanent delete
POST   /api/v1/{table}/upsert           upsert single
POST   /api/v1/{table}/bulk/create      bulk create
POST   /api/v1/{table}/bulk/update      bulk update
POST   /api/v1/{table}/bulk/delete      bulk delete
POST   /api/v1/{table}/bulk/upsert      bulk upsert
```

### 2. Nested relationship writes in one request

Create or update a parent and all its children in one POST/PUT/PATCH.
No separate child endpoint calls needed.

```json
POST /api/v1/orders
{
  "customer_id": 42,
  "items": [
    { "product_id": 10, "qty": 2, "price": 29.99 },
    { "id": 5, "qty": 3 },
    { "id": 99, "_delete": true }
  ],
  "tags": [1, { "id": 3, "pivot": { "order": 1 } }]
}
```

### 3. Advanced query on every list endpoint — zero code

Every `GET /{table}` supports out of the box:

```
?select=*,category(id,name),variants(*)   column selection + relationship embedding
?s=search term                             full-text search across indexed columns
?status=eq.active&price=gte.100           filter operators (20+ types)
?sortby=price&order=desc                   sorting
?page=2&per_page=25                        pagination
?aggregate=count,sum:price&group_by=status aggregation + group by
?with=invoices,contacts                    legacy eager load
```

### 4. Automatic query caching

Queries are cached automatically per table. Invalidated on any write to that table.
Disable per table: `disableCache: true`.
RPC functions control cache TTL via `cacheTTL` and invalidation via `clearCacheTables`.

### 5. Zero-code multi-tenancy

Set `hasTenantId: true` on any table. Every query is then automatically scoped to the
`X-Tenant-ID` header value — reads, writes, deletes. No code change needed.

### 6. Auto-generated OpenAPI always in sync

`GET /api/v1/docs/openapi.json` reflects the live config: tables, columns, relationships,
RPC functions, query schemas, response schemas. No manual API doc maintenance.

### 7. Lifecycle hooks as business logic layer

Hooks run at every stage of the request. Use them for:
- Injecting computed fields before insert (`beforeCreate`)
- Sending notifications after write (`afterCreate`, `afterUpdate`)
- Blocking operations on business rules (`beforeDelete`)
- Invalidating downstream caches (`afterUpdate`)

### 8. Comprehensive audit trail — automatic

Every CRUD operation is logged: who, what, when, before/after state, recap of changed fields.
Accessible via `GET /api/v1/audit/logs`, field timeline, and stats endpoints.
Override per record with `customAuditLog`. Disable per table with `disableAuditLog: true`.

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
    columnHiddens:       ['cost_price'],       // never returned in responses
    columnWriteDisabled: ['sku'],              // silently ignored on create/update
    columnIndexes:       ['name','description'], // included in full-text search

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
// app/Api/Tables/ProductTable.php
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

All types are in `Sopheak\Core\Types\Relationships\`.

### BelongsTo
```php
new RecordBelongsToType(
    name: 'category',        // key used in ?select= and write payload
    foreignTable: 'categories',
    foreignKey: 'category_id',  // FK on THIS table
    localKey: 'id',
)
```
Write: set FK in root payload — `{ "category_id": 3 }`.

### HasMany
```php
new RecordHasManyType(
    name: 'variants',
    foreignTable: 'product_variants',
    foreignKey: 'product_id',   // FK on CHILD table
    localKey: 'id',
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
    name: 'tags', foreignTable: 'tags',
    pivotTable: 'product_tags',
    foreignPivotKey: 'product_id',
    relatedPivotKey: 'tag_id',
    pivotColumns: ['order'],   // optional extra pivot columns
    withTimestamps: true,
)
```

### HasManyThrough
```php
new RecordHasManyThroughType(
    name: 'reviews', foreignTable: 'reviews',
    throughTable: 'order_items',
    firstKey: 'product_id',     // FK on through-table → THIS table
    secondKey: 'order_item_id', // FK on reviews → through-table
    localKey: 'id', secondLocalKey: 'id',
)
```

### HasOneThrough
Same as HasManyThrough with `type: RecordRelationshipsEnum::HAS_ONE_THROUGH`.

### Morph Relationships
```php
// MorphMany
new RecordHasManyType(
    name: 'comments', foreignTable: 'comments',
    foreignKey: 'commentable_id', localKey: 'id',
    type: RecordRelationshipsEnum::MORPH_MANY,
    morphType: 'commentable_type',
    morphClass: 'App\\Models\\Product',
)
// MorphToMany
new RecordMetaBelongsToManyType(
    name: 'tags', foreignTable: 'tags', pivotTable: 'taggables',
    foreignPivotKey: 'taggable_id', relatedPivotKey: 'tag_id',
    type: RecordRelationshipsEnum::MORPH_TO_MANY,
    morphType: 'taggable_type', morphClass: 'App\\Models\\Product',
)
```

### Spatie Permission
```php
new RecordSpatiePermissionType(name: 'roles', type: 'roles', guardName: 'api')
```

### Relationship Write Payload Summary

| Relationship    | Payload shape                      | Remove child              |
|-----------------|------------------------------------|---------------------------|
| BelongsTo       | `"fk_col": id` in root             | —                         |
| HasMany         | `"rel": [id, {id,...}, {...}]`      | `{id, "_delete": true}`   |
| HasOne          | `"rel": {fields}`                  | `{"_delete": true}`       |
| BelongsToMany   | `"rel": [id, {id, pivot:{}}]`      | `{id, "_delete": true}`   |
| HasManyThrough  | `"rel": [id, {id}]`               | `{id, "_delete": true}`   |
| MorphMany       | `"rel": [{data}]`                  | `{id, "_delete": true}`   |
| MorphToMany     | `"rel": [id, {id}]`               | `{id, "_delete": true}`   |
| MorphTo         | type + id fields in root           | —                         |
| Spatie          | `"rel": [id, name, {id}]`         | `{id, "_delete": true}`   |

---

## Validators

Run before create / update / delete. Return a Laravel `Validator` instance.
Failure → `error_code: 10004`, HTTP 422. Bulk operations bypass validators.

Signature: `fn(Request $request, ?int $id): \Illuminate\Validation\Validator`

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

| Hook           | Fires                  | Extra args  | Can return              |
|----------------|------------------------|-------------|-------------------------|
| `beforeRead`   | Before list/get query  | —           | `Request\|null`         |
| `afterRead`    | After list/get         | `$results`  | `void`                  |
| `beforeCreate` | Before INSERT          | —           | `Request\|array\|null`  |
| `afterCreate`  | After INSERT           | `$record`   | `void`                  |
| `beforeUpdate` | Before UPDATE          | `$record`   | `Request\|array\|null`  |
| `afterUpdate`  | After UPDATE           | `$record`   | `void`                  |
| `beforeDelete` | Before DELETE          | `$record`   | `Request\|array\|null`  |
| `afterDelete`  | After DELETE           | `$record`   | `void`                  |

Signature: `fn(Request $request, string $table, ...$args): Request|array|null`

```php
beforeCreate: function (Request $request, string $table): Request {
    $request->merge(['created_by' => auth()->id()]);
    return $request;
},
afterUpdate: function (Request $request, string $table, $record): void {
    cache()->forget("product:{$record->id}");
},
beforeDelete: function (Request $request, string $table, $record): void {
    abort_if($record->has_active_orders, 422, 'Cannot delete with active orders');
},
```

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

### Global function (class-based)

```php
class LoginFunction extends \Sopheak\Core\Resources\GlobalFunction
{
    public function configure(): RecordFunctionType
    {
        return new RecordFunctionType(
            httpMethod: ['POST'], class: LoginAction::class,
            functionName: 'handle', pmsName: null,
        );
    }
}
```

Register in `config/record.php`:
```php
'global_functions' => ['auth/login' => LoginFunction::class]
```

Endpoint: `POST /api/v1/rpc/auth/login`

### Table-scoped function

Register inside `RecordTableType::functions`:
```php
functions: ['publish' => new RecordFunctionType(...)]
```

Endpoint: `POST /api/v1/products/rpc/publish`

---

## RPC Action Class — Return Types & Response

The action method receives `Request $request` and can return either:

### Option A — return array (framework wraps it automatically)

```php
use Illuminate\Http\Request;
use Sopheak\Core\Exceptions\RecordNotFoundException;
use Sopheak\Core\Exceptions\RecordForbiddenException;
use Sopheak\Core\Exceptions\RecordServerException;

class PublishProductAction
{
    public function handle(Request $request): array
    {
        $id = $request->route('id'); // available for table-scoped functions

        $product = Product::find($id);
        if (! $product) throw new RecordNotFoundException();

        $product->update(['status' => 'published']);

        // Returning array → auto-wrapped into standard success envelope
        return ['published' => true, 'id' => $product->id];
    }
}
```

Response produced:
```json
{ "success": true, "error_code": 0, "data": { "published": true, "id": 5 }, "meta": {...} }
```

### Option B — return JsonResponse directly (full control)

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

All methods are **static**. Import: `use Sopheak\Core\Services\RecordApiResponseService;`

### Standard wrapped responses (match the API envelope)

```php
// Success with data + optional meta
RecordApiResponseService::successWrapped(
    data:       $data,        // mixed
    meta:       [],           // array — merged into meta object
    status:     200,          // HTTP status code
    headers:    [],           // extra HTTP headers
    error_code: null,         // override error_code (default 0)
): JsonResponse

// Error with message + optional field errors
RecordApiResponseService::errorWrapped(
    message:    'Something failed',
    status:     500,
    errors:     [],           // ['field' => ['message']]
    error_code: null,
): JsonResponse
```

### Shorthand responses

```php
RecordApiResponseService::success($data)          // 200, error_code 0
RecordApiResponseService::created($data)          // 201, error_code 0
RecordApiResponseService::updated($data)          // 200, error_code 0
RecordApiResponseService::deleted($data)          // 200, error_code 0
RecordApiResponseService::notFound($message)      // 404, error_code 10009
RecordApiResponseService::unauthorized($message)  // 401, error_code 10002
RecordApiResponseService::forbidden($message)     // 403, error_code 10008
RecordApiResponseService::validationError($errors)// 422, error_code 10004
RecordApiResponseService::serverError($message)   // 500, error_code 10010
```

### Data helpers

```php
// Remove columns listed in columnHiddens for a table
RecordApiResponseService::removeHiddenFields($data, $table): mixed

// Remove deleted_at from response data
RecordApiResponseService::removeDeletedAtFields($data): mixed
```

---

## Custom Controllers — Using Package Standards

When building a custom controller (not RPC), use the package's response and query tools
to stay consistent with the standard API contract.

### List / Get endpoint — use applyRequestFilters

`RecordService::applyRequestFilters()` gives your custom endpoint all standard query support:
`?select`, `?s`, `?sortby`, `?order`, `?per_page`, `?page`, `?limit`, filters, aggregation, relationships.

```php
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Services\RecordApiResponseService;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $result = RecordService::applyRequestFilters(
            request:      $request,
            tableOrBuilder: DB::table('orders')->where('status', 'paid'), // Builder
            isArray:      true,
            orderBy:      'created_at',
        );
        // $result keys: data, meta, headers, filters, request

        return RecordApiResponseService::successWrapped(
            data:    $result['data'],
            meta:    $result['meta'],
            headers: $result['headers'],
        );
    }
}
```

Alternatively, pass a table name string or a `RecordTableType` (inherits column config):

```php
// By table name — no column config applied
RecordService::applyRequestFilters($request, 'orders');

// By RecordTableType — columnHiddens and config are respected
RecordService::applyRequestFilters($request, $tableType);

// Via Builder macro (same underlying call)
DB::table('orders')
    ->where('status', 'paid')
    ->applyRequestFilters($request, isArray: true, orderBy: 'created_at');
// Returns same array: ['data', 'meta', 'headers', 'filters', 'request']
```

### applyRequestFilters — full signature

```php
RecordService::applyRequestFilters(
    request:        Request $request,
    tableOrBuilder: Builder|RecordTableType|string,
    tanentColumn:   ?string = '',   // tenant column name if filtering manually
    isArray:        bool    = true, // true = return array; false = return collection
    orderBy:        string  = 'id', // default sort column when ?sortby not provided
): array
// Returns: ['data', 'meta', 'headers', 'filters', 'request']
```

### Single record endpoint

```php
public function show(Request $request, int $id): JsonResponse
{
    $record = DB::table('products')->find($id);

    if (! $record) {
        return RecordApiResponseService::notFound('Product not found');
    }

    return RecordApiResponseService::successWrapped(data: $record);
}
```

### Write endpoint

```php
public function store(Request $request): JsonResponse
{
    $data = $request->validated();
    $id   = DB::table('products')->insertGetId($data);

    return RecordApiResponseService::created(
        DB::table('products')->find($id)
    );
}
```

### Error handling pattern

```php
use Sopheak\Core\Exceptions\RecordNotFoundException;
use Sopheak\Core\Exceptions\RecordForbiddenException;

// Throw package exceptions — they are caught and formatted automatically
throw new RecordNotFoundException();     // → 404, error_code 10009
throw new RecordForbiddenException();    // → 403, error_code 10008
throw new RecordServerException();       // → 500, error_code 10010

// Or return directly
return RecordApiResponseService::validationError(['email' => ['Required']]);
return RecordApiResponseService::unauthorized();
return RecordApiResponseService::forbidden();
```

---

## Audit Logging

`config/audit.php` key settings:

```php
'enabled'           => true,
'queue_enabled'     => true,
'log_relationships' => false,
'subject_fields'    => ['name', 'title', 'email'],
'entity_labels'     => ['products' => 'Product'],
'recap_max_fields'  => 5,
```

Per-table override in `RecordTableType`:
```php
disableAuditLog: false,
customAuditLog: fn($event, $entityClass, $data, $tenantId, $ctx) => /* custom logic */,
```

Model-driven (outside CRUD API):
```php
class Product extends Model { use \Sopheak\Core\Traits\AuditableTrait; }
```

---

## QueryHelpers Trait

For custom Eloquent controllers outside the CRUD API:

```php
class Product extends Model { use \Sopheak\Core\Traits\QueryHelpers; }

Product::query()->applyRequestFilters(request: $request, isArray: false, orderBy: 'id');
// Returns: Paginator | LazyCollection | Collection | Builder
// Extra params: ?join= ?select_join= ?lazy=true ?total_record=true
```

---

## Exceptions

```php
\Sopheak\Core\Exceptions\RecordNotFoundException   // error_code 10009, HTTP 404
\Sopheak\Core\Exceptions\RecordForbiddenException  // error_code 10008, HTTP 403
\Sopheak\Core\Exceptions\RecordServerException     // error_code 10010, HTTP 500
```

---

## Rate Limiting Groups

| Group                    | Applies to                    |
|--------------------------|-------------------------------|
| `throttle:api-reads`     | All GET endpoints             |
| `throttle:api-writes`    | POST / PUT / PATCH / DELETE   |
| `throttle:api-functions` | All RPC function calls        |

---

## Artisan Commands

```
sp-laravel-api:record {table}              scaffold table config class
sp-laravel-api:sync-record-columns         sync DB schema → column metadata
sp-laravel-api:sync-record-columns --force --table=x
sp-laravel-api:setup                       publish configs and migrations
sp-laravel-api:validate-setup --fix        validate installation, auto-repair
sp-laravel-api:clean-audit-logs            purge expired audit records
```

---

## Standard Response Shape

```
success:    bool
error_code: int      — 0 = ok; see SKILL-FRONTEND.md for full table
data:       object | array
meta:       { request_id, page, per_page, total }
message:    string   — errors only
errors:     { field: [string] }  — validation only
```

Note: key is `error_code` (snake_case); meta uses `page` not `current_page`.

---

## Environment Variable

```
SP_LARAVEL_API_AUTH_GUARD=api    # api | web | sanctum | jwt
```

---

## Project Conventions

| Path                   | Purpose                               |
|------------------------|---------------------------------------|
| `config/record.php`    | Table and function registry           |
| `config/records/tables/` | Per-table class-based config files  |
| `config/audit.php`     | Audit logging settings                |
| `app/Api/Tables/`      | RecordResource subclasses             |
| `app/Api/Functions/`   | GlobalFunction subclasses             |
| `app/Actions/`         | RPC action classes                    |
