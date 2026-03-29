<?php

namespace Sopheak\Core\Console;

use Throwable;
use Illuminate\Console\Command;
use Sopheak\Core\Services\AttributeDiscoveryService;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * List all tables registered in the schema registry, including those
 * discovered via PHP 8 Attribute-based config.
 *
 * Usage:
 *   php artisan sp-laravel-api:list-tables
 *   php artisan sp-laravel-api:list-tables --source=attributes
 */
class ListTablesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sp-laravel-api:list-tables
                            {--source=all : Filter by source: all | file | attributes}';

    /**
     * The console command description.
     */
    protected $description = 'List all tables registered in the schema registry (file-based and/or attribute-discovered).';

    public function handle(): int
    {
        $source = strtolower((string) ($this->option('source') ?: 'all'));

        if (!in_array($source, ['all', 'file', 'attributes'], true)) {
            $this->error('Invalid --source value "' . $source . '". Use: all, file, or attributes.');

            return self::FAILURE;
        }

        $fileTables      = $this->getFileTables();
        $attributeTables = $this->getAttributeTables();

        $rows = [];

        if (in_array($source, ['all', 'file'], true)) {
            foreach ($fileTables as $key => $config) {
                $rows[] = $this->buildRow($key, $config, 'file');
            }
        }

        if (in_array($source, ['all', 'attributes'], true)) {
            foreach ($attributeTables as $key => $config) {
                $overridden = isset($fileTables[$key]) ? '⚠ overridden by file' : '';
                $rows[] = $this->buildRow($key, $config, 'attribute', $overridden);
            }
        }

        if (empty($rows)) {
            $this->warn('No tables found for source: ' . $source);

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Table', 'PMS Name', 'Auth R/W', 'Soft Del', 'Tenant', 'Source', 'Note'],
            $rows
        );

        $this->newLine();
        $this->line('Total: ' . count($rows) . ' table(s)');

        return self::SUCCESS;
    }

    /**
     * @return array<string, RecordTableType>
     */
    private function getFileTables(): array
    {
        SchemaRegistryUtils::refresh();

        $all = SchemaRegistryUtils::get();

        // Re-run discovery without attribute merging to isolate file tables
        // We achieve this by checking which keys were present before attribute merging.
        // Simplest approach: return all that have a key in config('record.tables').
        array_keys((array) config('record.tables', []));

        // Also include tables loaded from per-file includes (table_config_path)
        // The registry already merged those; we treat everything currently in the
        // registry as file-sourced to avoid a double-load.
        $result = [];
        foreach ($all as $key => $config) {
            $result[$key] = $config;
        }

        return $result;
    }

    /**
     * @return array<string, RecordTableType>
     */
    private function getAttributeTables(): array
    {
        try {
            return AttributeDiscoveryService::discover();
        } catch (Throwable $throwable) {
            $this->warn('Attribute discovery failed: ' . $throwable->getMessage());

            return [];
        }
    }

    /**
     * Build a table row for output.
     *
     * @return array<int, string>
     */
    private function buildRow(string $key, RecordTableType $config, string $source, string $note = ''): array
    {
        return [
            $key,
            $config->table ?? $key,
            is_array($config->pmsName) ? implode(',', $config->pmsName) : (string) ($config->pmsName ?? '-'),
            ($config->isAuthRead ? 'Y' : 'N') . '/' . ($config->isAuthWrite ? 'Y' : 'N'),
            $config->softDeletes ? 'yes' : 'no',
            $config->hasTenantId ? 'yes' : 'no',
            $source,
            $note,
        ];
    }
}
