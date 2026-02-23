<?php

namespace Sopheak\Core\Services;

use Exception;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
            return Cache::remember($cacheKey, $ttl, $callback);
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
            return Cache::put($cacheKey, $value, $ttl);
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
        } catch (Exception $exception) {
            Log::warning('Failed to get cached query result', [
                'key' => $key,
                'error' => $exception->getMessage()
            ]);
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
        } catch (Exception $exception) {
            Log::warning('Failed to invalidate cache', [
                'key' => $key,
                'error' => $exception->getMessage()
            ]);
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
            if (!$store instanceof RedisStore) {
                return Cache::flush() ? 1 : 0;
            }

            $redis = $store->connection();
            $keys = $redis->keys(self::getCachePrefix() . $pattern);

            if (empty($keys)) {
                return 0;
            }

            return $redis->del($keys);
        } catch (Exception $exception) {
            Log::warning('Failed to invalidate cache by pattern', [
                'pattern' => $pattern,
                'error' => $exception->getMessage()
            ]);
            return 0;
        }
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
            'limit' => $limit
        ];

        return md5(serialize($keyParts));
    }

    /**
     * Invalidate all cache for a specific table
     */
    public static function invalidateTable(string $table): int
    {
        $deleted = self::forgetByPattern(sprintf('*record_index:table:%s:*', $table));
        $deleted += self::forgetByPattern(sprintf('*record_show:table:%s:*', $table));
        $deleted += self::forgetByPattern(sprintf('*record_func:table:%s:*', $table));

        return $deleted;
    }

    public static function invalidateTableForTenant(string $table, string $tenantKey): int
    {
        $deleted = self::forgetByPattern(sprintf('*record_index:table:%s:tenant:%s:*', $table, $tenantKey));
        $deleted += self::forgetByPattern(sprintf('*record_show:table:%s:tenant:%s:*', $table, $tenantKey));
        $deleted += self::forgetByPattern(sprintf('*record_func:table:%s:tenant:%s:*', $table, $tenantKey));

        return $deleted;
    }

    public static function invalidateRecordForTenant(string $table, mixed $id, string $tenantKey): int
    {
        return self::forgetByPattern(sprintf('*record_show:table:%s:id:%s:tenant:%s:*', $table, $id, $tenantKey));
    }
}
