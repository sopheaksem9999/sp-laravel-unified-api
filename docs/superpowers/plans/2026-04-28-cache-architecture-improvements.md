# Cache Architecture Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make record/table/function caching safe and fast across all Laravel cache drivers by replacing pattern/index invalidation with versioned (namespace) cache keys and tenant-aware invalidation.

**Architecture:** We stop deleting cache keys by pattern (`Redis KEYS`, `Database LIKE`, or index arrays). Instead, we add a small “namespace version” token to every cache key. Clearing cache becomes an O(1) operation: bump a version key in the cache store. Reads automatically use the latest version token, so old entries become unreachable and expire naturally by TTL. We keep per-table cache enable/disable, support manual clear (table / table function / global function), and prevent cross-tenant cache stampedes by scoping invalidation by tenant.

**Tech Stack:** PHP 8+, Laravel Cache facade (works with redis/database/file/memcached/array)

---

## File Structure (What Changes Where)

- Modify: `src/Services/QueryCacheService.php`
  - Replace pattern/index invalidation with versioned namespace keys
  - Keep `remember/put/get/forget` public API stable
- Modify: `src/Services/RecordCacheService.php`
  - Make cache key hashes deterministic (recursive sort) before hashing
  - Keep all public methods stable
- Modify: `src/Listeners/InvalidateRecordCacheListener.php`
  - Use tenant-aware invalidation based on event payload
- Add: `tests/Unit/QueryCacheServiceNamespaceInvalidationTest.php`
  - Prove namespace invalidation works without deleting keys
- Modify: `tests/Unit/SchemaRegistryTest.php`
  - Remove assumptions about internal cache index keys
- Modify: `docs/guide/records/record-cache.md`
  - Document namespace invalidation behavior and driver compatibility

---

### Task 1: Add Tests for Namespace (Version) Invalidation

**Files:**
- Create: `tests/Unit/QueryCacheServiceNamespaceInvalidationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/QueryCacheServiceNamespaceInvalidationTest.php`:

```php
<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Tests\TestCase;

class QueryCacheServiceNamespaceInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);

        Cache::flush();
    }

    public function test_it_invalidates_only_the_target_tenant_for_table_cache(): void
    {
        $keyTenant1 = 'record_index:table:users:tenant:tenant-1:hash:abc';
        $keyTenant2 = 'record_index:table:users:tenant:tenant-2:hash:abc';

        QueryCacheService::put($keyTenant1, 't1', 3600);
        QueryCacheService::put($keyTenant2, 't2', 3600);

        $this->assertSame('t1', QueryCacheService::get($keyTenant1));
        $this->assertSame('t2', QueryCacheService::get($keyTenant2));

        QueryCacheService::invalidateTableForTenant('users', 'tenant-1');

        $this->assertNull(QueryCacheService::get($keyTenant1));
        $this->assertSame('t2', QueryCacheService::get($keyTenant2));
    }

    public function test_it_invalidates_all_tenants_when_table_global_namespace_is_bumped(): void
    {
        $keyTenant1 = 'record_index:table:users:tenant:tenant-1:hash:abc';
        $keyTenant2 = 'record_index:table:users:tenant:tenant-2:hash:abc';

        QueryCacheService::put($keyTenant1, 't1', 3600);
        QueryCacheService::put($keyTenant2, 't2', 3600);

        $this->assertSame('t1', QueryCacheService::get($keyTenant1));
        $this->assertSame('t2', QueryCacheService::get($keyTenant2));

        QueryCacheService::invalidateTable('users');

        $this->assertNull(QueryCacheService::get($keyTenant1));
        $this->assertNull(QueryCacheService::get($keyTenant2));
    }

    public function test_it_invalidates_global_function_cache_for_specific_tenant(): void
    {
        $keyTenant1 = 'record_func_global:function:stats:tenant:tenant-1:hash:abc';
        $keyTenant2 = 'record_func_global:function:stats:tenant:tenant-2:hash:abc';

        QueryCacheService::put($keyTenant1, 't1', 3600);
        QueryCacheService::put($keyTenant2, 't2', 3600);

        $this->assertSame('t1', QueryCacheService::get($keyTenant1));
        $this->assertSame('t2', QueryCacheService::get($keyTenant2));

        QueryCacheService::invalidateGlobalFunctionForTenant('stats', 'tenant-1');

        $this->assertNull(QueryCacheService::get($keyTenant1));
        $this->assertSame('t2', QueryCacheService::get($keyTenant2));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
vendor/bin/phpunit tests/Unit/QueryCacheServiceNamespaceInvalidationTest.php
```

