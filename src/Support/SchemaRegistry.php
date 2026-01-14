<?php

namespace Sopheak\Core\Support;

use Sopheak\Core\Interfaces\RecordResourceInterface;
use stdClass;
use Exception;
use Sopheak\Core\Types\RecordTableType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SchemaRegistry
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

        return $tables[$tableName] ?? null;
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

        $tables = config('record.tables', []);
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

            $config->primary_key ??= 'id';
            $config->has_tenant_id ??= true;

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
        $schema->soft_deletes = isset($columns['deleted_at']);

        return $schema;
    }

    /**
     * Bust the in-memory cache.
     */
    public static function refresh(): void
    {
        self::$cache = [];
    }

    /**
     * Register a table schema at runtime.
     */
    public static function register(string $tableName, string|object $config): void
    {
        if (is_string($config) || ($config instanceof RecordResourceInterface)) {
            self::$cache[$tableName] = $config;
            return;
        }

        if (empty(self::$cache)) {
            self::get();
        }

        if (empty($config->columns)) {
            $actualTableName = $config->table ?? $tableName;
            $config->columns = self::getTableColumns($actualTableName);
        }

        $config->primary_key ??= 'id';
        $config->has_tenant_id ??= true;

        self::$cache[$tableName] = $config;
    }

    /**
     * Clear cache for a specific table.
     */
    public static function clearTableCache(string $tableName): void
    {
        unset(self::$cache[$tableName]);
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
}
