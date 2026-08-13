<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Throwable;
use Exception;
use Illuminate\Support\Facades\Cache;

class QueryCacheService
{
    private const REQUEST_NAMESPACE_MEMO_KEY = 'sp_laravel_api.cache.namespace_versions';

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
     * Ten years, in seconds.
     *
     * A TTL is REQUIRED here, not cosmetic: Repository::add() only delegates to
     * the store's atomic add() when one is passed, and falls back to a plain
     * get-then-put when it is not. Without it two concurrent first-bumps can
     * each read null, each seed 1, and clobber one another's increment --
     * resurrecting entries cached under the version that was overwritten.
     */
    private const NAMESPACE_SEED_TTL = 315360000;

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
    public static function remember(string $key, callable $callback, ?int $ttl = null, array $dependencies = []): mixed
    {
        // If caching is disabled, execute callback directly
        if (!self::isCacheEnabled()) {
            return $callback();
        }

        $ttl ??= self::getCacheTtl();
        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key, $dependencies);

        try {
            return Cache::remember($cacheKey, $ttl, $callback);
        } catch (Exception) {
            return $callback();
        }
    }

    /**
     * Cache a query result
     */
    public static function put(string $key, mixed $value, ?int $ttl = null, array $dependencies = []): bool
    {
        // If caching is disabled, return true (no-op)
        if (!self::isCacheEnabled()) {
            return true;
        }

        $ttl ??= self::getCacheTtl();
        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key, $dependencies);

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
    public static function get(string $key, array $dependencies = []): mixed
    {
        // If caching is disabled, return null (cache miss)
        if (!self::isCacheEnabled()) {
            return null;
        }

        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key, $dependencies);

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
    public static function forget(string $key, array $dependencies = []): bool
    {
        // If caching is disabled, return true (no-op)
        if (!self::isCacheEnabled()) {
            return true;
        }

        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key, $dependencies);

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

    private static function resolveNamespaceToken(string $key, array $dependencies = []): string
    {
        $dependencyToken = self::resolveDependencyToken($dependencies);

        $parsed = self::parseScopeFromKey($key);
        if (null === $parsed) {
            return 'v1' . $dependencyToken;
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

            // A single record's cache must also die when the whole table is cleared.
            // Without these two components, clearTableCache() busts list caches and
            // leaves every record_show entry for the table stale until TTL.
            $tableGlobalVersion = self::getNamespaceVersion(scope: 'table', name: $parsed['name']);
            $tableTenantVersion = '' !== $parsed['tenant']
                ? self::getNamespaceVersion(scope: 'table', name: $parsed['name'], tenantKey: $parsed['tenant'])
                : 1;

            return sprintf(
                'v%s.%s.%s.t%s.%s',
                $globalVersion,
                $tenantVersion,
                $recordVersion,
                $tableGlobalVersion,
                $tableTenantVersion
            ) . $dependencyToken;
        }

        return sprintf('v%s.%s', $globalVersion, $tenantVersion) . $dependencyToken;
    }

    /**
     * Hash the versions of every namespace this entry declares a dependency on.
     *
     * This is what lets a cached function die when a table it reads is written to.
     * Parts are sorted so declaration order does not change the key. An empty list
     * yields an empty string, reproducing the pre-dependency token exactly.
     *
     * @param array<int, array<string, mixed>> $dependencies
     */
    private static function resolveDependencyToken(array $dependencies): string
    {
        if ([] === $dependencies) {
            return '';
        }

        $parts = [];
        foreach ($dependencies as $dependency) {
            if (!is_array($dependency)) {
                continue;
            }

            $scope = isset($dependency['scope']) ? (string) $dependency['scope'] : '';
            $name = isset($dependency['name']) ? (string) $dependency['name'] : '';
            if ('' === $scope || '' === $name) {
                continue;
            }

            $tenant = isset($dependency['tenant']) && '' !== (string) $dependency['tenant']
                ? (string) $dependency['tenant']
                : null;

            $version = self::getNamespaceVersion(scope: $scope, name: $name, tenantKey: $tenant);
            $parts[] = sprintf('%s:%s:%s:%s', $scope, $name, $tenant ?? '-', $version);
        }

        if ([] === $parts) {
            return '';
        }

        sort($parts);

        return '.d' . substr(md5(implode('|', $parts)), 0, 12);
    }

    private static function parseScopeFromKey(string $key): ?array
    {
        if (preg_match('/^record_show:table:([^:]+):id:([^:]+):.*tenant:([^:]*):/', $key, $matches)) {
            return ['scope' => 'record', 'name' => $matches[1], 'record_id' => $matches[2], 'tenant' => $matches[3]];
        }

        if (preg_match('/^record_(?:index|cursor|func):table:([^:]+):.*tenant:([^:]*):/', $key, $matches)) {
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
        $key = self::namespaceKey(scope: $scope, name: $name, tenantKey: $tenantKey, recordId: $recordId);

        try {
            $next = self::incrementNamespaceVersion($key);
            self::memoizeNamespaceVersion($key, $next);
            self::incrementRequestStat('invalidation_bumps');
        } catch (Exception) {
        }
    }

    /**
     * Atomically move a namespace to its next version.
     *
     * add() seeds the counter at 1 so the first increment lands on 2 -- unseeded
     * namespaces already resolve to v1 via getNamespaceVersion(), so a bump has to
     * move off it. add() is a no-op once the counter exists, making this safe to
     * call on every bump. Passing a TTL is what routes that seed through the
     * store's atomic add() instead of Repository's plain get-then-put fallback;
     * the increment that follows is atomic on database, redis and memcached.
     * Together they are what stops two concurrent writers from losing an update.
     */
    private static function incrementNamespaceVersion(string $key): int
    {
        Cache::add($key, 1, self::NAMESPACE_SEED_TTL);

        $next = Cache::increment($key);
        if (is_int($next) && $next > 0) {
            return $next;
        }

        // Store has no usable atomic increment -- fall back to read-modify-write.
        $current = (int) Cache::get($key, 1);
        $next = max($current, 1) + 1;
        Cache::forever($key, $next);

        return $next;
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
