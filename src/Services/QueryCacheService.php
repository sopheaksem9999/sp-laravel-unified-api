<?php

namespace Sopheak\Core\Services;

use Exception;
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
        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key);

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
        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key);

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

        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key);

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

        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key);

        try {
            return Cache::forget($cacheKey);
        } catch (Exception) {
            return false;
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
            'limit' => $limit,
        ];

        return md5(serialize($keyParts));
    }

    /**
     * Invalidate all cache for a specific table
     */
    public static function invalidateTable(string $table): int
    {
        if (!self::isCacheEnabled()) {
            return 0;
        }

        self::bumpNamespace(scope: 'table', name: $table);

        return 1;
    }

    /**
     * Alias for invalidateTable to be used via dependency injection
     */
    public function invalidateTableCache(string $table): int
    {
        return self::invalidateTable($table);
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

    private static function parseScopeFromKey(string $key): ?array
    {
        if (preg_match('/^record_(?:index|show|func):table:([^:]+):.*tenant:([^:]*):/', $key, $matches)) {
            return ['scope' => 'table', 'name' => $matches[1], 'tenant' => $matches[2]];
        }

        if (preg_match('/^record_func_global:function:([^:]+):tenant:([^:]*):/', $key, $matches)) {
            return ['scope' => 'global_function', 'name' => $matches[1], 'tenant' => $matches[2]];
        }

        return null;
    }

    private static function namespaceKey(string $scope, string $name, ?string $tenantKey = null): string
    {
        if (null === $tenantKey) {
            return self::getCachePrefix() . sprintf('ns:%s:%s', $scope, $name);
        }

        return self::getCachePrefix() . sprintf('ns:%s:%s:tenant:%s', $scope, $name, $tenantKey);
    }

    private static function bumpNamespace(string $scope, string $name, ?string $tenantKey = null): void
    {
        try {
            $key = self::namespaceKey(scope: $scope, name: $name, tenantKey: $tenantKey);
            $current = self::getNamespaceVersion(scope: $scope, name: $name, tenantKey: $tenantKey);
            Cache::forever($key, $current + 1);
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
}