Expected: FAIL (because `QueryCacheService` does not yet implement namespace versioning and still deletes by pattern/index).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/QueryCacheServiceNamespaceInvalidationTest.php
git commit -m "test: add failing tests for cache namespace invalidation"
```

---

### Task 2: Implement Namespace (Version) Keys in `QueryCacheService`

**Files:**
- Modify: `src/Services/QueryCacheService.php`

- [ ] **Step 1: Add namespace key parsing**

Add these private helpers to `QueryCacheService`:

```php
private static function parseScopeFromKey(string $key): ?array
{
    if (preg_match('/^record_(?:index|show|func):table:([^:]+):.*tenant:([^:]*):/', $key, $m)) {
        return ['scope' => 'table', 'name' => $m[1], 'tenant' => $m[2]];
    }

    if (preg_match('/^record_func_global:function:([^:]+):tenant:([^:]*):/', $key, $m)) {
        return ['scope' => 'global_function', 'name' => $m[1], 'tenant' => $m[2]];
    }

    return null;
}

private static function namespaceKey(string $scope, string $name, ?string $tenantKey = null): string
{
    $prefix = self::getCachePrefix();

    if (null === $tenantKey) {
        return $prefix . sprintf('ns:%s:%s', $scope, $name);
    }

    return $prefix . sprintf('ns:%s:%s:tenant:%s', $scope, $name, $tenantKey);
}
```

- [ ] **Step 2: Add a safe “bump namespace” helper (works on all drivers)**

```php
private static function bumpNamespace(string $scope, string $name, ?string $tenantKey = null): void
{
    $key = self::namespaceKey(scope: $scope, name: $name, tenantKey: $tenantKey);

    try {
        $current = Cache::get($key);
        $next = is_int($current) ? $current + 1 : ((int) $current + 1);
        if ($next <= 0) {
            $next = 1;
        }
        Cache::forever($key, $next);
    } catch (Exception) {
    }
}

private static function getNamespaceVersion(string $scope, string $name, ?string $tenantKey = null): int
{
    $key = self::namespaceKey(scope: $scope, name: $name, tenantKey: $tenantKey);

    try {
        $value = Cache::get($key);
        $version = is_int($value) ? $value : (int) $value;
        return $version > 0 ? $version : 1;
    } catch (Exception) {
        return 1;
    }
}
```

- [ ] **Step 3: Compute a namespace token and use it in the stored cache key**

Add this helper:

```php
private static function resolveNamespaceToken(string $key): string
{
    $parsed = self::parseScopeFromKey($key);
    if (null === $parsed) {
        return 'v1';
    }

    $globalVersion = self::getNamespaceVersion(scope: $parsed['scope'], name: $parsed['name']);
    $tenantVersion = 1;
    if ('' !== $parsed['tenant']) {
        $tenantVersion = self::getNamespaceVersion(scope: $parsed['scope'], name: $parsed['name'], tenantKey: $parsed['tenant']);
    }

    return sprintf('v%s.%s', $globalVersion, $tenantVersion);
}
```

Update `remember/put/get/forget` to store/read using a versioned key:

```php
$cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key);
```

- [ ] **Step 4: Replace invalidation methods with namespace bumps**

Update invalidation methods:

```php
public static function invalidateTable(string $table): int
{
    if (!self::isCacheEnabled()) {
        return 0;
    }

    self::bumpNamespace(scope: 'table', name: $table);
    return 1;
}

