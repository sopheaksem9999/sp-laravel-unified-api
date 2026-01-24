<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class GenerateRecordTablesFromDatabaseCommand extends Command
{
    protected $signature = 'sp-laravel-api:generate-all-configs';

    protected $description = 'Create RecordTableType config files for all database tables.';

    public function handle(): int
    {
        $this->info('Scanning database tables to generate RecordTableType configs.');

        $existingTables = array_keys(RecordConfigService::getTableConfig());

        $directory = config_path(RecordConfigService::tableConfigPath());
        $this->ensureDirectory($directory);

        $tenantColumn = RecordConfigService::tenantColumn();

        $allTables = $this->getDatabaseTables();
        if ($allTables === []) {
            $this->warn('No tables found in the database.');

            return self::SUCCESS;
        }

        $created = 0;

        foreach ($allTables as $tableName) {
            if (str_contains((string) $tableName, '_has')) {
                continue;
            }

            $configKey = $tableName;

            if (in_array($configKey, $existingTables, true)) {
                continue;
            }

            $filePath = $directory . DIRECTORY_SEPARATOR . $configKey . '.php';

            if (is_file($filePath)) {
                $this->line('Skipping existing config file: ' . $this->relativePath($filePath));

                continue;
            }

            $columns = SchemaRegistryUtils::getTableColumns($tableName);

            $hasTenant = isset($columns[$tenantColumn]);
            $softDeletes = isset($columns['deleted_at']);

            $relationships = $this->detectBelongsToRelationships($columns, $allTables);

            $pmsName = Str::singular($configKey);

            $contents = $this->buildConfigFileContents(
                name: $configKey,
                table: $tableName,
                pmsName: $pmsName,
                hasTenant: $hasTenant,
                softDeletes: $softDeletes,
                relationships: $relationships,
            );

            $this->writeFile($filePath, $contents);

            $this->info('Created RecordTableType config: ' . $this->relativePath($filePath));
            $created++;
        }

        $this->newLine();
        $this->info('Total new record table config files created: ' . $created);

        if ($created > 0) {
            $this->newLine();
            $this->info('Running sp-laravel-api:sync-record-columns --force to populate columns for generated configs...');
            $this->call('sp-laravel-api:sync-record-columns', ['--force' => true]);
        }

        return self::SUCCESS;
    }

    private function getDatabaseTables(): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name AS table_name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

            return array_map(static fn($row): string => (string) $row->table_name, $rows);
        }

        if ($driver === 'pgsql') {
            $rows = DB::select("SELECT tablename AS table_name FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')");

            return array_map(static fn($row): string => (string) $row->table_name, $rows);
        }

        $rows = DB::select('SHOW TABLES');
        $tables = [];

        foreach ($rows as $row) {
            $values = array_values((array) $row);
            if (isset($values[0])) {
                $tables[] = (string) $values[0];
            }
        }

        return $tables;
    }

    private function detectBelongsToRelationships(array $columns, array $allTables): array
    {
        $relationships = [];

        foreach (array_keys($columns) as $columnName) {
            if (!str_ends_with((string) $columnName, '_id')) {
                continue;
            }

            if ($columnName === 'id') {
                continue;
            }

            $base = substr((string) $columnName, 0, -3);
            if ($base === '') {
                continue;
            }

            $candidates = [
                $base,
                Str::plural($base),
                Str::snake($base),
                Str::plural(Str::snake($base)),
            ];

            $targetTable = null;

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $allTables, true)) {
                    $targetTable = $candidate;

                    break;
                }
            }

            if ($targetTable === null) {
                continue;
            }

            $alias = $base;

            if (isset($relationships[$alias])) {
                continue;
            }

            $relationships[$alias] = [
                'table' => $targetTable,
                'foreignKey' => $columnName,
                'ownerKey' => 'id',
            ];
        }

        return $relationships;
    }

    private function buildConfigFileContents(string $name, string $table, string $pmsName, bool $hasTenant, bool $softDeletes, array $relationships): string
    {
        $pmsLiteral = var_export($pmsName, true);
        $tableLiteral = var_export($table, true);
        $hasTenantLiteral = $hasTenant ? 'true' : 'false';
        $softDeletesLiteral = $softDeletes ? 'true' : 'false';

        $relationshipsCode = $this->buildRelationshipsCode($relationships);

        return <<<PHP
<?php

use Sopheak\\Core\\Types\\RecordBelongsToType;
use Sopheak\\Core\\Types\\RecordTablePublic;
use Sopheak\\Core\\Types\\RecordTableType;

return new RecordTableType(
    pmsName: {$pmsLiteral},
    table: {$tableLiteral},
    public: new RecordTablePublic(
        read: false,
        write: false,
    ),
    relationships: {$relationshipsCode},
    functions: [],
    softDeletes: {$softDeletesLiteral},
    hasTenantId: {$hasTenantLiteral},
);
PHP;
    }

    private function buildRelationshipsCode(array $relationships): string
    {
        if ($relationships === []) {
            return '[]';
        }

        $lines = ['['];

        foreach ($relationships as $alias => $rel) {
            $aliasLiteral = var_export($alias, true);
            $tableLiteral = var_export($rel['table'], true);
            $foreignKeyLiteral = var_export($rel['foreignKey'], true);
            $ownerKeyLiteral = var_export($rel['ownerKey'], true);

            $lines[] = '        ' . $aliasLiteral . ' => new RecordBelongsToType(';
            $lines[] = '            table: ' . $tableLiteral . ',';
            $lines[] = '            foreignKey: ' . $foreignKeyLiteral . ',';
            $lines[] = '            ownerKey: ' . $ownerKeyLiteral . ',';
            $lines[] = '        ),';
        }

        $lines[] = '    ]';

        return implode("\n", $lines);
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (file_exists($path) && !is_dir($path)) {
            throw new RuntimeException('Path exists and is not a directory: ' . $path);
        }

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create directory: ' . $path);
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && (!mkdir($dir, 0755, true) && !is_dir($dir))) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Failed to write file: ' . $path);
        }
    }

    private function relativePath(string $path): string
    {
        $base = base_path();
        if (str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return substr($path, strlen($base . DIRECTORY_SEPARATOR));
        }

        return $path;
    }
}
