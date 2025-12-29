<?php

namespace Sopheak\Core\Services;

use Exception;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class QueryCacheService
{
    private static int $defaultTtl = 3600;

    /**
     * Check if caching is enabled globally
     */
    private static function isCacheEnabled(): bool
    {
        return config('record.cache.enabled', false);
    }

    /**
     * Get cache TTL from record configuration
     */
    private static function getCacheTtl(): int
    {
        return config('record.cache.ttl', config('record.cache_ttl', self::$defaultTtl));
    }

    /**
     * Get cache prefix from record configuration
     */
    private static function getCachePrefix(): string
    {
        return config('record.cache.prefix', 'records_api') . ':';
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
                // Pattern invalidation only supported on Redis
                return 0;
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
        return self::forgetByPattern(sprintf('*table:%s*', $table));
    }

    /**
     * Get cache statistics
     */
    public static function getStats(): array
    {
        // If caching is disabled, return disabled status
        if (!self::isCacheEnabled()) {
            return [
                'status' => 'disabled',
                'cache_enabled' => false,
                'cache_prefix' => self::getCachePrefix()
            ];
        }

        try {
            $store = Cache::getStore();
            if (!$store instanceof RedisStore) {
                return [
                    'status' => 'enabled',
                    'cache_enabled' => true,
                    'driver' => config('cache.default'),
                    'cache_prefix' => self::getCachePrefix()
                ];
            }
            $redis = $store->connection();
            $keys = $redis->keys(self::getCachePrefix() . '*');

            return [
                'status' => 'enabled',
                'cache_enabled' => true,
                'total_keys' => count($keys),
                'memory_usage' => $redis->info('memory')['used_memory_human'] ?? 'unknown',
                'cache_prefix' => self::getCachePrefix()
            ];
        } catch (Exception $exception) {
            return [
                'status' => 'error',
                'cache_enabled' => true,
                'error' => $exception->getMessage()
            ];
        }
    }
}
