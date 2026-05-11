<?php

namespace Sopheak\Core\Utilities;

use Throwable;
use Sopheak\Core\Interfaces\RecordResourceInterface;
use stdClass;
use Exception;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sopheak\Core\Services\AttributeDiscoveryService;
use Sopheak\Core\Services\RecordConfigService;

class SchemaRegistryUtils
{
    private static array $cache = [];

    private static array $uniqueColumnsCache = [];

    private static array $foreignKeysCache = [];

    /**
     * Get a specific table schema.
     */
    public static function getTable(string $tableName): ?RecordTableType
    {
        if (isset(self::$cache[$tableName]) && self::$cache[$tableName] instanceof RecordTableType) {
            return self::$cache[$tableName];
        }

        $tables = self::get();

        if (isset($tables[$tableName]) && $tables[$tableName] instanceof RecordTableType) {
            return $tables[$tableName];
        }

        foreach ($tables as $configKey => $config) {
            if (!($config instanceof RecordTableType)) {
                continue;
            }

            if (in_array($tableName, self::tableAliases((string) $configKey, $config), true)) {
                self::$cache[$tableName] = $config;

                return $config;
            }
        }

        return null;
    }

    /**
     * Get schema registry for allowed tables.
     *
     * @return array<string,RecordTableType>
     */
    public static function get(): array
    {
        if (!empty(self::$cache)) {
            return self::$cache;
        }

        $tables = RecordConfigService::getTableConfig();
        $registry = [];

        foreach ($tables as $tableName => $config) {
            // Resolve class-based or array config to RecordTableType
            if (is_string($config) && class_exists($config)) {
                $instance = new $config();
                if ($instance instanceof RecordTableType) {
                    $config = $instance;
                } elseif ($instance instanceof RecordResourceInterface) {
                    $config = $instance->toTableType();
                }
            } elseif (is_array($config)) {
                $config = RecordTableType::__set_state($config);
            }

            if (!($config instanceof RecordTableType)) {
                continue;
            }

            // Fallback: if columns are not set (e.g. dev mode without generation), derive from DB
            $actualTableName = $config->table ?? $tableName;
            if (empty($config->columns)) {
                $config->columns = self::getTableColumns($actualTableName);
            }

            $config->primaryKey ??= 'id';
            $config->hasTenantId ??= true;

            $registry[$tableName] = $config;
        }

        // Merge attribute-discovered tables; file-based config always wins on conflict.
        if ((bool) config('sp-laravel-api.attribute_discovery.enabled', false)) {
            try {
                $attributeTables = AttributeDiscoveryService::discover();
            } catch (Throwable) {
                $attributeTables = [];
            }

            foreach ($attributeTables as $tableName => $config) {
                if (isset($registry[$tableName])) {
                    // File-based config takes precedence on conflicts; still merge
                    // attribute-defined functions that are missing in file config.
                    self::mergeMissingFunctions($registry[$tableName], $config);
                    continue;
                }

                $actualTableName = $config->table ?? $tableName;
                if (empty($config->columns)) {
                    $config->columns = self::getTableColumns($actualTableName);
                }

                $config->primaryKey ??= 'id';

                $registry[$tableName] = $config;
            }
        }

        self::$cache = $registry;

        return self::$cache;
    }

    /**
     * Resolve table schema (columns) even if not in the allowed configuration.
     */
    public static function resolveTableSchema(string $tableName): ?object
    {
        if (isset(self::$cache[$tableName])) {
            return self::$cache[$tableName];
        }

        $columns = self::getTableColumns($tableName);
        if (empty($columns)) {
            return null;
        }

        $schema = new stdClass();
        $schema->table = $tableName;
        $schema->columns = $columns;
        $schema->softDeletes = isset($columns['deleted_at']);

        return $schema;
    }

    /**
     * Bust the in-memory cache.
     */
    public static function refresh(): void
    {
        self::$cache = [];
        RelationshipResolverUtils::clearSchemaCache();
        QueryBuilderFiltersUtils::clearColumnCache();
    }

    public static function clearAllCache(): void
    {
        self::$cache = [];
        self::$uniqueColumnsCache = [];
        self::$foreignKeysCache = [];
        RelationshipResolverUtils::clearSchemaCache();
        QueryBuilderFiltersUtils::clearColumnCache();
    }

    /**
     * Register a table schema at runtime.
     */
    public static function register(string $tableName, string|object $config): void
    {
        if (is_string($config) || ($config instanceof RecordResourceInterface)) {
            self::$cache[$tableName] = $config;
            RelationshipResolverUtils::clearSchemaCache();
            return;
        }

        if (empty(self::$cache)) {
            self::get();
        }

        if (empty($config->columns)) {
            $actualTableName = $config->table ?? $tableName;
            $config->columns = self::getTableColumns($actualTableName);
        }

        $config->primaryKey ??= 'id';
        $config->hasTenantId ??= true;

        self::$cache[$tableName] = $config;
        RelationshipResolverUtils::clearSchemaCache();
        QueryBuilderFiltersUtils::clearColumnCache();
    }

