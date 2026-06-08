<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Throwable;
use Exception;
use Illuminate\Support\Facades\Cache;

class QueryCacheService
{
    private const REQUEST_NAMESPACE_MEMO_KEY = 'sp_laravel_api.cache.namespace_versions';

    private const REQUEST_BUMPED_NAMESPACES_KEY = 'sp_laravel_api.cache.bumped_namespaces';

    private const REQUEST_STATS_KEY = 'sp_laravel_api.cache.stats';

    private const DEFAULT_STATS = [
        'cache_hits' => 0,
        'cache_misses' => 0,
        'cache_puts' => 0,
        'namespace_reads' => 0,
        'namespace_memo_hits' => 0,
        'invalidation_bumps' => 0,
        'invalidation_dedupe_hits' => 0,
    ];

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
            $stored = Cache::put($cacheKey, $value, $ttl);
            if ($stored) {
                self::incrementRequestStat('cache_puts');
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

        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key);

        try {
            $value = Cache::get($cacheKey);
            self::incrementRequestStat(null !== $value ? 'cache_hits' : 'cache_misses');

            return $value;
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

    /**
     * Read the current namespace version for a given scope/name/tenant/record combination.
     * Exposed for the cache:status artisan command and external inspection tools.
     */
    public static function inspectNamespaceVersion(string $scope, string $name, ?string $tenantKey = null, ?string $recordId = null): int
    {
        return self::getNamespaceVersion(scope: $scope, name: $name, tenantKey: $tenantKey, recordId: $recordId);
    }

    public static function requestStats(): array
    {
        $stats = self::getRequestAttribute(self::REQUEST_STATS_KEY, []);

        return array_merge(self::DEFAULT_STATS, is_array($stats) ? $stats : []);
    }

    public static function invalidateRecordForTenant(string $table, mixed $id, string $tenantKey): int
    {
        if (!self::isCacheEnabled()) {
            return 0;
        }

        self::bumpNamespace(scope: 'record', name: $table, tenantKey: $tenantKey, recordId: (string) $id);

        return 1;
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

        if ('record' === $parsed['scope'] && isset($parsed['record_id'])) {
            $recordVersion = self::getNamespaceVersion(
                scope: 'record',
                name: $parsed['name'],
                tenantKey: '' !== $parsed['tenant'] ? $parsed['tenant'] : null,
                recordId: $parsed['record_id']
            );

            return sprintf('v%s.%s.%s', $globalVersion, $tenantVersion, $recordVersion);
        }

        return sprintf('v%s.%s', $globalVersion, $tenantVersion);
    }

    private static function parseScopeFromKey(string $key): ?array
    {
        if (preg_match('/^record_show:table:([^:]+):id:([^:]+):.*tenant:([^:]*):/', $key, $matches)) {
            return ['scope' => 'record', 'name' => $matches[1], 'record_id' => $matches[2], 'tenant' => $matches[3]];
        }

        if (preg_match('/^record_(?:index|func):table:([^:]+):.*tenant:([^:]*):/', $key, $matches)) {
            return ['scope' => 'table', 'name' => $matches[1], 'tenant' => $matches[2]];
        }

        if (preg_match('/^record_func_global:function:([^:]+):tenant:([^:]*):/', $key, $matches)) {
            return ['scope' => 'global_function', 'name' => $matches[1], 'tenant' => $matches[2]];
        }

        return null;
    }

    private static function namespaceKey(string $scope, string $name, ?string $tenantKey = null, ?string $recordId = null): string
    {
        if ('record' === $scope && null !== $recordId) {
            $tenantSegment = null !== $tenantKey ? sprintf(':tenant:%s', $tenantKey) : '';
            return self::getCachePrefix() . sprintf('ns:%s:%s:%s%s', $scope, $name, $recordId, $tenantSegment);
        }

        if (null === $tenantKey) {
            return self::getCachePrefix() . sprintf('ns:%s:%s', $scope, $name);
        }

        return self::getCachePrefix() . sprintf('ns:%s:%s:tenant:%s', $scope, $name, $tenantKey);
    }

    private static function bumpNamespace(string $scope, string $name, ?string $tenantKey = null, ?string $recordId = null): void
    {
        try {
            $key = self::namespaceKey(scope: $scope, name: $name, tenantKey: $tenantKey, recordId: $recordId);
            $bumped = self::getRequestAttribute(self::REQUEST_BUMPED_NAMESPACES_KEY, []);
            $bumped = is_array($bumped) ? $bumped : [];
            if (isset($bumped[$key])) {
                self::incrementRequestStat('invalidation_dedupe_hits');

                return;
            }

            $bumped[$key] = true;
            self::setRequestAttribute(self::REQUEST_BUMPED_NAMESPACES_KEY, $bumped);

            $current = self::getNamespaceVersion(scope: $scope, name: $name, tenantKey: $tenantKey, recordId: $recordId);
            $next = $current + 1;
            Cache::forever($key, $next);
            self::memoizeNamespaceVersion($key, $next);
            self::incrementRequestStat('invalidation_bumps');
        } catch (Exception) {
        }
    }

    private static function getNamespaceVersion(string $scope, string $name, ?string $tenantKey = null, ?string $recordId = null): int
    {
        $key = self::namespaceKey(scope: $scope, name: $name, tenantKey: $tenantKey, recordId: $recordId);

        $memo = self::getRequestAttribute(self::REQUEST_NAMESPACE_MEMO_KEY, []);
        $memo = is_array($memo) ? $memo : [];
        if (isset($memo[$key])) {
            self::incrementRequestStat('namespace_memo_hits');

            return (int) $memo[$key];
        }

        try {
            $value = Cache::get($key);
            self::incrementRequestStat('namespace_reads');
            $version = is_int($value) ? $value : (int) $value;
            $version = $version > 0 ? $version : 1;
            self::memoizeNamespaceVersion($key, $version);

            return $version;
        } catch (Exception) {
            return 1;
        }
    }

    private static function memoizeNamespaceVersion(string $key, int $version): void
    {
        $memo = self::getRequestAttribute(self::REQUEST_NAMESPACE_MEMO_KEY, []);
        $memo = is_array($memo) ? $memo : [];
        $memo[$key] = $version;
        self::setRequestAttribute(self::REQUEST_NAMESPACE_MEMO_KEY, $memo);
    }

    private static function incrementRequestStat(string $key): void
    {
        $stats = self::requestStats();
        $stats[$key] = (int) ($stats[$key] ?? 0) + 1;
        self::setRequestAttribute(self::REQUEST_STATS_KEY, $stats);
    }

    private static function getRequestAttribute(string $key, mixed $default = null): mixed
    {
        try {
            return request()->attributes->get($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    private static function setRequestAttribute(string $key, mixed $value): void
    {
        try {
            request()->attributes->set($key, $value);
        } catch (Throwable) {
        }
    }
}