public static function invalidateTableForTenant(string $table, string $tenantKey): int
{
    if (!self::isCacheEnabled()) {
        return 0;
    }

    self::bumpNamespace(scope: 'table', name: $table, tenantKey: $tenantKey);
    return 1;
}

public static function invalidateRecordForTenant(string $table, mixed $id, string $tenantKey): int
{
    return self::invalidateTableForTenant($table, $tenantKey);
}

public static function invalidateTableFunctionForTenant(string $table, string $functionName, string $tenantKey): int
{
    return self::invalidateTableForTenant($table, $tenantKey);
}

public static function invalidateGlobalFunctionForTenant(string $functionName, string $tenantKey): int
{
    if (!self::isCacheEnabled()) {
        return 0;
    }

    self::bumpNamespace(scope: 'global_function', name: $functionName, tenantKey: $tenantKey);
    return 1;
}
```

- [ ] **Step 5: Remove old pattern/index invalidation methods**

Delete these methods from `QueryCacheService` (they are no longer used):
- `forgetByPattern`
- `forgetByPrefix`
- `trackRecordCacheKey`
- `forgetByIndexPattern`
- `forgetByIndex`
- `forgetByTableIndex`
- `parseRecordCacheKey`
- `parseRecordCachePattern`
- `recordCacheIndexKey`

- [ ] **Step 6: Run tests to verify they pass**

Run:

```bash
vendor/bin/phpunit tests/Unit/QueryCacheServiceNamespaceInvalidationTest.php
```

Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Services/QueryCacheService.php
git commit -m "refactor: use versioned cache namespaces for invalidation across drivers"
```

---

### Task 3: Ensure Deterministic Cache Keys (Recursive Sort Before Hashing)

**Files:**
- Modify: `src/Services/RecordCacheService.php`

- [ ] **Step 1: Add a private recursive ksort helper**

Add this method to `RecordCacheService`:

```php
private function recursiveKsort(array &$array): void
{
    foreach ($array as &$value) {
        if (is_array($value)) {
            $this->recursiveKsort($value);
        }
    }
    ksort($array);
}
```

- [ ] **Step 2: Update `generateOptimizedCacheKey`**

```php
public function generateOptimizedCacheKey(string $table, array $filters, array $includes, int $page, int $limit, bool $tenantEnabled): string
{
    $tenantColumn = RecordConfigService::tenantColumn();
    $tenantKey = $this->resolveTenantCacheKey(tenantId: $filters[$tenantColumn] ?? null, tenantEnabled: $tenantEnabled);

    $this->recursiveKsort($filters);
    $this->recursiveKsort($includes);

    $keyData = [
        'filters' => $filters,
        'includes' => $includes,
        'page' => $page,
        'limit' => $limit,
        'tenant_enabled' => $tenantEnabled,
    ];

    return sprintf('record_index:table:%s:tenant:%s:hash:%s', $table, $tenantKey, md5(serialize($keyData)));
}
```

- [ ] **Step 3: Update `generateRecordCacheKey`**

```php
public function generateRecordCacheKey(string $table, mixed $id, mixed $tenantId, mixed $select, bool $tenantEnabled): string
{
    $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);

    if (is_array($select)) {
        $this->recursiveKsort($select);
    }

    $keyData = [
        'id' => $id,
        'select' => $select,
        'tenant_enabled' => $tenantEnabled,
    ];

    return sprintf('record_show:table:%s:id:%s:tenant:%s:select:%s', $table, $id, $tenantKey, md5(serialize($keyData)));
}
```

- [ ] **Step 4: Update function key generation to use recursive sort**

Replace:

```php
ksort($queryParams);
```

With:

```php
$this->recursiveKsort($queryParams);
```

In:
- `generateTableFunctionCacheKey`
- `generateGlobalFunctionCacheKey`

