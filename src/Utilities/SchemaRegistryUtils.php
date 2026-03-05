<?php

namespace Sopheak\Core\Utilities;

use Sopheak\Core\Interfaces\RecordResourceInterface;
use stdClass;
use Exception;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sopheak\Core\Services\RecordConfigService;

class SchemaRegistryUtils
{
    private static array $cache = [];

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

    private static function getCompositeTypeFields(string $schema, string $typeName): array
    {
        $rows = DB::select(
            'select a.attname as field_name from pg_type t join pg_namespace n on n.oid = t.typnamespace join pg_class c on c.oid = t.typrelid join pg_attribute a on a.attrelid = c.oid where t.typtype = ? and n.nspname = ? and t.typname = ? and a.attnum > 0 and not a.attisdropped order by a.attnum',
            ['c', $schema, $typeName]
        );

        return array_map(static fn($row): string => (string) $row->field_name, $rows);
    }
}
