---
title: "Internal API Methods and Function Caching"
description: "Business logic method reference, detailed internal examples, and function caching behavior."
keywords:
  - internal methods
  - business logic
  - function caching
  - service methods
  - internal API
---

## Internal API Methods (Business Logic)

The package provides internal methods that allow developers to execute CRUD operations directly from their business logic (e.g., inside custom controllers, jobs, or commands) using the exact same dynamic query syntax as the REST API. This ensures consistent behavior, relationship loading, and filtering across both HTTP requests and internal code.

### Available Methods

All methods are available statically on `Sopheak\Core\Services\RecordService`.

#### `executeGetByFilter`
Fetch multiple records using API filter syntax.
```php
public static function executeGetByFilter(
    string $table, 
    array|string $queryParams = [], 
    mixed $tenantId = null, 
    bool $isArray = true, 
    string $orderBy = 'id'
): array
```

#### `executeGetById`
Fetch a single record by ID, optionally loading relationships.
```php
public static function executeGetById(
    string $table, 
    mixed $id, 
    array|string $queryParams = [], 
    mixed $tenantId = null
): array
```

#### `executeCreate`
Create a record and return it fully loaded with requested relationships.
```php
public static function executeCreate(
    string $table, 
    array $payload, 
    array|string $queryParams = [], 
    mixed $tenantId = null
): array
```

#### `executeUpdate`
Update a record and return it fully loaded with requested relationships.
```php
public static function executeUpdate(
    string $table, 
    mixed $id, 
    array $payload, 
    array|string $queryParams = [], 
    mixed $tenantId = null
): array
```

#### `executeDelete`
Delete a record and return the fully loaded record *before* it was deleted.
```php
public static function executeDelete(
    string $table, 
    mixed $id, 
    array|string $queryParams = [], 
    mixed $tenantId = null
): array
```

### Write Side-Effects (Record Lifecycle Events)

Internal write methods (`executeCreate`, `executeUpdate`, `executeDelete`) trigger the same side-effects as the HTTP API.

After a successful write, the package dispatches these events:
- `Sopheak\Core\Events\RecordCreated`
- `Sopheak\Core\Events\RecordUpdated`
- `Sopheak\Core\Events\RecordDeleted`

These events are **Laravel 13 safe** (they do not carry the full `Illuminate\Http\Request`; instead they include an `auditContext` array with primitive fields like `ip`, `user_agent`, `user_id`, and `request_id`).

**What happens via listeners:**
- Cache invalidation is handled by `InvalidateRecordCacheListener`
- Audit insertion is handled by `LogRecordAuditListener` (queued when audit queue is enabled)

### Detailed Examples

#### 1. Fetching Records with Relationships
You can pass query parameters as an array or a URL-encoded string.

```php
use Sopheak\Core\Services\RecordService;

// Using array syntax
$invoice = RecordService::executeGetById(
    table: 'invoices',
    id: 1,
    queryParams: [
        'select' => '*,customer(*),items(*,product(*))'
    ]
);

// Using string syntax
$activeUsers = RecordService::executeGetByFilter('users', 'select=*,profile(*)&status=eq.active');
```

#### 2. Creating a Record and Getting it Back
When creating a record, you often need the newly generated ID or default database values immediately. `executeCreate` handles this and can even load relationships in the same step.

```php
$newOrder = RecordService::executeCreate('orders', 
    // Payload
    [
        'customer_id' => 5,
        'total' => 150.00,
        'status' => 'pending'
    ],
    // Query params for the returned record
    ['select' => '*,customer(name,email)']
);

echo $newOrder['data']['id']; // The new ID
echo $newOrder['data']['customer']['name']; // Loaded relationship
```

#### 3. Updating a Record
Similar to creation, you can update a record and immediately get the fresh data back.

```php
$updatedOrder = RecordService::executeUpdate('orders', 12, 
    // Payload
    ['status' => 'completed'],
    // Query params
    'select=*,customer(*)'
);
```

#### 4. Deleting a Record
Sometimes you need the data of the record you just deleted (e.g., to send a cancellation email). `executeDelete` fetches the record *before* deleting it and returns it.

```php
$deletedUser = RecordService::executeDelete('users', 42, [
    'select' => '*,profile(*)'
]);

// Send email using the data we just deleted
Mail::to($deletedUser['data']['email'])->send(new AccountDeleted($deletedUser['data']));
```

#### 5. Handling Tenancy
If your application uses multi-tenancy, you can pass the `$tenantId` as the last parameter to ensure the operation is scoped correctly.

```php
$tenantId = 99;
$tenantUsers = RecordService::executeGetByFilter('users', [], $tenantId);
```

### Function Caching

Caching is only applied for `GET` requests and when `record.cache.enabled` is true.
For a focused cache guide (config, TTL, invalidation), see [Record Cache](/guide/record-cache).

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