- [ ] **Step 5: Commit**

```bash
git add src/Services/RecordCacheService.php
git commit -m "fix: make record cache keys deterministic via recursive ksort"
```

---

### Task 4: Make Invalidation Tenant-Aware to Prevent Cross-Tenant Cache Stampedes

**Files:**
- Modify: `src/Listeners/InvalidateRecordCacheListener.php`

- [ ] **Step 1: Update listener to call `RecordCacheService::clearTableCache` with tenant id**

Replace the current `handle(...)` with:

```php
public function handle(RecordCreated|RecordUpdated|RecordDeleted $event): void
{
    $tenantColumn = \Sopheak\Core\Services\RecordConfigService::tenantColumn();
    $tenantId = null;

    if ($event instanceof RecordCreated) {
        $tenantId = $event->payload[$tenantColumn] ?? null;
    } elseif ($event instanceof RecordUpdated) {
        $tenantId = $event->newPayload[$tenantColumn] ?? $event->oldPayload[$tenantColumn] ?? null;
    } elseif ($event instanceof RecordDeleted) {
        $tenantId = $event->oldPayload[$tenantColumn] ?? null;
    }

    app(\Sopheak\Core\Services\RecordCacheService::class)->clearTableCache($event->table, $tenantId);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Listeners/InvalidateRecordCacheListener.php
git commit -m "fix: invalidate record cache per-tenant using namespace version bump"
```

---

### Task 5: Update Existing Tests That Assume the Old Cache Index Internals

**Files:**
- Modify: `tests/Unit/SchemaRegistryTest.php`

- [ ] **Step 1: Remove any assertions that reference `record_cache_index`**

Search in `SchemaRegistryTest` for strings like:
- `record_cache_index:`
- `record_index:table:` used as an “index key”

Replace those assertions with a behavior-based assertion, for example:

```php
QueryCacheService::put('record_index:table:users:tenant:tenant-1:hash:abc', 'value', 600);
$this->assertSame('value', QueryCacheService::get('record_index:table:users:tenant:tenant-1:hash:abc'));

QueryCacheService::invalidateTableForTenant('users', 'tenant-1');
$this->assertNull(QueryCacheService::get('record_index:table:users:tenant:tenant-1:hash:abc'));
```

- [ ] **Step 2: Run unit tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/SchemaRegistryTest.php
```

Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/SchemaRegistryTest.php
git commit -m "test: remove cache index assertions; validate namespace invalidation behavior"
```

---

### Task 6: Documentation Update

**Files:**
- Modify: `docs/guide/records/record-cache.md`

- [ ] **Step 1: Document namespace invalidation**

Add a section that explains:
- Cache keys include a namespace token (global + tenant)
- Clearing cache bumps the namespace version (no wildcard deletes)
- Works across all Laravel cache drivers
- Old cache entries remain until TTL, but are unreachable after bump

Suggested docs snippet:

```md
## Cache Invalidation (Namespace Versioning)

This package does not delete cached keys by wildcard (no Redis `KEYS`, no DB `LIKE`). Instead it uses namespace versioning:

- Each cached query key includes a version token (global + tenant).
- On write (or manual clear), the package increments a small namespace version key.
- New reads use the latest version token, making old cached entries unreachable.
- Old entries are automatically removed when their TTL expires.
```

- [ ] **Step 2: Commit**

```bash
git add docs/guide/records/record-cache.md
git commit -m "docs: describe namespace versioning cache invalidation"
```

---

## Self-Review Checklist

- [ ] Namespace invalidation never uses Redis `KEYS` or DB `LIKE`
- [ ] Manual clear still works via `RecordCacheService` methods
- [ ] Per-table cache enable/disable still works via `RecordCacheService::isCacheableRequest`
- [ ] Tenant-aware invalidation is enforced in `InvalidateRecordCacheListener`
- [ ] Deterministic cache key hashing prevents duplicate cache entries from reordered query params
