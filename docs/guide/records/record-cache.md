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
- The table/function opts in — `disableCache` defaults to `true`, so caching
  must be enabled explicitly per table (`RecordTableType(disableCache: false)`)
  and/or per function (`RecordFunctionType(disableCache: false)`)
- HTTP method is `GET`
- Request does not include `search`, `filter`, or `where`
- Table/function cache is not disabled by config flags

> **Why opt-in?** With caching enabled globally, every endpoint used to be
> cached by default, which broke client business logic that reads fresh data.
> Cache is now opt-in per table/function: enable it only where a cached read is
> safe (low-volatility data, no side effects on read).

## Cache Control Levels

### Global level

- Turn all cache on/off with `record.cache.enabled`.
- Set default TTL with `record.cache.ttl`.

### Table level

- **Opt in** per table with `RecordTableType(disableCache: false)` — the
  default `true` keeps the table uncached.
- Disable by table in `record.cache.per_table['table'] = false`.
- Override table TTL in `record.cache.per_table_ttl['table']`.

### Function level

- **Opt in** per function with `RecordFunctionType(disableCache: false)` — the
  default `true` keeps the function uncached.
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

### Own-Records (`viewOwn`) and Per-User Keys

A cache entry is shared by every caller that sends the same query on the same table and tenant — the key has no user in it. Own-records scoping is the exception: when the caller is restricted by `viewOwn:{pmsName}` on the table, or on any table the request embeds through `select` / `with`, the key also carries the owner column(s) and the caller's user id, so each restricted user gets their own entry and never receives rows another caller cached. Callers without `viewOwn` keep the shared keys, so enabling it invalidates nothing. A write still invalidates every entry for the table and tenant. See [Own-Records Scoping](/guide/feature-permission-own-records#caching).

## Manual Cache Clear

```php
use Sopheak\Core\Services\RecordCacheService;

$cache = app(RecordCacheService::class);
$cache->clearTableCache('settings', $tenantId);
$cache->clearCacheForTables(['settings', 'users'], $tenantId);
```

## Practical Setup

1. Start with `record.cache.enabled=true` in production.
2. Opt in per table (`disableCache: false`) only for low-volatility data where
   stale reads are safe; leave everything else at the default (uncached).
3. Set shorter `per_table_ttl` for near-real-time tables.
4. Add `clearCacheTables` on write functions that mutate related tables.