    /**
     * Clear cache for a specific table.
     */
    public static function clearTableCache(string $tableName): void
    {
        unset(self::$cache[$tableName]);
        unset(self::$uniqueColumnsCache[$tableName], self::$foreignKeysCache[$tableName]);
    }

    /**
     * @return array<string>
     */
    public static function tableAliases(string $configKey, RecordTableType $config): array
    {
        $aliases = [];

        if ('' !== trim($configKey)) {
            $aliases[] = trim($configKey);
        }

        if (is_string($config->table) && '' !== trim($config->table)) {
            $aliases[] = trim($config->table);
        }

        if (is_string($config->pmsName) && '' !== trim($config->pmsName)) {
            $aliases[] = trim($config->pmsName);
        } elseif (is_array($config->pmsName)) {
            foreach ($config->pmsName as $candidate) {
                if (!is_string($candidate)) {
                    continue;
                }

                if ('' === trim($candidate)) {
                    continue;
                }

                $aliases[] = trim($candidate);
            }
        }

        return array_values(array_unique($aliases));
    }

    private static function mergeMissingFunctions(RecordTableType $existingConfig, RecordTableType $attributeConfig): void
    {
        $existingFunctions = is_array($existingConfig->functions ?? null) ? $existingConfig->functions : [];
        $attributeFunctions = is_array($attributeConfig->functions ?? null) ? $attributeConfig->functions : [];

        if ([] === $attributeFunctions) {
            return;
        }

        // Existing config wins on key conflicts.
        $existingConfig->functions = array_merge($attributeFunctions, $existingFunctions);
    }

    /**
     * Get table columns information from database.
     * Public so the CLI command can use it for generation.
     */
    public static function getTableColumns(string $tableName): array
    {
        try {
            $driver = DB::getDriverName();
            $columnInfo = [];

            if ($driver === 'sqlite') {
                $columns = DB::select(sprintf('PRAGMA table_info(%s)', $tableName));

                foreach ($columns as $column) {
                    $columnInfo[$column->name] = [
                        'type' => $column->type,
                        'key' => $column->pk == 1 ? 'PRI' : '',
                        'nullable' => $column->notnull == 0,
                        'default' => $column->dflt_value,
                        'extra' => '',
                    ];
                }
            } elseif ($driver === 'pgsql') {
                $columns = DB::select(
                    'select column_name, data_type, udt_name, udt_schema, is_nullable, column_default from information_schema.columns where table_name = ? and table_schema = current_schema()',
                    [$tableName]
                );

                $compositeCache = [];

                foreach ($columns as $column) {
                    $compositeFields = [];
                    $typeName = $column->udt_name ?? null;
                    $typeSchema = $column->udt_schema ?? null;
                    $dataType = strtolower((string) $column->data_type);

                    if ($dataType === 'user-defined' && is_string($typeName) && $typeName !== '') {
                        $schemaKey = is_string($typeSchema) && $typeSchema !== '' ? $typeSchema : 'public';
                        $cacheKey = $schemaKey . '.' . $typeName;

                        if (!array_key_exists($cacheKey, $compositeCache)) {
                            $compositeCache[$cacheKey] = self::getCompositeTypeFields($schemaKey, $typeName);
                        }

                        $compositeFields = $compositeCache[$cacheKey];
                    }

                    $columnInfo[$column->column_name] = [
                        'type' => $column->data_type,
                        'udt_name' => $column->udt_name ?? null,
                        'udt_schema' => $column->udt_schema ?? null,
                        'nullable' => 'YES' === $column->is_nullable,
                        'key' => '',
                        'default' => $column->column_default,
                        'extra' => '',
                    ];

                    if ($compositeFields !== []) {
                        $columnInfo[$column->column_name]['compositeFields'] = $compositeFields;
                    }
                }
            } else {
                $columns = DB::select(sprintf('DESCRIBE `%s`', $tableName));

                foreach ($columns as $column) {
                    $columnInfo[$column->Field] = [
                        'type' => $column->Type,
                        'key' => $column->Key,
                        'nullable' => 'YES' === $column->Null,
                        'default' => $column->Default,
                        'extra' => $column->Extra,
                    ];
                }
            }

            return $columnInfo;
        } catch (Exception $exception) {
            Log::warning(sprintf('Failed to get columns for table %s: ', $tableName) . $exception->getMessage());

            return [];
        }
    }

