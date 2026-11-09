<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use BackedEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionParameter;
use Sopheak\Core\Enums\RecordColumnTypeEnum;
use Sopheak\Core\Interfaces\RecordResourceInterface;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordColumnType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Throwable;

class SyncRecordColumnsCommand extends Command
{
    protected $signature = 'sp-laravel-api:sync-record-columns {--force : Force regeneration even if columns already exist} {--table= : Sync columns only for the specified table} {--no-enums : Write enum values as a list instead of generating PHP enum classes} {--enum-path= : Directory for generated enum classes (default: app/Enums/Records)} {--enum-namespace= : Namespace for generated enum classes (default: App\\Enums\\Records)}';

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
            $skippedTables = [];

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
                    // Table is defined inline in config/sp-record.php or elsewhere; we only manage per-file configs
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

                foreach ($columns as $colName => &$colMeta) {
                    if (!isset($colMeta['enum']) && isset($colMeta['type']) && is_string($colMeta['type'])) {
                        $parsed = SchemaRegistryUtils::parseEnumValues($colMeta['type']);
                        if (!empty($parsed)) {
                            $colMeta['enum'] = $parsed;
                        }
                    }

                    if (!isset($colMeta['enum']) && isset($config->columns[$colName]['enum']) && is_array($config->columns[$colName]['enum'])) {
                        $colMeta['enum'] = array_values($config->columns[$colName]['enum']);
                    }
                }

                unset($colMeta);

                if (!$this->option('no-enums')) {
                    $filePaths = isset($tableFiles[$tableName])
                        ? array_values(array_filter(array_column($tableFiles[$tableName], 'path'), is_string(...)))
                        : [];

                    foreach ($columns as $colName => &$colMeta) {
                        $existingClass = $config->columns[$colName]['enumClass'] ?? null;
                        if (is_array($colMeta['enum'] ?? null) && [] !== $colMeta['enum']) {
                            $colMeta['enumClass'] = $this->ensureEnumClass(
                                $actualTableName,
                                (string) $colName,
                                $colMeta['enum'],
                                is_string($existingClass) ? $existingClass : null,
                                $filePaths
                            );
                        }
                    }

                    unset($colMeta);
                }

                // 5. Update all corresponding config files
                $written = false;
                foreach ($tableFiles[$tableName] as $fileInfo) {
                    $this->lastFileWritten = false;
                    $this->updateConfigFile(
                        $fileInfo['path'],
                        $tableName,
                        $columns,
                        $fileInfo['mode'] === 'array'
                    );
                    $written = $written || $this->lastFileWritten;
                }

