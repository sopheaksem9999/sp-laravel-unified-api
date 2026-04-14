<?php

namespace Sopheak\Core\Services;

use Exception;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;

class QueryCacheService
{
    /**
     * Check if caching is enabled globally
     */
    private static function isCacheEnabled(): bool
    {
        return RecordConfigService::cacheEnabled();
    }

    /**
     * Get cache TTL from record configuration
     */
    private static function getCacheTtl(): int
    {
        $ttl = RecordConfigService::cacheTtl();
        if ($ttl > 0) {
            return $ttl;
        }

        return RecordConfigService::legacyCacheTtl();
    }

    /**
     * Get cache prefix from record configuration
     */
    private static function getCachePrefix(): string
    {
        return RecordConfigService::cachePrefix() . ':';
    }

    /**
     * Get cached query result or execute and cache the callback
     */
    public static function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        // If caching is disabled, execute callback directly
        if (!self::isCacheEnabled()) {
            return $callback();
        }

        $ttl ??= self::getCacheTtl();
        $cacheKey = self::getCachePrefix() . $key;

        try {
            $value = Cache::remember($cacheKey, $ttl, $callback);
            self::trackRecordCacheKey($key, $cacheKey, $ttl);
            return $value;
        } catch (Exception) {
            return $callback();
        }
    }

    /**
     * Cache a query result
     */
    public static function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        // If caching is disabled, return true (no-op)
        if (!self::isCacheEnabled()) {
            return true;
        }

        $ttl ??= self::getCacheTtl();
        $cacheKey = self::getCachePrefix() . $key;

        try {
            $stored = Cache::put($cacheKey, $value, $ttl);
            if ($stored) {
                self::trackRecordCacheKey($key, $cacheKey, $ttl);
            }

            return $stored;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Get cached query result
     */
    public static function get(string $key): mixed
    {
        // If caching is disabled, return null (cache miss)
        if (!self::isCacheEnabled()) {
            return null;
        }

        $cacheKey = self::getCachePrefix() . $key;

        try {
            return Cache::get($cacheKey);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Invalidate cache by key
     */
    public static function forget(string $key): bool
    {
        // If caching is disabled, return true (no-op)
        if (!self::isCacheEnabled()) {
            return true;
        }

        $cacheKey = self::getCachePrefix() . $key;

        try {
            return Cache::forget($cacheKey);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Invalidate cache by pattern (Redis only)
     */
    public static function forgetByPattern(string $pattern): int
    {
        // If caching is disabled, return 0 (no-op)
        if (!self::isCacheEnabled()) {
            return 0;
        }

        try {
            $store = Cache::getStore();
            $storePrefix = method_exists($store, 'getPrefix') ? $store->getPrefix() : '';
            $cachePrefix = self::getCachePrefix();
            $prefix = $storePrefix;
            if ('' === $prefix || !str_ends_with($prefix, $cachePrefix)) {
                $prefix .= $cachePrefix;
            }

            if ($store instanceof RedisStore) {
                $redis = $store->connection();
                $keys = $redis->keys($prefix . $pattern);
                if (empty($keys)) {
                    return 0;
                }

                return $redis->del($keys);
            }

            if ($store instanceof DatabaseStore) {
                $sqlPattern = str_replace('*', '%', $prefix . $pattern);
                $table = (string) config('cache.stores.database.table', 'cache');
                return (int) $store->getConnection()
                    ->table($table)
                    ->where('key', 'like', $sqlPattern)
                    ->delete();
            }

            return self::forgetByIndexPattern($pattern);
        } catch (Exception) {
            return 0;
        }
    }

    /**
     * Invalidate cache by prefix (Redis only)
     */
    public static function forgetByPrefix(string $prefix): int
    {
        $prefix = ltrim($prefix, '*');
        return self::forgetByPattern($prefix . '*');
    }

    /**
     * Generate cache key for table-based queries
     */
    public static function tableKey(string $table, array $filters = [], array|string $includes = [], int $page = 1, int $limit = 50): string
    {
        // Normalize includes to array format
        if (is_string($includes)) {
            $includes = $includes !== '' && $includes !== '0' ? explode(',', $includes) : [];
        }

        $keyParts = [
            'table' => $table,
            'filters' => md5(serialize($filters)),
            'includes' => md5(serialize($includes)),
            'page' => $page,
            'limit' => $limit,
        ];

        return md5(serialize($keyParts));
    }

    /**
     * Invalidate all cache for a specific table
     */
    public static function invalidateTable(string $table): int
    {
        $deleted = self::forgetByPrefix(sprintf('record_index:table:%s', $table));
        $deleted += self::forgetByPrefix(sprintf('record_show:table:%s', $table));

        return $deleted + self::forgetByPrefix(sprintf('record_func:table:%s', $table));
    }

    public static function invalidateTableForTenant(string $table, string $tenantKey): int
    {
        $deleted = self::forgetByPrefix(sprintf('record_index:table:%s:tenant:%s', $table, $tenantKey));
        $deleted += self::forgetByPrefix(sprintf('record_show:table:%s:tenant:%s', $table, $tenantKey));
        return $deleted + self::forgetByPrefix(sprintf('record_func:table:%s:tenant:%s', $table, $tenantKey));
    }

    public static function invalidateRecordForTenant(string $table, mixed $id, string $tenantKey): int
    {
        return self::forgetByPrefix(sprintf('record_show:table:%s:id:%s:tenant:%s', $table, $id, $tenantKey));
    }

    public static function invalidateTableFunctionForTenant(string $table, string $functionName, string $tenantKey): int
    {
        return self::forgetByPrefix(sprintf('record_func:table:%s:function:%s:tenant:%s', $table, $functionName, $tenantKey));
    }

    public static function invalidateGlobalFunctionForTenant(string $functionName, string $tenantKey): int
    {
        return self::forgetByPrefix(sprintf('record_func_global:function:%s:tenant:%s', $functionName, $tenantKey));
    }

    private static function trackRecordCacheKey(string $key, string $cacheKey, ?int $ttl): void
    {
        if (Cache::getStore() instanceof RedisStore) {
            return;
        }

        $parsed = self::parseRecordCacheKey($key);
        if (null === $parsed) {
            return;
        }

        $indexKey = self::recordCacheIndexKey(scope: $parsed['scope'], name: $parsed['name']);
        $index = Cache::get($indexKey, []);
        if (!is_array($index)) {
            $index = [];
        }

        $tenantKey = $parsed['tenant'];
        $tenantKeys = $index[$tenantKey] ?? [];
        if (!is_array($tenantKeys)) {
            $tenantKeys = [];
        }

        if (!in_array($cacheKey, $tenantKeys, true)) {
            $tenantKeys[] = $cacheKey;
        }

        $index[$tenantKey] = $tenantKeys;

        $indexTtl = max($ttl ?? self::getCacheTtl(), 86400);
        Cache::put($indexKey, $index, $indexTtl);
    }

    private static function forgetByIndexPattern(string $pattern): int
    {
        $parsed = self::parseRecordCachePattern(rtrim($pattern, '*'));
        if (null === $parsed) {
            return 0;
        }

        if ('global_function' === $parsed['scope']) {
            if (null !== $parsed['tenant']) {
                return self::forgetByIndex(scope: $parsed['scope'], name: $parsed['name'], tenantKey: $parsed['tenant']);
            }

            return self::forgetByTableIndex(scope: $parsed['scope'], name: $parsed['name']);
        }

        if (null !== $parsed['tenant']) {
            return self::forgetByIndex(scope: $parsed['scope'], name: $parsed['name'], tenantKey: $parsed['tenant']);
        }

        return self::forgetByTableIndex(scope: $parsed['scope'], name: $parsed['name']);
    }

    private static function forgetByIndex(string $scope, string $name, string $tenantKey): int
    {
        $indexKey = self::recordCacheIndexKey(scope: $scope, name: $name);
        $index = Cache::get($indexKey, []);
        if (!is_array($index) || [] === $index) {
            return 0;
        }

        $deleted = 0;
        $keys = $index[$tenantKey] ?? [];
        if (!is_array($keys) || [] === $keys) {
            return 0;
        }

        foreach ($keys as $cacheKey) {
            if (Cache::forget($cacheKey)) {
                $deleted++;
            }
        }

        unset($index[$tenantKey]);
        if ([] === $index) {
            Cache::forget($indexKey);
        } else {
            Cache::put($indexKey, $index, max(self::getCacheTtl(), 86400));
        }

        return $deleted;
    }

    private static function forgetByTableIndex(string $scope, string $name): int
    {
        $indexKey = self::recordCacheIndexKey(scope: $scope, name: $name);
        $index = Cache::get($indexKey, []);
        if (!is_array($index) || [] === $index) {
            return 0;
        }

        $deleted = 0;
        foreach ($index as $tenantKey => $keys) {
            if (!is_string($tenantKey)) {
                continue;
            }

            if ('' === $tenantKey) {
                continue;
            }

            if (!is_array($keys)) {
                continue;
            }

            if ([] === $keys) {
                continue;
            }

            foreach ($keys as $cacheKey) {
                if (Cache::forget($cacheKey)) {
                    $deleted++;
                }
            }
        }

        Cache::forget($indexKey);

        return $deleted;
    }

    private static function parseRecordCacheKey(string $key): ?array
    {
        if (preg_match('/^record_index:table:([^:]+):tenant:([^:]+):hash:/', $key, $matches)) {
            return ['scope' => 'table', 'name' => $matches[1], 'tenant' => $matches[2]];
        }

        if (preg_match('/^record_show:table:([^:]+):id:[^:]+:tenant:([^:]+):select:/', $key, $matches)) {
            return ['scope' => 'table', 'name' => $matches[1], 'tenant' => $matches[2]];
        }

        if (preg_match('/^record_func:table:([^:]+):function:[^:]+:tenant:([^:]+):hash:/', $key, $matches)) {
            return ['scope' => 'table', 'name' => $matches[1], 'tenant' => $matches[2]];
        }

        if (preg_match('/^record_func_global:function:([^:]+):tenant:([^:]+):hash:/', $key, $matches)) {
            return ['scope' => 'global_function', 'name' => $matches[1], 'tenant' => $matches[2]];
        }

        return null;
    }

    private static function parseRecordCachePattern(string $pattern): ?array
    {
        if (preg_match('/record_(?:index|show|func):table:([^:]+):.*tenant:([^:]+):/', $pattern, $matches)) {
            return ['scope' => 'table', 'name' => $matches[1], 'tenant' => $matches[2]];
        }

        if (preg_match('/record_(?:index|show|func):table:([^:]+):/', $pattern, $matches)) {
            return ['scope' => 'table', 'name' => $matches[1], 'tenant' => null];
        }

        if (preg_match('/record_func_global:function:([^:]+):tenant:([^:]+):/', $pattern, $matches)) {
            return ['scope' => 'global_function', 'name' => $matches[1], 'tenant' => $matches[2]];
        }

        if (preg_match('/record_func_global:function:([^:]+):/', $pattern, $matches)) {
            return ['scope' => 'global_function', 'name' => $matches[1], 'tenant' => null];
        }

        return null;
    }

    private static function recordCacheIndexKey(string $scope, string $name): string
    {
        return self::getCachePrefix() . sprintf('record_cache_index:%s:%s', $scope, $name);
    }
}