    /**
     * @return array<string>
     */
    public static function getUniqueColumns(string $tableName): array
    {
        if (isset(self::$uniqueColumnsCache[$tableName])) {
            return self::$uniqueColumnsCache[$tableName];
        }

        $driver = DB::getDriverName();
        $columns = [];

        try {
            if ($driver === 'sqlite') {
                $indexes = DB::select(sprintf('PRAGMA index_list(%s)', $tableName));
                foreach ($indexes as $index) {
                    if (empty($index->unique)) {
                        continue;
                    }

                    $indexInfo = DB::select(sprintf('PRAGMA index_info(%s)', $index->name));
                    if (count($indexInfo) === 1) {
                        $columns[] = $indexInfo[0]->name;
                    }
                }
            } elseif ($driver === 'pgsql') {
                $columns = array_map(
                    fn($row) => $row->column_name,
                    DB::select(
                        'select a.attname as column_name from pg_index i join pg_class t on t.oid = i.indrelid join pg_namespace n on n.oid = t.relnamespace join pg_attribute a on a.attrelid = t.oid and a.attnum = any(i.indkey) where t.relname = ? and n.nspname = current_schema() and i.indisunique = true and i.indisprimary = false and array_length(i.indkey, 1) = 1',
                        [$tableName]
                    )
                );
            } else {
                $indexes = DB::select(sprintf('SHOW INDEX FROM `%s` WHERE Non_unique = 0', $tableName));
                $grouped = [];
                foreach ($indexes as $index) {
                    $keyName = $index->Key_name ?? null;
                    if (!is_string($keyName)) {
                        continue;
                    }

                    if ('' === $keyName) {
                        continue;
                    }

                    if ('PRIMARY' === $keyName) {
                        continue;
                    }

                    $grouped[$keyName][] = $index->Column_name ?? null;
                }

                foreach ($grouped as $cols) {
                    $cols = array_values(array_filter($cols, fn($col): bool => is_string($col) && '' !== $col));
                    if (count($cols) === 1) {
                        $columns[] = $cols[0];
                    }
                }
            }
        } catch (Exception $exception) {
            Log::warning(sprintf('Failed to get unique columns for table %s: ', $tableName) . $exception->getMessage());
        }

        $columns = array_values(array_unique($columns));
        self::$uniqueColumnsCache[$tableName] = $columns;

        return $columns;
    }

    /**
     * @return array<string,array{table:string,column:string}>
     */
    public static function getForeignKeys(string $tableName): array
    {
        if (isset(self::$foreignKeysCache[$tableName])) {
            return self::$foreignKeysCache[$tableName];
        }

        $driver = DB::getDriverName();
        $foreignKeys = [];

        try {
            if ($driver === 'sqlite') {
                $rows = DB::select(sprintf('PRAGMA foreign_key_list(%s)', $tableName));
                foreach ($rows as $row) {
                    if (!isset($row->from, $row->table, $row->to)) {
                        continue;
                    }

                    $foreignKeys[$row->from] = [
                        'table' => $row->table,
                        'column' => $row->to,
                    ];
                }
            } elseif ($driver === 'pgsql') {
                $rows = DB::select(
                    'select kcu.column_name, ccu.table_name as foreign_table_name, ccu.column_name as foreign_column_name from information_schema.table_constraints tc join information_schema.key_column_usage kcu on tc.constraint_name = kcu.constraint_name and tc.table_schema = kcu.table_schema join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.table_schema = tc.table_schema where tc.constraint_type = ? and tc.table_schema = current_schema() and tc.table_name = ?',
                    ['FOREIGN KEY', $tableName]
                );

                foreach ($rows as $row) {
                    if (!isset($row->column_name, $row->foreign_table_name, $row->foreign_column_name)) {
                        continue;
                    }

                    $foreignKeys[$row->column_name] = [
                        'table' => $row->foreign_table_name,
                        'column' => $row->foreign_column_name,
                    ];
                }
            } else {
                $rows = DB::select(
                    'select column_name, referenced_table_name, referenced_column_name from information_schema.key_column_usage where table_schema = database() and table_name = ? and referenced_table_name is not null',
                    [$tableName]
                );

                foreach ($rows as $row) {
                    if (!isset($row->column_name, $row->referenced_table_name, $row->referenced_column_name)) {
                        continue;
                    }

                    $foreignKeys[$row->column_name] = [
                        'table' => $row->referenced_table_name,
                        'column' => $row->referenced_column_name,
                    ];
                }
            }
        } catch (Exception $exception) {
            Log::warning(sprintf('Failed to get foreign keys for table %s: ', $tableName) . $exception->getMessage());
        }

        self::$foreignKeysCache[$tableName] = $foreignKeys;

        return $foreignKeys;
    }

    private static function getCompositeTypeFields(string $schema, string $typeName): array
    {
        $rows = DB::select(
            'select a.attname as field_name from pg_type t join pg_namespace n on n.oid = t.typnamespace join pg_class c on c.oid = t.typrelid join pg_attribute a on a.attrelid = c.oid where t.typtype = ? and n.nspname = ? and t.typname = ? and a.attnum > 0 and not a.attisdropped order by a.attnum',
            ['c', $schema, $typeName]
        );

        return array_map(static fn($row): string => (string) $row->field_name, $rows);
    }
}
