<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordConfigService;

class EnablePgsqlRlsCommand extends Command
{
    protected $signature = 'sp-laravel-api:enable-pgsql-rls
                            {--connection= : PGSQL connection name}
                            {--dry-run : Print SQL instead of executing}
                            {--table=* : Specific tables only (repeatable)}';

    protected $description = 'Enable PostgreSQL Row-Level Security on tenant-scoped tables';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('This command only supports PostgreSQL.');

            return Command::FAILURE;
        }

        $tenantColumn = RecordConfigService::tenantColumn();
        $columnType = RecordConfigService::tenantColumnType();
        $castType = $this->resolveCastType($columnType);

        $tables = $this->resolveTables();

        if (empty($tables)) {
            $this->warn('No tenant-scoped tables found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->line(sprintf('Found %d table(s) with tenant column <comment>%s</comment>.', count($tables), $tenantColumn));

        $dryRun = (bool) $this->option('dry-run');
        $connection = $this->option('connection');

        foreach ($tables as $table) {
            $statements = $this->buildStatements($table, $tenantColumn, $castType);

            foreach ($statements as $sql) {
                if ($dryRun) {
                    $this->line($sql);
                } else {
                    DB::connection($connection)->statement($sql);
                    $this->info(sprintf('Applied RLS on <comment>%s</comment>.', $table));
                }
            }
        }

        if ($dryRun) {
            $this->info('Dry-run complete. No changes were made.');
        } else {
            $this->info('RLS enabled successfully on all tenant-scoped tables.');
        }

        return Command::SUCCESS;
    }

    protected function resolveTables(): array
    {
        $tables = [];
        $tenantColumn = RecordConfigService::tenantColumn();
        $specific = (array) $this->option('table');

        $allConfigs = RecordConfigService::getTableConfig();

        foreach ($allConfigs as $table => $config) {
            if (is_array($config)) {
                continue;
            }

            if (!is_object($config)) {
                continue;
            }

            $hasTenantId = (bool) ($config->hasTenantId ?? false);

            if (!$hasTenantId) {
                continue;
            }

            if (!empty($specific) && !in_array($table, $specific, true)) {
                continue;
            }

            if (!Schema::hasColumn($table, $tenantColumn)) {
                $this->warn(sprintf('Skipping <comment>%s</comment>: column <comment>%s</comment> does not exist.', $table, $tenantColumn));

                continue;
            }

            $tables[] = $table;
        }

        sort($tables);

        return $tables;
    }

    /**
     * @return array<int, string>
     */
    protected function buildStatements(string $table, string $tenantColumn, string $castType): array
    {
        return [
            sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY;', $table),
            sprintf(
                "CREATE POLICY tenant_isolation ON %s"
                . " USING (%s = current_setting('app.tenant_id', true)::%s"
                . " OR current_setting('app.tenant_id', true) IS NULL);",
                $table,
                $tenantColumn,
                $castType
            ),
        ];
    }

    protected function resolveCastType(string $columnType): string
    {
        return match ($columnType) {
            'integer', 'bigint' => 'bigint',
            'uuid' => 'uuid',
            default => 'text',
        };
    }
}
