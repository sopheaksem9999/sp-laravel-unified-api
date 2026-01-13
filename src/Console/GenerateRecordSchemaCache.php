<?php

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Interfaces\RecordResourceInterface;
use Throwable;

class GenerateRecordSchemaCache extends Command
{
    protected $signature = 'sp-laravel-api:sync-record-columns {--force : Force regeneration even if columns already exist}';
    protected $description = 'Populate RecordTableType columns in config/record/tables PHP files based on DB schema';

    public function handle(): int
    {
        try {
            $this->info('Scanning RecordTableType configuration and database schema...');

            $force = (bool) $this->option('force');

            // 1. Build mapping from table name => config file(s) under config/record/tables
            $tablesDirectory = config_path('record/tables');
            $tableFiles = [];

            if (is_dir($tablesDirectory)) {
                foreach (glob($tablesDirectory . '/*.php') as $path) {
                    $config = require $path;

                    if ($config instanceof RecordTableType) {
                        // Single table per file, filename is the key
                        $name = pathinfo($path, PATHINFO_FILENAME);
                        $tableFiles[$name][] = [
                            'path' => $path,
                            'mode' => 'single',
                        ];
                    } elseif (is_array($config)) {
                        foreach ($config as $name => $tableConfig) {
                            if ($tableConfig instanceof RecordTableType) {
                                $tableFiles[$name][] = [
                                    'path' => $path,
                                    'mode' => 'array',
                                ];
                            }
                        }
                    }
                }
            }

            if (empty($tableFiles)) {
                $this->warn('No config/record/tables/*.php files with RecordTableType found. Nothing to update.');

                return self::SUCCESS;
            }

            $tables = config('record.tables', []);
            $updatedTables = 0;

            foreach ($tables as $tableName => $config) {
                // 2. Resolve RecordTableType instance from config
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
                    $this->warn("Skipping invalid configuration for table: {$tableName}");
                    continue;
                }

                if (!isset($tableFiles[$tableName])) {
                    // Table is defined inline in config/record.php or elsewhere; we only manage per-file configs
                    $this->line("Skipping table {$tableName}: not found in config/record/tables/*.php");
                    continue;
                }

                $actualTableName = $config->table ?? $tableName;
                $this->line("Processing table: {$actualTableName} ({$tableName})");

                // 3. Get columns from DB schema
                $columns = \Sopheak\Core\Support\SchemaRegistry::getTableColumns($actualTableName);

                if (empty($columns)) {
                    $this->warn("  - No columns found or table does not exist in DB.");
                    continue;
                }

                $this->info('  - Found ' . count($columns) . ' columns.');
            
                // 5. Update all corresponding config files
                foreach ($tableFiles[$tableName] as $fileInfo) {
                    $this->updateConfigFile(
                        $fileInfo['path'],
                        $tableName,
                        $columns,
                        $fileInfo['mode'] === 'array'
                    );
                }

                $updatedTables++;
            }

            $this->info("Finished updating columns for {$updatedTables} tables in config/record/tables.");

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->error('Failed to generate schema cache: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Inject columns into a RecordTableType constructor inside a config file.
     *
     * @param string $filePath
     * @param string $tableName
     * @param array $columns
     * @param bool $isArrayFile
     */
    protected function updateConfigFile(string $filePath, string $tableName, array $columns, bool $isArrayFile): void
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->warn("  - Failed to read file: {$filePath}");
            return;
        }

        // Determine search pattern
        $needle = $isArrayFile
            ? "'" . $tableName . "' => new RecordTableType("
            : 'new RecordTableType(';

        $pos = strpos($content, $needle);
        if ($pos === false) {
            $this->warn("  - Could not locate RecordTableType definition for table {$tableName} in {$filePath}");
            return;
        }

        // Find start of constructor arguments
        $start = strpos($content, 'new RecordTableType(', $pos);
        if ($start === false) {
            $this->warn("  - Could not locate RecordTableType constructor for table {$tableName} in {$filePath}");
            return;
        }

        // Find end of constructor: first occurrence of ');' after start
        $end = strpos($content, ');', $start);
        if ($end === false) {
            $this->warn("  - Could not determine end of RecordTableType constructor for table {$tableName} in {$filePath}");
            return;
        }

        $constructor = substr($content, $start, $end + 2 - $start);

        $usesNamedArguments = str_contains($constructor, 'pms_name:')
            || str_contains($constructor, 'table:')
            || str_contains($constructor, 'has_tenant_id:')
            || str_contains($constructor, 'soft_deletes:')
            || str_contains($constructor, 'public:')
            || str_contains($constructor, 'relationships:')
            || str_contains($constructor, 'columns:');

        if (!$usesNamedArguments) {
            $this->warn("  - Skipping file {$filePath}: RecordTableType uses positional arguments; sync-record-columns currently supports only named arguments.");
            return;
        }

        $openParenPos = strpos($constructor, '(');
        $closeParenPos = strrpos($constructor, ')');

        if ($openParenPos === false || $closeParenPos === false || $closeParenPos <= $openParenPos) {
            $this->warn("  - Could not parse RecordTableType constructor arguments for table {$tableName} in {$filePath}");
            return;
        }

        $arguments = substr($constructor, $openParenPos + 1, $closeParenPos - $openParenPos - 1);

        $arguments = $this->removeExistingColumnsArgument($arguments);

        $laterParams = [
            'column_hiddens',
            'fulltext_indexes',
            'auditLogFn',
            'createValidator',
            'updateValidator',
            'deleteValidator',
            'beforeRead',
            'afterRead',
            'beforeCreate',
            'afterCreate',
            'beforeUpdate',
            'afterUpdate',
            'beforeDelete',
            'afterDelete',
        ];

        $insertOffset = null;

        foreach ($laterParams as $name) {
            $pattern = $name . ':';
            $posName = strpos($arguments, $pattern);
            if ($posName !== false) {
                $insertOffset = $posName;
                break;
            }
        }

        if ($insertOffset === null) {
            $insertOffset = strlen($arguments);
        }

        $indent = '        ';
        $beforeInsert = substr($arguments, 0, $insertOffset);
        $lastNewlinePos = strrpos($beforeInsert, "\n");

        if ($lastNewlinePos !== false) {
            $indent = '';
            $i = $lastNewlinePos + 1;
            $len = strlen($arguments);

            while ($i < $len && $arguments[$i] === ' ') {
                $indent .= ' ';
                $i++;
            }

            if ($indent === '') {
                $indent = '  ';
            }
        }

        $columnsCode = $this->exportColumnsAsShortArray($columns, $indent);
        $isInsertingAtEnd = $insertOffset === strlen($arguments);

        if ($isInsertingAtEnd) {
            $trimmed = rtrim($arguments);

            if ($trimmed !== '') {
                $lastChar = substr($trimmed, -1);

                if ($lastChar !== ',' && $lastChar !== '(') {
                    $lastCharPos = strlen($trimmed) - 1;
                    $arguments = substr($arguments, 0, $lastCharPos + 1) . ',' . substr($arguments, $lastCharPos + 1);
                }
            }

            $insertOffset = strlen($arguments);
        }

        // $insert = "\n" . $indent . 'columns: ' . $columnsCode . ',';
        $insert = $indent . 'columns: ' . $columnsCode . ','. "\n";

        $newArguments = substr($arguments, 0, $insertOffset) . $insert . substr($arguments, $insertOffset);

        $newConstructor = substr($constructor, 0, $openParenPos + 1) . $newArguments . substr($constructor, $closeParenPos);

        $newContent = substr($content, 0, $start) . $newConstructor . substr($content, $end + 2);

        file_put_contents($filePath, $newContent);
        $this->info("  - Updated columns in file: {$filePath}");
    }

