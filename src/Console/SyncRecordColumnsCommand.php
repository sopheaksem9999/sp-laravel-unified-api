<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Illuminate\Console\Command;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Interfaces\RecordResourceInterface;
use Throwable;

class SyncRecordColumnsCommand extends Command
{
    protected $signature = 'sp-laravel-api:sync-record-columns {--force : Force regeneration even if columns already exist} {--table= : Sync columns only for the specified table}';

    protected $description = 'Populate RecordTableType columns in config/records/tables PHP files based on DB schema';

    public function handle(): int
    {
        try {
            $this->info('Scanning RecordTableType configuration and database schema...');

            $force = (bool) $this->option('force');
            $tableFilter = (string) ($this->option('table') ?? '');

            // 1. Build mapping from table name => config file(s) under config/records/tables
            $tablesDirectory = config_path(RecordConfigService::tableConfigPath());
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
                $this->warn('No config/records/tables/*.php files with RecordTableType found. Nothing to update.');

                return self::SUCCESS;
            }

            $tables = RecordConfigService::getTableConfig();

            if ($tableFilter !== '') {
                if (!array_key_exists($tableFilter, $tables)) {
                    $this->warn(sprintf('Table "%s" not found in configured tables. Nothing to update.', $tableFilter));

                    return self::SUCCESS;
                }

                $tables = [$tableFilter => $tables[$tableFilter]];
                $this->info(sprintf('Filtering to table: %s', $tableFilter));
            }

            $updatedTables = 0;

            $ignoredTables = [
                // Internal Package Tables
                'sp_attachments',
                'sp_attachment_links',
                'sp_attachment_folders',
                'sp_webhook_endpoints',
                'sp_webhook_subscriptions',
                'sp_webhook_deliveries',
                'sp_audit_logs',
                'audit_logs',
                'sp_roles',
                'sp_permissions',

                // Standard Laravel Tables
                'migrations',
                'failed_jobs',
                'jobs',
                'job_batches',
                'password_resets',
                'password_reset_tokens',
                'personal_access_tokens',
                'sessions',
                'cache',
                'cache_locks',

                // Laravel Passport / OAuth
                'oauth_auth_codes',
                'oauth_access_tokens',
                'oauth_clients',
                'oauth_personal_access_clients',
                'oauth_refresh_tokens',
            ];

            foreach ($tables as $tableName => $config) {
                if (in_array($tableName, $ignoredTables, true)) {
                    continue;
                }

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
                    $this->warn('Skipping invalid configuration for table: ' . $tableName);
                    continue;
                }

                if (!isset($tableFiles[$tableName])) {
                    // Table is defined inline in config/record.php or elsewhere; we only manage per-file configs
                    $this->line(sprintf('Skipping table %s: not found in config/records/tables/*.php', $tableName));
                    continue;
                }

                $actualTableName = $config->table ?? $tableName;
                $this->line(sprintf('Processing table: %s (%s)', $actualTableName, $tableName));

                // 3. Get columns from DB schema
                $columns = SchemaRegistryUtils::getTableColumns($actualTableName);

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

            $this->info(sprintf('Finished updating columns for %d tables in config/records/tables.', $updatedTables));

            return self::SUCCESS;

        } catch (Throwable $throwable) {
            $this->error('Failed to generate schema cache: ' . $throwable->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Inject columns into a RecordTableType constructor inside a config file.
     */
    protected function updateConfigFile(string $filePath, string $tableName, array $columns, bool $isArrayFile): void
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->warn('  - Failed to read file: ' . $filePath);
            return;
        }

        // Determine search pattern
        $needle = $isArrayFile
            ? "'" . $tableName . "' => new RecordTableType("
            : 'new RecordTableType(';

        $pos = strpos($content, $needle);
        if ($pos === false) {
            $this->warn(sprintf('  - Could not locate RecordTableType definition for table %s in %s', $tableName, $filePath));
            return;
        }

        // Find start of constructor arguments
        $start = strpos($content, 'new RecordTableType(', $pos);
        if ($start === false) {
            $this->warn(sprintf('  - Could not locate RecordTableType constructor for table %s in %s', $tableName, $filePath));
            return;
        }

        $endParen = $this->findMatchingParen($content, $start);
        if ($endParen === null) {
            $this->warn(sprintf('  - Could not determine end of RecordTableType constructor for table %s in %s', $tableName, $filePath));
            return;
        }

        $end = strpos($content, ';', $endParen);
        if ($end === false) {
            $this->warn(sprintf('  - Could not locate constructor terminator for table %s in %s', $tableName, $filePath));
            return;
        }

        $constructor = substr($content, $start, $end + 1 - $start);

        $usesNamedArguments = str_contains($constructor, 'pmsName:')
            || str_contains($constructor, 'table:')
            || str_contains($constructor, 'hasTenantId:')
            || str_contains($constructor, 'softDeletes:')
            || str_contains($constructor, 'public:')
            || str_contains($constructor, 'relationships:')
            || str_contains($constructor, 'columns:');

        if (!$usesNamedArguments) {
            $this->warn(sprintf('  - Skipping file %s: RecordTableType uses positional arguments; sync-record-columns currently supports only named arguments.', $filePath));
            return;
        }

        $openParenPos = strpos($constructor, '(');
        $closeParenPos = strrpos($constructor, ')');

        if ($openParenPos === false || $closeParenPos === false || $closeParenPos <= $openParenPos) {
            $this->warn(sprintf('  - Could not parse RecordTableType constructor arguments for table %s in %s', $tableName, $filePath));
            return;
        }

        $arguments = substr($constructor, $openParenPos + 1, $closeParenPos - $openParenPos - 1);

        $arguments = $this->removeExistingColumnsArgument($arguments);

        $laterParams = [
            'columnHiddens',
            'columnIndexes',
            'customAuditLog',
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

        $indent = '  ';
        $beforeInsert = substr($arguments, 0, $insertOffset);
        $lines = explode("\n", $beforeInsert);
        $closingIndent = '';
        $lastNewlinePos = strrpos($arguments, "\n");
        if ($lastNewlinePos !== false) {
            $tail = substr($arguments, $lastNewlinePos + 1);
            if (trim($tail) === '') {
                $closingIndent = $tail;
            }
        }

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (trim($lines[$i]) === '') {
                continue;
            }

            $lineIndent = strspn($lines[$i], ' ');
            $indent = $lineIndent > 0 ? str_repeat(' ', $lineIndent) : '  ';

            break;
        }

        $columnsCode = $this->exportColumnsAsShortArray($columns, $indent);
        $beforeInsert = rtrim(substr($arguments, 0, $insertOffset));
        $afterInsert = substr($arguments, $insertOffset);

        if ($beforeInsert !== '' && !str_ends_with($beforeInsert, ',')) {
            $beforeInsert .= ',';
        }

        $beforeInsert .= "\n" . $indent . 'columns: ' . $columnsCode . ',';

        $afterInsert = ltrim($afterInsert);
        if ($afterInsert !== '') {
            $newArguments = $beforeInsert . "\n" . $indent . $afterInsert;
        } else {
            $newArguments = $beforeInsert . "\n" . $closingIndent;
        }

        $newConstructor = substr($constructor, 0, $openParenPos + 1) . $newArguments . substr($constructor, $closeParenPos);

        $newContent = substr($content, 0, $start) . $newConstructor . substr($content, $end + 1);

        file_put_contents($filePath, $newContent);
        $this->info('  - Updated columns in file: ' . $filePath);
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

        while ($segmentEnd < $len && (in_array($arguments[$segmentEnd], [' ', "\t", "\r", "\n", ','], true))) {
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

        if (is_string($value)) {
            return $this->exportString($value);
        }

        return var_export($value, true);
    }

    private function exportString(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . str_replace('\\', '\\\\', $value) . "'";
        }

        if (!str_contains($value, '"')) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

            return '"' . $escaped . '"';
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

        return "'" . $escaped . "'";
    }

    private function findMatchingParen(string $content, int $start): ?int
    {
        $openPos = strpos($content, '(', $start);
        if ($openPos === false) {
            return null;
        }

        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $escaped = false;
        $len = strlen($content);

        for ($i = $openPos; $i < $len; $i++) {
            $ch = $content[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $escaped = true;
                continue;
            }

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }

            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }

            if ($inSingle) {
                continue;
            }

            if ($inDouble) {
                continue;
            }

            if ($ch === '(') {
                $depth++;
                continue;
            }

            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
