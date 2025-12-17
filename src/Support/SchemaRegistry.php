<?php

namespace Sopheak\Core\Support;

use Exception;
use Sopheak\Core\Types\RecordTableType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SchemaRegistry
{
    private const CACHE_KEY = 'schema_record_registry:v1';

    private static array $cache = [];

    private static array $columnCache = [];

    // Cache TTL in seconds (1 year) - fallback if config not available
    private static int $cacheTtl = 31536000;

    // Redis cache keys
    private static string $schemaCacheKey = 'schema_record_registry:schema';

    private static string $columnCacheKey = 'schema_record_registry:columns';

    /**
     * Get schema registry for allowed tables.
     *
     * @return array<string,RecordTableType>
     */
    public static function get(): array
    {
        // Check memory cache first
        if (!empty(self::$cache)) {
            return self::$cache;
        }

        // Check Redis cache
        $cached = Cache::get(self::$schemaCacheKey);
        if ($cached) {
            self::$cache = self::hydrateRuntimeFields($cached);

            return self::$cache;
        }

        // Use static configuration from record.tables
        $tables = config('record.tables', []);
        if (empty($tables)) {
            // Safe default: expose nothing until configured
            self::$cache = [];
        } else {
            // Build schema cache directly from static configuration
            self::$cache = [];
            foreach ($tables as $tableName => $config) {
                // Use actual table name from config, fallback to record name
                $actualTableName = $config->table ?? $tableName;

                // Add columns property using actual table name
                $config->columns = self::getTableColumns($actualTableName);

                $config->primary_key ??= 'id';
                $config->has_tenant_id ??= true;

                self::$cache[$tableName] = $config;
            }
        }

        // Cache the result
        try {
            $cacheable = self::sanitizeForCache(self::$cache);
            Cache::put(self::$schemaCacheKey, $cacheable, self::$cacheTtl);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to cache schema registry', [
                'error' => $throwable->getMessage(),
            ]);
        }

        return self::$cache;
    }

    /**
     * Bust the cache.
     */
    public static function refresh(): void
    {
        // Clear all caches
        Cache::forget(self::$schemaCacheKey);
        Cache::forget(self::CACHE_KEY); // Legacy cache key

        // Clear column caches for all tables
        $tables = config('record.tables', []);
        foreach (array_keys($tables) as $tableName) {
            Cache::forget(self::$columnCacheKey.':'.$tableName);
        }

        // Clear memory caches
        self::$cache = [];
        self::$columnCache = [];
    }

    /**
     * Clear all caches (memory and Redis).
     */
    public static function clearAllCache(): void
    {
        self::$cache = [];
        self::$columnCache = [];

        Cache::forget(self::$schemaCacheKey);

        // Clear column caches for all tables
        $tables = config('record.tables', []);
        foreach (array_keys($tables) as $tableName) {
            Cache::forget(self::$columnCacheKey.':'.$tableName);
        }
    }

    /**
     * Clear cache for a specific table.
     */
    public static function clearTableCache(string $tableName): void
    {
        // Clear memory cache
        unset(self::$columnCache[$tableName]);

        // Clear Redis cache
        Cache::forget(self::$columnCacheKey.':'.$tableName);

        // Clear main schema cache to force rebuild
        Cache::forget(self::$schemaCacheKey);
        self::$cache = [];
    }

    /**
     * Get table columns information from database with caching.
     */
    private static function getTableColumns(string $tableName): array
    {
        // Check memory cache first
        if (isset(self::$columnCache[$tableName])) {
            return self::$columnCache[$tableName];
        }

        // Check Redis cache
        $cacheKey = self::$columnCacheKey.':'.$tableName;
        $cached = Cache::get($cacheKey);
        if ($cached) {
            self::$columnCache[$tableName] = $cached;

            return $cached;
        }

        try {
            $driver = DB::getDriverName();
            $columnInfo = [];

            if ($driver === 'sqlite') {
                // SQLite syntax
                $columns = DB::select(sprintf('PRAGMA table_info(%s)', $tableName));
                
                foreach ($columns as $column) {
                    $columnInfo[$column->name] = [
                        'type' => $column->type,
                        'nullable' => $column->notnull == 0,
                        'key' => $column->pk == 1 ? 'PRI' : '',
                        'default' => $column->dflt_value,
                        'extra' => '',
                    ];
                }
            } else {
                // MySQL syntax (default)
                $columns = DB::select(sprintf('DESCRIBE `%s`', $tableName));
                
                foreach ($columns as $column) {
                    $columnInfo[$column->Field] = [
                        'type' => $column->Type,
                        'nullable' => 'YES' === $column->Null,
                        'key' => $column->Key,
                        'default' => $column->Default,
                        'extra' => $column->Extra,
                    ];
                }
            }

            // Cache the result
            self::$columnCache[$tableName] = $columnInfo;
            Cache::put($cacheKey, $columnInfo, self::$cacheTtl);

            return $columnInfo;
        } catch (Exception $exception) {
            // Log the error for debugging
            Log::warning(sprintf('Failed to get columns for table %s: ', $tableName).$exception->getMessage());

            // Return empty array as fallback
            return [];
        }
    }

    private static function hydrateRuntimeFields(array $schema): array
    {
        $tables = config('record.tables', []);

        foreach ($schema as $tableName => $config) {
            if (!isset($tables[$tableName])) {
                continue;
            }

            $source = $tables[$tableName];

            $config->createValidator = $source->createValidator ?? null;
            $config->updateValidator = $source->updateValidator ?? null;
            $config->deleteValidator = $source->deleteValidator ?? null;
        }

        return $schema;
    }

    private static function sanitizeForCache(mixed $value): mixed
    {
        if ($value instanceof \Closure) {
            return null;
        }

        if ($value instanceof \UnitEnum) {
            return $value;
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = self::sanitizeForCache($item);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            $clone = clone $value;
            foreach (get_object_vars($clone) as $property => $propertyValue) {
                $clone->{$property} = self::sanitizeForCache($propertyValue);
            }

            return $clone;
        }

        return $value;
    }
}
