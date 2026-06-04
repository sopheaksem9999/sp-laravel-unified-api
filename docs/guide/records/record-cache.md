---
title: "Record Cache (Config, TTL, and Invalidation)"
description: "Focused guide for record cache setup, table/function cache controls, TTL behavior, and cache invalidation rules."
keywords:
  - record cache
  - record.cache.enabled
  - per_table cache
  - per_table_ttl
  - disableCache
  - cacheTTL
  - clearCacheTables
  - cache invalidation
---

# Record Cache

This guide explains how cache works in `sopheak/sp-laravel-api` and where to control it.

## Where to Configure

Main config is in `config/record.php`:

```php
'cache' => [
    'enabled' => env('SP_LARAVEL_API_CACHE_API', false),
    'ttl' => env('SP_LARAVEL_API_CACHE_API_TTL', 3600),
    'prefix' => 'sp_laravel_api',
    'per_table' => [
        // 'sp_audit_logs' => false,
    ],
    'per_table_ttl' => [
        // 'products' => 600,
    ],
],
```

## When Requests Are Cacheable

A request is cacheable only when all conditions are true:

- `record.cache.enabled = true`
- HTTP method is `GET`
- Request does not include `search`, `filter`, or `where`
- Table/function cache is not disabled by config flags

## Cache Control Levels

### Global level

- Turn all cache on/off with `record.cache.enabled`.
- Set default TTL with `record.cache.ttl`.

### Table level

- Disable by table in `record.cache.per_table['table'] = false`.
- Disable in table config with `RecordTableType(disableCache: true)`.
- Override table TTL in `record.cache.per_table_ttl['table']`.

### Function level

- Disable per function with `RecordFunctionType(disableCache: true)`.
- Override function TTL with `RecordFunctionType(cacheTTL: 300)`.
- Clear related table caches on write with `RecordFunctionType(clearCacheTables: [...])`.

## Invalidation Behavior

### Cache Invalidation (Namespace Versioning)

This package does not invalidate cache by wildcard deletes (no Redis `KEYS`, no database `LIKE`, and no driver-specific cache tags). Instead, it uses namespace versioning:

- Each cached key is stored with an internal version token (table/global-function + tenant).
- When a write happens (or you manually clear cache), the package increments a small namespace version key.
- New reads automatically use the latest version token, making old cached entries unreachable.
- Old entries are removed automatically when their TTL expires.

### CRUD writes

For successful write operations, runtime clears affected table/record caches automatically.

### Table function writes (`POST|PUT|PATCH|DELETE`)

- Invalidates the executed table-function cache key.
- Clears table cache for the current table by default.
- If `clearCacheTables` is set, those tables are cleared instead.

### Global function writes (`POST|PUT|PATCH|DELETE`)

- Invalidates executed global-function cache key.
- Clears tables listed in `clearCacheTables` (if provided).

## Manual Cache Clear

```php
use Sopheak\Core\Services\RecordCacheService;

$cache = app(RecordCacheService::class);
$cache->clearTableCache('settings', $tenantId);
$cache->clearCacheForTables(['settings', 'users'], $tenantId);
```

## Practical Setup

1. Start with `record.cache.enabled=true` in production.
2. Disable cache only for high-volatility tables.
3. Set shorter `per_table_ttl` for near-real-time tables.
4. Add `clearCacheTables` on write functions that mutate related tables.
