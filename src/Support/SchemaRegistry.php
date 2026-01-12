<?php

namespace Sopheak\Core\Support;

use Closure;
use Exception;
use Throwable;
use UnitEnum;
use Sopheak\Core\Types\RecordTableType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SchemaRegistry
{
    private const CACHE_KEY = 'sopheak_core:sp_laravel_api:record_schema:v1';

    private static array $cache = [];

    private static array $columnCache = [];

    // Cache TTL in seconds (1 year) - fallback if config not available
    private static int $cacheTtl = 31536000;

    // Redis cache keys
    private static string $schemaCacheKey = 'sopheak_core:sp_laravel_api:record_schema:v1:schema';

    private static string $columnCacheKey = 'sopheak_core:sp_laravel_api:record_schema:v1:columns';

    /**
     * Get a specific table schema.
     * This is the preferred method for runtime access as it supports lazy loading.
     */
    public static function getTable(string $tableName): ?RecordTableType
    {
        $config = null;

        // 1. Check memory cache
        if (isset(self::$cache[$tableName])) {
            $cached = self::$cache[$tableName];
            // If fully resolved, return it
            if ($cached instanceof RecordTableType) {
                return $cached;
            }
            // If pending resolution (string/class), use it
            $config = $cached;
        } elseif (!empty(self::$cache)) {
             // 2. Legacy: If cache is populated but key missing, it's missing.
             // Unless we allow partial loading. 
             // Current logic assumes if cache is not empty, it contains everything. 
             // But with lazy loading, cache might be partial.
             // So we should fallback to config if not found.
        }

        // 3. Try to load from config if not yet found
        if ($config === null) {
            $tables = config('record.tables', []);
            if (isset($tables[$tableName])) {
                $config = $tables[$tableName];
            }
        }

        if ($config === null) {
            return null;
        }

        // 4. Handle Class-Based Config (Lazy Loading)
        if (is_string($config) && class_exists($config)) {
            $instance = new $config();
            if ($instance instanceof RecordTableType) {
                $config = $instance;
            } elseif ($instance instanceof \Sopheak\Core\Interfaces\RecordResourceInterface) {
                $config = $instance->toTableType();
            }
        }

        // 5. Normalize config
        if (is_array($config)) {
            // Convert array to RecordTableType object using __set_state for safe mapping
            $config = RecordTableType::__set_state($config);
        }

        if (!($config instanceof RecordTableType)) {
            return null;
        }

        // 6. Enrich with columns
        $actualTableName = $config->table ?? $tableName;
        if (empty($config->columns)) {
            $config->columns = self::getTableColumns($actualTableName);
        }

        // 7. Apply defaults
        $config->primary_key ??= 'id';
        $config->has_tenant_id ??= true;

        // 8. Cache in memory
        self::$cache[$tableName] = $config;
        self::$columnCache[$tableName] = $config->columns;

        return $config;
    }

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
                // 1. Resolve Class-Based Config
                if (is_string($config) && class_exists($config)) {
                    $instance = new $config();
                    if ($instance instanceof RecordTableType) {
                        $config = $instance;
                    } elseif ($instance instanceof \Sopheak\Core\Interfaces\RecordResourceInterface) {
                        $config = $instance->toTableType();
                    }
                }

                // 2. Normalize Array Config
                if (is_array($config)) {
                    $config = RecordTableType::__set_state($config);
                }

                if (!($config instanceof RecordTableType)) {
                    continue;
                }

                // Use actual table name from config, fallback to record name
                $actualTableName = $config->table ?? $tableName;

                // Add columns property using actual table name
                if (empty($config->columns)) {
                    $config->columns = self::getTableColumns($actualTableName);
                }

                $config->primary_key ??= 'id';
                $config->has_tenant_id ??= true;

                self::$cache[$tableName] = $config;
            }
        }

        // Cache the result
        try {
            $cacheable = self::sanitizeForCache(self::$cache);
            Cache::put(self::$schemaCacheKey, $cacheable, self::$cacheTtl);
        } catch (Throwable $throwable) {
            Log::warning('Failed to cache schema registry', [
                'error' => $throwable->getMessage(),
            ]);
        }

        return self::$cache;
    }

    /**
     * Resolve table schema (columns) even if not in the allowed configuration.
     * This allows filtering on related tables that are not exposed as top-level resources.
     *
     * @param string $tableName The table name to resolve
     * @return object|null A minimal schema object with columns, or null if table not found
     */
    public static function resolveTableSchema(string $tableName): ?object
    {
        // Check if it's already in the allowed schema
        if (isset(self::$cache[$tableName])) {
            return self::$cache[$tableName];
        }

        // Check column cache directly (this will load from DB if not cached)
        $columns = self::getTableColumns($tableName);
        if (empty($columns)) {
            return null;
        }

        // Return a minimal schema object
        $schema = new \stdClass();
        $schema->table = $tableName;
        $schema->columns = $columns;
        $schema->soft_deletes = isset($columns['deleted_at']); // Auto-detect soft deletes
        
        return $schema;
    }

    /**
     * Bust the cache.
     */
    public static function refresh(): void
    {
        // Clear all caches
        Cache::forget(self::$schemaCacheKey);
        Cache::forget(self::CACHE_KEY);

        // Clear column caches for all tables
        $tables = config('record.tables', []);
        foreach (array_keys($tables) as $tableName) {
            Cache::forget(self::$columnCacheKey . ':' . $tableName);
        }

        // Clear memory caches
        self::$cache = [];
        self::$columnCache = [];
    }

    /**
     * Register a table schema at runtime.
     * 
     * @param string $tableName The key name of the table in schema
     * @param string|object $config The configuration object or class name
     */
    public static function register(string $tableName, string|object $config): void
    {
        // If it's a class string or resource interface, store it for lazy loading
        if (is_string($config) || ($config instanceof \Sopheak\Core\Interfaces\RecordResourceInterface)) {
            self::$cache[$tableName] = $config;
            return;
        }

        // Ensure cache is populated first
        if (empty(self::$cache)) {
            self::get();
        }

        // Ensure columns are populated
        if (empty($config->columns)) {
            $actualTableName = $config->table ?? $tableName;
            $config->columns = self::getTableColumns($actualTableName);
        }

        // Default fallbacks
        $config->primary_key ??= 'id';
        $config->has_tenant_id ??= true;

        self::$cache[$tableName] = $config;

        // Force update column cache for this table
        self::$columnCache[$tableName] = $config->columns;
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
            Cache::forget(self::$columnCacheKey . ':' . $tableName);
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
        Cache::forget(self::$columnCacheKey . ':' . $tableName);

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
        $cacheKey = self::$columnCacheKey . ':' . $tableName;
        $cached = Cache::get($cacheKey);
        if ($cached) {
            self::$columnCache[$tableName] = $cached;

            return $cached;
        }

        try {
            $driver = DB::getDriverName();
            $columnInfo = [];

            if ($driver === 'sqlite') {
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
            } elseif ($driver === 'pgsql') {
                $columns = DB::select(
                    'select column_name, data_type, is_nullable, column_default from information_schema.columns where table_name = ? and table_schema = current_schema()',
                    [$tableName]
                );

                foreach ($columns as $column) {
                    $columnInfo[$column->column_name] = [
                        'type' => $column->data_type,
                        'nullable' => 'YES' === $column->is_nullable,
                        'key' => '',
                        'default' => $column->column_default,
                        'extra' => '',
                    ];
                }
            } else {
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
            Log::warning(sprintf('Failed to get columns for table %s: ', $tableName) . $exception->getMessage());

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

            if (is_object($source)) {
                $config->createValidator = $source->createValidator ?? null;
                $config->updateValidator = $source->updateValidator ?? null;
                $config->deleteValidator = $source->deleteValidator ?? null;
            } elseif (is_array($source)) {
                $config->createValidator = $source['createValidator'] ?? null;
                $config->updateValidator = $source['updateValidator'] ?? null;
                $config->deleteValidator = $source['deleteValidator'] ?? null;
            }
        }

        return $schema;
    }

    private static function sanitizeForCache(mixed $value): mixed
    {
        if ($value instanceof Closure) {
            return null;
        }

        if ($value instanceof UnitEnum) {
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