    private function exportColumnsAsShortArray(array $columns, string $baseIndent): string
    {
        $innerIndent = $baseIndent . '  ';
        $lines = ['['];

        foreach ($columns as $name => $meta) {
            $value = $this->exportValue($meta, $innerIndent);
            $lines[] = $innerIndent . var_export($name, true) . ' => ' . $value . ',';
        }

        $lines[] = $baseIndent . ']';

        return implode("\n", $lines);
    }

    private function removeExistingColumnsArgument(string $arguments): string
    {
        $columnsPos = strpos($arguments, 'columns:');
        if ($columnsPos === false) {
            return $arguments;
        }

        $beforeColumns = substr($arguments, 0, $columnsPos);
        $lineStartPos = strrpos($beforeColumns, "\n");
        $segmentStart = $lineStartPos === false ? 0 : $lineStartPos + 1;

        $arrayStart = strpos($arguments, '[', $columnsPos);

        if ($arrayStart === false) {
            $commaPos = strpos($arguments, ',', $columnsPos);
            if ($commaPos === false) {
                return substr($arguments, 0, $segmentStart);
            }

            $segmentEnd = $commaPos + 1;

            return substr($arguments, 0, $segmentStart) . substr($arguments, $segmentEnd);
        }

        $depth = 0;
        $len = strlen($arguments);
        $i = $arrayStart;

        for (; $i < $len; $i++) {
            $ch = $arguments[$i];
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    $i++;
                    break;
                }
            }
        }

        $segmentEnd = $i;

        while ($segmentEnd < $len && ($arguments[$segmentEnd] === ' ' || $arguments[$segmentEnd] === "\t" || $arguments[$segmentEnd] === "\r" || $arguments[$segmentEnd] === "\n" || $arguments[$segmentEnd] === ',')) {
            $segmentEnd++;
        }

        return substr($arguments, 0, $segmentStart) . substr($arguments, $segmentEnd);
    }

    private function exportValue(mixed $value, string $currentIndent): string
    {
        if (is_array($value)) {
            $nextIndent = $currentIndent . '  ';
            $lines = ['['];

            foreach ($value as $key => $item) {
                $itemValue = $this->exportValue($item, $nextIndent);
                if (is_int($key)) {
                    $lines[] = $nextIndent . $itemValue . ',';
                } else {
                    $lines[] = $nextIndent . var_export($key, true) . ' => ' . $itemValue . ',';
                }
            }

            $lines[] = $currentIndent . ']';

            return implode("\n", $lines);
        }

        return var_export($value, true);
    }
}
