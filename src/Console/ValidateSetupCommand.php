<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Sopheak\Core\Config\ConfigNamespaceBridge;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class ValidateSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sp-laravel-api:validate
                            {--fix : Attempt to fix common issues automatically}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate SP Laravel API package setup and configuration';

    /**
     * Validation results.
     */
    private array $results = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Validating SP Laravel API Setup...');
        $this->newLine();

        // Run all validation checks
        $this->validateConfigFiles();
        $this->validateRecordConfigDirectories();
        $this->validateDatabaseConnection();
        $this->validateDatabaseCompatibility();
        $this->validateMigrations();
        $this->validateEnvironmentVariables();
        $this->validatePermissions();
        $this->validateSchemaRegistryUtils();
        $this->validateColumnDrift();
        $this->validateRoutes();
        $this->validatePackageLimits();
        $this->validateRateLimiters();
        $this->validateWebhooks();
        $this->validateMcpConfiguration();

        // Display results
        $this->displayResults();

        // Return appropriate exit code
        return $this->hasErrors() ? 1 : 0;
    }

    /**
     * Validate configuration files exist and are properly configured.
     *
     * Both filenames count. The package's five previously-unprefixed config
     * files ship as `sp-*.php` now, but an old-named file on disk still loads
     * and still works, so a client who has migrated and a client who has not
     * must both validate clean. Checking only one spelling would have failed
     * whichever population it did not name — and this is the command the
     * upgrade guide tells the reader to run to confirm the migration worked.
     */
    private function validateConfigFiles(): void
    {
        $this->info('📋 Checking Configuration Files...');

        // Derived from the bridge's own rename table so a future rename cannot
        // leave this check naming files nothing publishes any more.
        $legacyNames = [];
        foreach (ConfigNamespaceBridge::RENAMES as $canonical => $published) {
            $legacyNames[$published . '.php'] = $canonical . '.php';
        }

        $configFiles = [
            'sp-record.php',
            'sp-audit.php',
            'sp-attachments.php',
            'sp-webhooks.php',
            'sp-permissions.php',
            'sp-laravel-api.php',
        ];

        foreach ($configFiles as $file) {
            $legacyName = $legacyNames[$file] ?? null;
            $found = null;

            if (File::exists(config_path($file))) {
                $found = $file;
            } elseif ($legacyName !== null && File::exists(config_path($legacyName))) {
                $found = $legacyName;
            }

            if ($found === null) {
                $this->addResult('❌', 'Missing config file: ' . $file, 'error');

                if ($this->option('fix')) {
                    $this->info(sprintf('🔧 Attempting to publish %s...', $file));
                    $this->call('sp-laravel-api:setup');
                }

                continue;
            }

            $this->addResult('✅', 'Config file exists: ' . $found, 'success');

            if ($found !== $file) {
                $this->addResult('⚠️', sprintf(
                    'Deprecated config filename: config/%s — rename it to config/%s',
                    $found,
                    $file
                ), 'warning');
            }

            // Validate config content
            try {
                $config = include config_path($found);
                if (is_array($config) && !empty($config)) {
                    $this->addResult('✅', 'Config file valid: ' . $found, 'success');
                } else {
                    $this->addResult('⚠️', 'Config file empty or invalid: ' . $found, 'warning');
                }
            } catch (Exception $e) {
                $this->addResult('❌', sprintf('Config file syntax error: %s - %s', $found, $e->getMessage()), 'error');
            }
        }
    }

    private function validateRecordConfigDirectories(): void
    {
        $this->info('📁 Checking Record Config Directories...');

        $tablesPath = config_path(RecordConfigService::tableConfigPath());
        if (File::isDirectory($tablesPath)) {
            $this->addResult('✅', 'Table config directory exists: ' . str_replace(base_path() . '/', '', $tablesPath), 'success');
        } else {
            $this->addResult('❌', 'Missing table config directory: ' . str_replace(base_path() . '/', '', $tablesPath), 'error');
            if ($this->option('fix')) {
                $this->info('🔧 Attempting to create missing record directories...');
                $this->call('sp-laravel-api:setup', ['--force' => false]);
            }
        }

        // Both accepted spellings come from RecordConfigService, the single
        // source of truth the runtime scan and config/sp-record.php's
        // autoloaded scan already share — this used to be a third, independent
        // hardcoded copy of the same list.
        $globalFunctionNames = RecordConfigService::globalFunctionDirectoryNames();
        $globalFunctionPaths = array_map(config_path(...), $globalFunctionNames);

        $existingGlobalFunctionPaths = array_values(array_filter($globalFunctionPaths, static fn(string $path): bool => File::isDirectory($path)));
        if ($existingGlobalFunctionPaths !== []) {
            foreach ($existingGlobalFunctionPaths as $globalFunctionsPath) {
                $this->addResult('✅', 'Global function directory exists: ' . str_replace(base_path() . '/', '', $globalFunctionsPath), 'success');
                $this->validateGlobalFunctionConfigFiles($globalFunctionsPath);
            }
        } else {
            $this->addResult('⚠️', 'Missing global function directory: ' . implode(' or ', array_map(
                static fn(string $name): string => 'config/' . $name,
                $globalFunctionNames
            )), 'warning');
            $this->addResult('ℹ️', 'Create config/' . end($globalFunctionNames) . '/*.php files or run: php artisan sp-laravel-api:setup', 'info');
            if ($this->option('fix')) {
                $this->info('🔧 Attempting to create missing global function directory...');
                $this->call('sp-laravel-api:setup', ['--force' => false]);
            }
        }
    }

    private function validateGlobalFunctionConfigFiles(string $globalFunctionsPath): void
    {
        $files = File::allFiles($globalFunctionsPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $fullPath = $file->getPathname();
            $relativePath = str_replace(base_path() . '/', '', $fullPath);

            try {
                $config = require $fullPath;
            } catch (Exception $exception) {
                $this->addResult('❌', sprintf('Invalid global function config %s: %s', $relativePath, $exception->getMessage()), 'error');
                continue;
            }

            if (!is_array($config)) {
                $this->addResult('❌', 'Global function config must return array: ' . $relativePath, 'error');
                continue;
            }

            $group = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $keysWithoutGroupPrefix = array_filter(
                array_keys($config),
                static fn(int|string $key): bool => is_string($key) && $key !== '' && !str_contains($key, '/')
            );

            if ($keysWithoutGroupPrefix !== []) {
                $this->addResult('✅', 'Global function group "' . $group . '" will prefix endpoint keys in ' . $relativePath, 'success');
            } else {
                $this->addResult('✅', 'Global function config loaded: ' . $relativePath, 'success');
            }
        }
    }

    /**
     * Validate database connection.
     */
    private function validateDatabaseConnection(): void
    {
        $this->info('🗄️ Checking Database Connection...');

        try {
            DB::connection()->getPdo();
            $this->addResult('✅', 'Database connection successful', 'success');

            $driver = DB::getDriverName();
            $this->addResult('ℹ️', 'Database driver: ' . $driver, 'info');
        } catch (Exception $exception) {
            $this->addResult('❌', 'Database connection failed: ' . $exception->getMessage(), 'error');
        }
    }

    /**
     * Validate database compatibility with SchemaRegistryUtils.
     */
    private function validateDatabaseCompatibility(): void
    {
        $this->info('🔧 Checking Database Compatibility...');

        try {
            $driver = DB::getDriverName();

            if (in_array($driver, ['mysql', 'sqlite', 'pgsql'])) {
                $this->addResult('✅', sprintf("Database driver '%s' is supported", $driver), 'success');

                // Test SchemaRegistryUtils with a simple table
                $tables = DB::select($this->getTableListQuery($driver));

                if (!empty($tables)) {
                    $tableName = $this->getFirstTableName($tables, $driver);

                    // Test column retrieval
                    $columns = $this->testColumnRetrieval($tableName);

                    if (!empty($columns)) {
                        $this->addResult('✅', sprintf("SchemaRegistryUtils can read table columns for '%s'", $tableName), 'success');
                    } else {
                        $this->addResult('⚠️', sprintf("SchemaRegistryUtils returned empty columns for '%s'", $tableName), 'warning');
                    }
                } else {
                    $this->addResult('⚠️', 'No tables found in database', 'warning');
                }
            } else {
                $this->addResult('⚠️', sprintf("Database driver '%s' may not be fully supported", $driver), 'warning');
            }
        } catch (Exception $exception) {
            $this->addResult('❌', 'Database compatibility check failed: ' . $exception->getMessage(), 'error');
        }
    }

    /**
     * Report table configs that declare fewer columns than the table actually has.
     *
     * A column missing from `columns` is not an error — it is how you keep a
     * column out of the query surface on purpose. It is worth surfacing because
     * the failure mode when it is *accidental* is silent: `columns` is the
     * allow-list for sorting, filtering, `select` and `group_by`, so a
     * `?sortby=<undeclared>` is discarded with no error and the query falls back
     * to the default order. That is how the 2026-08-16 report reached a client —
     * the shipped attachment configs had drifted from their own migrations and
     * lists came back in primary-key order.
     *
     * `created_at`/`updated_at` are recovered automatically at query time (see
     * QueryBuilderFiltersUtils::withRecoverableSystemColumns()), so they are
     * reported here only as a nudge to make the config honest. Every other
     * column is listed because nothing recovers it.
     */
    private function validateColumnDrift(): void
    {
        $this->info('🧭 Checking Config/Database Column Drift...');

        try {
            $tenantColumn = RecordConfigService::tenantColumn();
            $checked = 0;
            $drifted = 0;

            foreach (SchemaRegistryUtils::get() as $table => $config) {
                $tableName = (string) $table;
                $declared = array_keys($config->columns ?? []);

                if ([] === $declared) {
                    continue;
                }

                $physical = array_keys(SchemaRegistryUtils::getTableColumns($tableName));
                if ([] === $physical) {
                    continue;
                }

                ++$checked;

                // The tenant column is resolved server-side and is deliberately
                // never part of the client-facing query surface.
                $missing = array_values(array_diff($physical, $declared, [$tenantColumn]));
                if ([] === $missing) {
                    continue;
                }

                ++$drifted;
                $this->addResult('⚠️', sprintf(
                    "Table '%s' has columns not declared in its config: %s — these cannot be sorted or filtered on",
                    $tableName,
                    implode(', ', $missing)
                ), 'warning');
            }

            if (0 === $checked) {
                $this->addResult('ℹ️', 'No configured tables found in the database to compare', 'info');

                return;
            }

            if (0 === $drifted) {
                $this->addResult('✅', sprintf('All %d configured table(s) declare every column their table has', $checked), 'success');

                return;
            }

            $this->addResult('ℹ️', 'Declare the columns you want queryable, or run: php artisan sp-laravel-api:sync-record-columns', 'info');
        } catch (Exception $exception) {
            $this->addResult('⚠️', 'Column drift check failed: ' . $exception->getMessage(), 'warning');
        }
    }

    /**
     * Validate required migrations are run.
     */
    private function validateMigrations(): void
    {
        $this->info('📊 Checking Migrations...');

        try {
            // Check if migrations table exists
            $tables = DB::select($this->getTableListQuery(DB::getDriverName()));
            $tableNames = array_map(fn($table): string => $this->getTableNameFromResult($table, DB::getDriverName()), $tables);

            if (in_array('migrations', $tableNames)) {
                $this->addResult('✅', 'Migrations table exists', 'success');

                // Check for sp_audit_logs table
                if (in_array('sp_audit_logs', $tableNames)) {
                    $this->addResult('✅', 'Audit logs table exists', 'success');
                } else {
                    $this->addResult('⚠️', 'Audit logs table missing - run: php artisan migrate', 'warning');
                }
            } else {
                $this->addResult('❌', 'Migrations table missing - run: php artisan migrate:install', 'error');
            }
        } catch (Exception $exception) {
            $this->addResult('❌', 'Migration check failed: ' . $exception->getMessage(), 'error');
        }
    }

    /**
     * Validate environment variables.
     */
    private function validateEnvironmentVariables(): void
    {
        $this->info('🌍 Checking Environment Variables...');

        $envVars = [
            'RECORD_MAX_DEPTH' => ['default' => '3', 'required' => false],
            'RECORD_CACHE_TTL' => ['default' => '3600', 'required' => false],
            'AUDIT_LOG_ENABLED' => ['default' => 'true', 'required' => false],
            'AUDIT_LOG_RETENTION_DAYS' => ['default' => '365', 'required' => false],
        ];

        foreach ($envVars as $var => $config) {
            $value = env($var);

            if ($value !== null) {
                $this->addResult('✅', sprintf('%s is set: %s', $var, $value), 'success');
            } else {
                $message = $var . ' not set';
                if (isset($config['default'])) {
                    $message .= sprintf(' (using default: %s)', $config['default']);
                }

                $type = $config['required'] ? 'warning' : 'info';
                $icon = $config['required'] ? '⚠️' : 'ℹ️';
                $this->addResult($icon, $message, $type);
            }
        }
    }

    /**
     * Validate permissions setup.
     */
    private function validatePermissions(): void
    {
        $this->info('🔐 Checking Permissions Setup...');

        try {
            $tables = DB::select($this->getTableListQuery(DB::getDriverName()));
            $tableNames = array_map(fn($table): string => $this->getTableNameFromResult($table, DB::getDriverName()), $tables);

            $spBuiltInTables = ['sp_permissions', 'sp_roles', 'sp_role_permissions', 'sp_model_has_roles', 'sp_model_permissions'];
            $spMissingTables = array_diff($spBuiltInTables, $tableNames);

            if (empty($spMissingTables)) {
                $this->addResult('✅', 'Built-in permission tables (sp_*) exist', 'success');

                if (config('permissions.enabled', false)) {
                    $this->addResult('✅', 'Built-in permission system is enabled', 'success');
                } else {
                    $this->addResult('ℹ️', 'Built-in permission system is disabled (config/sp-permissions.php enabled=false)', 'info');
                    $this->addResult('ℹ️', 'Set SP_PERMISSION_ENABLED=true or permission.enabled=true to activate', 'info');
                }
            } else {
                $this->addResult('⚠️', 'Missing built-in permission tables: ' . implode(', ', $spMissingTables), 'warning');
                $this->addResult('ℹ️', 'Run: php artisan migrate to create the permission tables', 'info');
            }

            $legacyTables = ['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'];
            $existingLegacy = array_intersect($legacyTables, $tableNames);

            if (!empty($existingLegacy)) {
                $this->addResult('ℹ️', 'Found legacy permission tables (' . implode(', ', $existingLegacy) . ')', 'info');

                if (config('permissions.enabled', false)) {
                    $this->addResult('ℹ️', 'Run: php artisan sp-laravel-api:migrate-from-legacy to migrate data', 'info');
                }
            }
        } catch (Exception $exception) {
            $this->addResult('❌', 'Permission check failed: ' . $exception->getMessage(), 'error');
        }
    }

    /**
     * Validate SchemaRegistryUtils functionality.
     */
    private function validateSchemaRegistryUtils(): void
    {
        $this->info('🏗️ Checking SchemaRegistryUtils...');

        try {
            // Test SchemaRegistryUtils::get()
            $schema = SchemaRegistryUtils::get();

            if (is_array($schema)) {
                $this->addResult('✅', 'SchemaRegistryUtils::get() returns array', 'success');

                if (!empty($schema)) {
                    $tableCount = count($schema);
                    $this->addResult('✅', sprintf('SchemaRegistryUtils loaded %d table(s)', $tableCount), 'success');

                    if ($this->option('verbose')) {
                        foreach (array_keys($schema) as $tableName) {
                            $this->addResult('ℹ️', '  - ' . $tableName, 'info');
                        }
                    }
                } else {
                    $this->addResult('⚠️', 'SchemaRegistryUtils returned empty schema - check config/sp-record.php', 'warning');
                }
            } else {
                $this->addResult('❌', 'SchemaRegistryUtils::get() did not return array', 'error');
            }
        } catch (Exception $exception) {
            $this->addResult('❌', 'SchemaRegistryUtils check failed: ' . $exception->getMessage(), 'error');
        }
    }

    /**
     * Validate API routes are registered.
     */
    private function validateRoutes(): void
    {
        $this->info('🛣️ Checking API Routes...');

        try {
            $router = app('router');
            $routes = $router->getRoutes();

            $apiPrefix = RecordConfigService::apiPrefix();
            $hasApiRoutes = false;

            foreach ($routes as $route) {
                $uri = $route->uri();
                if (str_starts_with((string) $uri, $apiPrefix . '/')) {
                    $hasApiRoutes = true;
                    break;
                }
            }

            if ($hasApiRoutes) {
                $this->addResult('✅', 'API routes registered with prefix: ' . $apiPrefix, 'success');
            } else {
                $this->addResult('⚠️', 'No API routes found with prefix: ' . $apiPrefix, 'warning');
            }
        } catch (Exception $exception) {
            $this->addResult('❌', 'Route check failed: ' . $exception->getMessage(), 'error');
        }
    }

    /**
     * Validate package configuration limits.
     */
    private function validatePackageLimits(): void
    {
        $this->info('⚖️ Checking Package Configuration Limits...');

        // Check depth limit
        $maxDepth = config('record.max_depth');
        if (is_int($maxDepth) && $maxDepth > 0) {
            $this->addResult('✅', 'max_depth is configured properly: ' . $maxDepth, 'success');
        } else {
            $this->addResult('⚠️', 'max_depth is missing or invalid in config/sp-record.php', 'warning');
        }

        // Check rate_limits
        $rateLimits = config('record.rate_limits');
        if (is_array($rateLimits)) {
            $this->addResult('✅', 'rate_limits array is present in config/sp-record.php', 'success');
        } else {
            $this->addResult('ℹ️', 'rate_limits is not configured (using defaults)', 'info');
        }
    }

    private function validateWebhooks(): void
    {
        $this->info('🪝 Checking Webhooks Configuration...');

        $enabled = config('webhooks.enabled', false);

        if ($enabled) {
            $this->addResult('✅', 'Webhooks module is enabled', 'success');

            // Check if tables exist
            try {
                $tables = DB::select($this->getTableListQuery(DB::getDriverName()));
                $tableNames = array_map(fn($table): string => $this->getTableNameFromResult($table, DB::getDriverName()), $tables);

                $webhookTables = ['sp_webhook_endpoints', 'sp_webhook_subscriptions', 'sp_webhook_deliveries'];
                $missingTables = array_diff($webhookTables, $tableNames);

                if (empty($missingTables)) {
                    $this->addResult('✅', 'Webhook tables exist', 'success');
                } else {
                    $this->addResult('❌', 'Missing webhook tables: ' . implode(', ', $missingTables), 'error');
                    $this->addResult('ℹ️', 'Run: php artisan migrate', 'info');
                }
            } catch (Exception $exception) {
                $this->addResult('❌', 'Webhook table check failed: ' . $exception->getMessage(), 'error');
            }
        } else {
            $this->addResult('ℹ️', 'Webhooks module is disabled (SP_LARAVEL_API_WEBHOOKS_ENABLED=false)', 'info');
        }
    }

    /**
     * Validate MCP Configuration.
     */
    private function validateMcpConfiguration(): void
    {
        $this->info('🤖 Checking MCP Configuration...');

        $mcpConfig = config('record.mcp');

        if (is_array($mcpConfig)) {
            $this->addResult('✅', 'MCP configuration exists', 'success');

            if ($mcpConfig['enabled'] ?? false) {
                $this->addResult('ℹ️', 'MCP module is enabled', 'info');
            } else {
                $this->addResult('ℹ️', 'MCP module is disabled (SP_MCP_ENABLED=false)', 'info');
            }
        } else {
            $this->addResult('⚠️', 'MCP configuration is missing from config/sp-record.php', 'warning');
            $this->addResult('ℹ️', 'Add the mcp configuration array or run: php artisan sp-laravel-api:setup', 'info');
        }
    }

    /**
     * Validate required rate limiters exist for throttle middleware.
     */
    private function validateRateLimiters(): void
    {
        $this->info('⏱️ Checking Rate Limiters...');

        $providerPath = app_path('Providers/AppServiceProvider.php');

        if (!File::exists($providerPath)) {
            $this->addResult('❌', 'Missing AppServiceProvider: app/Providers/AppServiceProvider.php', 'error');
            $this->addResult('ℹ️', 'Define RateLimiter rules for api-reads/api-writes/api-functions or run: php artisan sp-laravel-api:setup', 'info');

            if ($this->option('fix')) {
                $this->info('🔧 Attempting to create/update AppServiceProvider rate limiters...');
                $this->call('sp-laravel-api:setup', ['--force' => false]);
            }

            return;
        }

        $contents = (string) File::get($providerPath);
        $required = ['api-reads', 'api-writes', 'api-functions'];

        $missing = [];
        foreach ($required as $name) {
            if (!str_contains($contents, sprintf("RateLimiter::for('%s'", $name))) {
                $missing[] = $name;
            }
        }

        if ($missing === []) {
            $this->addResult('✅', 'Rate limiters configured: api-reads, api-writes, api-functions', 'success');
            return;
        }

        $this->addResult('❌', 'Missing rate limiter(s): ' . implode(', ', $missing), 'error');
        $this->addResult('ℹ️', 'Add RateLimiter::for(...) rules in AppServiceProvider boot() or run: php artisan sp-laravel-api:setup', 'info');

        if ($this->option('fix')) {
            $this->info('🔧 Attempting to add missing rate limiters...');
            $this->call('sp-laravel-api:setup', ['--force' => false]);
        }
    }

    /**
     * Get table list query for different database drivers.
     */
    private function getTableListQuery(string $driver): string
    {
        return match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table'",
            'mysql' => "SHOW TABLES",
            'pgsql' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
            default => "SHOW TABLES"
        };
    }

    /**
     * Get first table name from results.
     * @param array<int, mixed> $tables
     */
    private function getFirstTableName(array $tables, string $driver): string
    {
        if (empty($tables)) {
            return '';
        }

        return $this->getTableNameFromResult($tables[0], $driver);
    }

    /**
     * Get table name from database result.
     */
    private function getTableNameFromResult($table, string $driver): string
    {
        return match ($driver) {
            'sqlite' => $table->name,
            'mysql' => array_values((array) $table)[0],
            'pgsql' => $table->tablename,
            default => array_values((array) $table)[0]
        };
    }

    /**
     * Test column retrieval for a table.
     */
    private function testColumnRetrieval(string $tableName): array
    {
        try {
            $driver = DB::getDriverName();

            if ($driver === 'sqlite') {
                $columns = DB::select(sprintf('PRAGMA table_info(%s)', $tableName));
                return array_map(fn($col) => $col->name, $columns);
            }

            $columns = DB::select(sprintf('DESCRIBE `%s`', $tableName));
            return array_map(fn($col) => $col->Field, $columns);
        } catch (Exception) {
            return [];
        }
    }

    /**
     * Add validation result.
     */
    private function addResult(string $icon, string $message, string $type): void
    {
        $this->results[] = [
            'icon' => $icon,
            'message' => $message,
            'type' => $type,
        ];

        // Display immediately if verbose
        if ($this->option('verbose')) {
            $this->line(sprintf('  %s %s', $icon, $message));
        }
    }

    /**
     * Display all validation results.
     */
    private function displayResults(): void
    {
        if (!$this->option('verbose')) {
            $this->newLine();
            $this->info('📋 Validation Results:');
            $this->newLine();

            foreach ($this->results as $result) {
                $this->line(sprintf('  %s %s', $result['icon'], $result['message']));
            }
        }

        $this->newLine();

        // Summary
        $errors = array_filter($this->results, fn(array $r): bool => $r['type'] === 'error');
        $warnings = array_filter($this->results, fn(array $r): bool => $r['type'] === 'warning');
        $successes = array_filter($this->results, fn(array $r): bool => $r['type'] === 'success');

        $this->info('📊 Summary:');
        $this->line("  ✅ Passed: " . count($successes));
        $this->line("  ⚠️  Warnings: " . count($warnings));
        $this->line("  ❌ Errors: " . count($errors));

        if ($errors !== []) {
            $this->newLine();
            $this->error('❌ Setup validation failed. Please fix the errors above.');
            $this->info('💡 Run with --fix to attempt automatic fixes.');
        } elseif ($warnings !== []) {
            $this->newLine();
            $this->warn('⚠️ Setup validation completed with warnings.');
            $this->info('💡 Consider addressing the warnings for optimal functionality.');
        } else {
            $this->newLine();
            $this->info('🎉 Setup validation passed! Your SP Laravel API package is properly configured.');
        }
    }

    /**
     * Check if there are any errors.
     */
    private function hasErrors(): bool
    {
        return !empty(array_filter($this->results, fn(array $r): bool => $r['type'] === 'error'));
    }
}