                if ($written) {
                    $updatedTables++;
                } else {
                    $skippedTables[] = $tableName;
                }
            }

            $this->info(sprintf('Finished updating columns for %d tables in config/records/tables.', $updatedTables));
            if ([] !== $skippedTables) {
                $this->warn(sprintf('Not updated (see the warnings above): %s', implode(', ', $skippedTables)));
            }

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

        [$arguments, $existingOffset] = $this->removeExistingColumnsArgument($arguments);

        // Put `columns:` back where it was, so a sync never reorders the
        // arguments of the file. A file without columns gets it before the
        // first of the later parameters (by position in the file), else last.
        $insertOffset = $existingOffset;

        if (null === $insertOffset) {
            $masked = $this->maskNonCode($arguments);
            foreach ($this->laterConstructorParams() as $name) {
                if (1 === preg_match('/(?<![A-Za-z0-9_])' . $name . '\s*:/', $masked, $m, PREG_OFFSET_CAPTURE)) {
                    $insertOffset = min($insertOffset ?? PHP_INT_MAX, (int) $m[0][1]);
                }
            }
        }

        if (null === $insertOffset) {
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
        $newContent = $this->ensureColumnTypeImports($newContent);

        file_put_contents($filePath, $newContent);
        $this->lastFileWritten = true;
        $this->info('  - Updated columns in file: ' . $filePath);
    }

    private function exportColumnsAsShortArray(array $columns, string $baseIndent): string
    {
        $innerIndent = $baseIndent . '  ';
        $lines = ['['];

        foreach ($columns as $name => $meta) {
            $value = is_array($meta) ? $this->exportColumnType($meta, $innerIndent) : $this->exportValue($meta, $innerIndent);
            $lines[] = $innerIndent . var_export($name, true) . ' => ' . $value . ',';
        }

        $lines[] = $baseIndent . ']';

        return implode("\n", $lines);
    }


    /**
     * @return array{0: string, 1: ?int}
     */
    private function removeExistingColumnsArgument(string $arguments): array
    {
        $masked = $this->maskNonCode($arguments);
        if (1 !== preg_match('/(?<!\w)columns\s*:/', $masked, $match, PREG_OFFSET_CAPTURE)) {
            return [$arguments, null];
        }

        /** @var int $columnsPos */
        $columnsPos = (int) $match[0][1];

        $beforeColumns = substr($arguments, 0, $columnsPos);
        $lineStartPos = strrpos($beforeColumns, "\n");
        $segmentStart = $lineStartPos === false ? 0 : $lineStartPos + 1;

        $arrayStart = strpos($masked, '[', $columnsPos);

        if ($arrayStart === false) {
            $commaPos = strpos($masked, ',', $columnsPos);
            if ($commaPos === false) {
                return [substr($arguments, 0, $segmentStart), $segmentStart];
            }

            $segmentEnd = $commaPos + 1;

            return [substr($arguments, 0, $segmentStart) . substr($arguments, $segmentEnd), $segmentStart];
        }

        $depth = 0;
        $len = strlen($arguments);
        $i = $arrayStart;

        for (; $i < $len; $i++) {
            $ch = $masked[$i];
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

        return [substr($arguments, 0, $segmentStart) . substr($arguments, $segmentEnd), $segmentStart];
    }

    /**
     * Names of RecordTableType constructor parameters declared after `columns`.
     *
     * @return list<string>
     */
    private function laterConstructorParams(): array
    {
        $parameters = (new ReflectionClass(RecordTableType::class))->getConstructor()?->getParameters() ?? [];
        $names = array_map(static fn(ReflectionParameter $p): string => $p->getName(), $parameters);
        $columnsIndex = array_search('columns', $names, true);

        return $columnsIndex !== false ? array_values(array_slice($names, $columnsIndex + 1)) : [];
    }

    /** Whether the last updateConfigFile() call wrote the file. */
    private bool $lastFileWritten = false;

    /** @var list<class-string> Enum classes the file being written refers to. */
    private array $enumImports = [];

    /**
     * Generate a backed enum class for a column's values, or reuse one.
     * An existing file is never overwritten: when its values differ from the
     * database the command only warns, since the class may hold your own code.
     *
     * @param array<int, string|int|float> $values
     * @param list<string> $filePaths
     * @return class-string
     */
    private function ensureEnumClass(string $table, string $column, array $values, ?string $existingClass = null, array $filePaths = []): string
    {
        $values = array_map(strval(...), array_values($values));

        if (null !== $existingClass && enum_exists($existingClass)) {
            $path = is_file($existingClass) ? $existingClass : (string) (new ReflectionClass($existingClass))->getFileName();
            if (is_file($path)) {
                $current = $this->enumFileValues((string) file_get_contents($path));
                if ($current !== $values) {
                    $this->warn(sprintf('  - Enum %s differs from %s.%s (database: %s); left unchanged, update it by hand.', $existingClass, $table, $column, implode(', ', $values)));
                }
            }

            return $existingClass;
        }

        // 1. Check existing enums imported in the target table config file(s)
        foreach ($filePaths as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $fileContent = (string) file_get_contents($filePath);
            if (preg_match_all('/^use\s+([^;\s]+)(?:\s+as\s+([^;\s]+))?\s*;/m', $fileContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $importedClass = trim($match[1]);
                    if (enum_exists($importedClass) && is_subclass_of($importedClass, BackedEnum::class) && $this->enumMatchesValues($importedClass, $values)) {
                        return $importedClass;
                    }
                }
            }
        }

        // 2. Check conventional existing enum classes in the application
        $appNamespace = rtrim(app()->getNamespace(), '\\');
        $studlyTable = Str::studly(Str::singular($table));
        $studlyCol = Str::studly($column);
        $candidates = [
            $appNamespace . '\\Enums\\' . $studlyTable . $studlyCol,
            $appNamespace . '\\Enums\\' . $studlyCol,
            $appNamespace . '\\Enums\\Records\\' . $studlyTable . $studlyCol,
            $appNamespace . '\\Enums\\Records\\' . $studlyCol,
        ];

        foreach ($candidates as $candidate) {
            if (enum_exists($candidate) && is_subclass_of($candidate, BackedEnum::class) && $this->enumMatchesValues($candidate, $values)) {
                return $candidate;
            }
        }

        $namespace = trim((string) ($this->option('enum-namespace') ?: rtrim(app()->getNamespace(), '\\') . '\\Enums\\Records'), '\\');
        $directory = (string) ($this->option('enum-path') ?: app_path('Enums/Records'));
        $class = $namespace . '\\' . $studlyTable . $studlyCol;
        $path = rtrim($directory, '/') . '/' . class_basename($class) . '.php';

        if (is_file($path)) {
            $current = $this->enumFileValues((string) file_get_contents($path));
            if ($current !== $values) {
                $this->warn(sprintf('  - Enum %s differs from %s.%s (database: %s); left unchanged, update it by hand.', $class, $table, $column, implode(', ', $values)));
            }

            return $class;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $this->enumSource($class, $table, $column, $values));
        $this->info('  - Generated enum ' . $class);

        return $class;
    }

    /**
     * @param class-string<BackedEnum> $enumClass
     * @param array<int, string|int|float> $values
     */
    private function enumMatchesValues(string $enumClass, array $values): bool
    {
        $cases = array_map(static fn(BackedEnum $case): string => (string) $case->value, $enumClass::cases());
        $strValues = array_map(strval(...), array_values($values));
        sort($cases);
        sort($strValues);

        return $cases === $strValues;
    }

    /**
     * @param list<string> $values
     */
    private function enumSource(string $class, string $table, string $column, array $values): string
    {
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $cases = [];
        $used = [];
        foreach ($values as $value) {
            $name = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value), '_'));
            if ('' === $name) {
                $name = 'EMPTY';
            }

            if (1 === preg_match('/^\d/', $name)) {
                $name = 'V_' . $name;
            }

            $base = $name;
            for ($i = 2; isset($used[$name]); ++$i) {
                $name = $base . '_' . $i;
            }

            $used[$name] = true;
            $cases[] = '    case ' . $name . ' = ' . var_export($value, true) . ';';
        }

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . $namespace . ";\n\n"
            . "/**\n * Values of `" . $table . '.' . $column . "`.\n *\n"
            . " * Generated by `php artisan sp-laravel-api:sync-record-columns`. Safe to edit:\n"
            . " * the command never overwrites this file; it warns when the database values change.\n */\n"
            . 'enum ' . class_basename($class) . ": string\n{\n" . implode("\n", $cases) . "\n}\n";
    }

    /**
     * The case values written in an enum file, in order.
     *
     * @return list<string>
     */
    private function enumFileValues(string $source): array
    {
        preg_match_all('/^\s*case\s+\w+\s*=\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|-?\d+)\s*;/m', $source, $matches);

        return array_map(static fn(string $literal): string => match ($literal[0]) {
            "'" => strtr(substr($literal, 1, -1), ["\\'" => "'", '\\\\' => '\\']),
            '"' => stripcslashes(substr($literal, 1, -1)),
            default => $literal,
        }, $matches[1]);
    }

    /**
     * One column as `new RecordColumnType(...)` with the standard parameters,
     * listing only those that differ from their defaults.
     *
     * @param array<string, mixed> $meta
     */
    private function exportColumnType(array $meta, string $indent): string
    {
        $column = RecordColumnType::fromArray($meta);
        $arguments = ['type' => 'RecordColumnTypeEnum::' . $column->type->name];

        $defaults = (new ReflectionClass(RecordColumnType::class))->getConstructor()?->getParameters() ?? [];
        foreach ($defaults as $parameter) {
            $name = $parameter->getName();
            if ('type' === $name) {
                continue;
            }

            if (!$parameter->isDefaultValueAvailable()) {
                continue;
            }

            $value = $column->{$name};
            if ($value === $parameter->getDefaultValue()) {
                continue;
            }

            if ('enum' === $name && is_string($value)) {
                $this->enumImports[] = $value;
                $arguments[$name] = class_basename($value) . '::class';
                continue;
            }

            $arguments[$name] = $this->exportValue($value, $indent . '    ');
        }

        $parts = [];
        foreach ($arguments as $name => $code) {
            $parts[] = $name . ': ' . $code;
        }

        $inline = 'new RecordColumnType(' . implode(', ', $parts) . ')';
        if (strlen($indent) + strlen($inline) <= 120 && !str_contains($inline, "\n")) {
            return $inline;
        }

        return "new RecordColumnType(\n" . $indent . '    ' . implode(",\n" . $indent . '    ', $parts) . ",\n" . $indent . ')';
    }

    /**
     * Add the imports the generated `RecordColumnType` code needs, after the
     * file's last `use` line (or after `<?php`).
     */
    private function ensureColumnTypeImports(string $content): string
    {
        $imports = array_values(array_unique([RecordColumnType::class, RecordColumnTypeEnum::class, ...$this->enumImports]));
        $this->enumImports = [];

        // Parse existing imports and their aliases/short-names
        $existingShortNames = [];
        if (preg_match_all('/^use\s+([^;\s]+)(?:\s+as\s+([^;\s]+))?\s*;/m', $content, $useMatches, PREG_SET_ORDER)) {
            foreach ($useMatches as $useMatch) {
                $fqcn = trim($useMatch[1]);
                $alias = isset($useMatch[2]) && '' !== trim($useMatch[2]) ? trim($useMatch[2]) : class_basename($fqcn);
                $existingShortNames[$alias] = $fqcn;
            }
        }

        $missing = [];
        foreach ($imports as $class) {
            $shortName = class_basename($class);
            if (in_array($class, $existingShortNames, true)) {
                continue;
            }

            if (isset($existingShortNames[$shortName])) {
                $alias = $shortName . 'RecordEnum';
                $existingShortNames[$alias] = $class;
                $missing[] = $class . ' as ' . $alias;
            } else {
                $existingShortNames[$shortName] = $class;
                $missing[] = $class;
            }
        }

        if ([] === $missing) {
            return $content;
        }

        $lines = implode('', array_map(static fn(string $import): string => 'use ' . $import . ";\n", $missing));
        if (preg_match_all('/^use\s+[^;]+;\n/m', $content, $matches, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($matches[0]);
            $offset = $last[1] + strlen($last[0]);

            return substr($content, 0, $offset) . $lines . substr($content, $offset);
        }

        return (string) preg_replace('/^<\?php\s*\n/', "<?php\n\n" . $lines . "\n", $content, 1);
    }

    private function exportValue(mixed $value, string $currentIndent): string
    {
        if (is_array($value)) {
            if ($this->isInlineableList($value)) {
                $elements = array_map(function ($item): string {
                    if (is_string($item)) {
                        return $this->exportString($item);
                    }

                    return var_export($item, true);
                }, $value);

                $inline = '[' . implode(', ', $elements) . ']';
                if (strlen($inline) <= 80) {
                    return $inline;
                }
            }

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

    /**
     * @param array<mixed> $array
     */
    private function isInlineableList(array $array): bool
    {
        if (!array_is_list($array)) {
            return false;
        }

        foreach ($array as $item) {
            if (!is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
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
        $masked = $this->maskNonCode($content);
        $openPos = strpos($masked, '(', $start);
        if ($openPos === false) {
            return null;
        }

        $depth = 0;
        $len = strlen($masked);
        for ($i = $openPos; $i < $len; $i++) {
            if ('(' === $masked[$i]) {
                ++$depth;
            } elseif (')' === $masked[$i] && 0 === --$depth) {
                return $i;
            }
        }

        return null;
    }

    /**
     * $code with every string literal and comment replaced by spaces (newlines
     * kept), so bracket matching and argument lookups ignore an apostrophe or a
     * bracket inside a comment or a string. Offsets are unchanged.
     */
    private function maskNonCode(string $code): string
    {
        $prefix = str_starts_with(ltrim($code), '<?php') ? '' : '<?php ';
        $masked = '';
        foreach (token_get_all($prefix . $code) as $token) {
            if (is_string($token)) {
                $masked .= $token;
                continue;
            }

            [$id, $text] = $token;
            $masked .= in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                ? (string) preg_replace('/[^\n]/', ' ', $text)
                : $text;
        }

        return substr($masked, strlen($prefix));
    }
}
