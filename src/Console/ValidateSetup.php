<?php

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Support\SchemaRegistry;

class ValidateSetup extends Command
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
        $this->validateDatabaseConnection();
        $this->validateDatabaseCompatibility();
        $this->validateMigrations();
        $this->validateEnvironmentVariables();
        $this->validatePermissions();
        $this->validateSchemaRegistry();
        $this->validateRoutes();

        // Display results
        $this->displayResults();

        // Return appropriate exit code
        return $this->hasErrors() ? 1 : 0;
    }

    /**
     * Validate configuration files exist and are properly configured.
     */
    private function validateConfigFiles(): void
    {
        $this->info('📋 Checking Configuration Files...');

        $configFiles = [
            'record.php' => 'Record API configuration',
            'audit.php' => 'Audit logging configuration',
            'cursor_pagination.php' => 'Cursor pagination configuration',
            'sp-laravel-api.php' => 'Main package configuration',
        ];

        foreach ($configFiles as $file => $description) {
            $path = config_path($file);
            
            if (File::exists($path)) {
                $this->addResult('✅', "Config file exists: {$file}", 'success');
                
                // Validate config content
                try {
                    $config = include $path;
                    if (is_array($config) && !empty($config)) {
                        $this->addResult('✅', "Config file valid: {$file}", 'success');
                    } else {
                        $this->addResult('⚠️', "Config file empty or invalid: {$file}", 'warning');
                    }
                } catch (\Exception $e) {
                    $this->addResult('❌', "Config file syntax error: {$file} - {$e->getMessage()}", 'error');
                }
            } else {
                $this->addResult('❌', "Missing config file: {$file}", 'error');
                
                if ($this->option('fix')) {
                    $this->info("🔧 Attempting to publish {$file}...");
                    $this->call('sp-laravel-api:setup');
                }
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
            $this->addResult('ℹ️', "Database driver: {$driver}", 'info');
            
        } catch (\Exception $e) {
            $this->addResult('❌', "Database connection failed: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Validate database compatibility with SchemaRegistry.
     */
    private function validateDatabaseCompatibility(): void
    {
        $this->info('🔧 Checking Database Compatibility...');

        try {
            $driver = DB::getDriverName();
            
            if (in_array($driver, ['mysql', 'sqlite', 'pgsql'])) {
                $this->addResult('✅', "Database driver '{$driver}' is supported", 'success');
                
                // Test SchemaRegistry with a simple table
                $tables = DB::select($this->getTableListQuery($driver));
                
                if (!empty($tables)) {
                    $tableName = $this->getFirstTableName($tables, $driver);
                    
                    // Test column retrieval
                    $columns = $this->testColumnRetrieval($tableName);
                    
                    if (!empty($columns)) {
                        $this->addResult('✅', "SchemaRegistry can read table columns for '{$tableName}'", 'success');
                    } else {
                        $this->addResult('⚠️', "SchemaRegistry returned empty columns for '{$tableName}'", 'warning');
                    }
                } else {
                    $this->addResult('⚠️', 'No tables found in database', 'warning');
                }
                
            } else {
                $this->addResult('⚠️', "Database driver '{$driver}' may not be fully supported", 'warning');
            }
            
        } catch (\Exception $e) {
            $this->addResult('❌', "Database compatibility check failed: {$e->getMessage()}", 'error');
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
            $tableNames = array_map(fn($table) => $this->getTableNameFromResult($table, DB::getDriverName()), $tables);
            
            if (in_array('migrations', $tableNames)) {
                $this->addResult('✅', 'Migrations table exists', 'success');
                
                // Check for audit_logs table
                if (in_array('audit_logs', $tableNames)) {
                    $this->addResult('✅', 'Audit logs table exists', 'success');
                } else {
                    $this->addResult('⚠️', 'Audit logs table missing - run: php artisan migrate', 'warning');
                }
                
            } else {
                $this->addResult('❌', 'Migrations table missing - run: php artisan migrate:install', 'error');
            }
            
        } catch (\Exception $e) {
            $this->addResult('❌', "Migration check failed: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Validate environment variables.
     */
    private function validateEnvironmentVariables(): void
    {
        $this->info('🌍 Checking Environment Variables...');

        $envVars = [
            'RECORD_API_PREFIX' => ['default' => 'api', 'required' => false],
            'RECORD_MAX_DEPTH' => ['default' => '3', 'required' => false],
            'RECORD_CACHE_TTL' => ['default' => '3600', 'required' => false],
            'AUDIT_LOG_ENABLED' => ['default' => 'true', 'required' => false],
            'AUDIT_LOG_RETENTION_DAYS' => ['default' => '365', 'required' => false],
        ];

        foreach ($envVars as $var => $config) {
            $value = env($var);
            
            if ($value !== null) {
                $this->addResult('✅', "{$var} is set: {$value}", 'success');
            } else {
                $message = "{$var} not set";
                if (isset($config['default'])) {
                    $message .= " (using default: {$config['default']})";
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
            // Check if Spatie Permission tables exist
            $tables = DB::select($this->getTableListQuery(DB::getDriverName()));
            $tableNames = array_map(fn($table) => $this->getTableNameFromResult($table, DB::getDriverName()), $tables);
            
            $permissionTables = ['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'];
            $missingTables = array_diff($permissionTables, $tableNames);
            
            if (empty($missingTables)) {
                $this->addResult('✅', 'Spatie Permission tables exist', 'success');
            } else {
                $this->addResult('⚠️', 'Missing permission tables: ' . implode(', ', $missingTables), 'warning');
                $this->addResult('ℹ️', 'Run: php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"', 'info');
            }
            
        } catch (\Exception $e) {
            $this->addResult('❌', "Permission check failed: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Validate SchemaRegistry functionality.
     */
    private function validateSchemaRegistry(): void
    {
        $this->info('🏗️ Checking SchemaRegistry...');

        try {
            // Test SchemaRegistry::get()
            $schema = SchemaRegistry::get();
            
            if (is_array($schema)) {
                $this->addResult('✅', 'SchemaRegistry::get() returns array', 'success');
                
                if (!empty($schema)) {
                    $tableCount = count($schema);
                    $this->addResult('✅', "SchemaRegistry loaded {$tableCount} table(s)", 'success');
                    
                    if ($this->option('verbose')) {
                        foreach (array_keys($schema) as $tableName) {
                            $this->addResult('ℹ️', "  - {$tableName}", 'info');
                        }
                    }
                } else {
                    $this->addResult('⚠️', 'SchemaRegistry returned empty schema - check config/record.php', 'warning');
                }
            } else {
                $this->addResult('❌', 'SchemaRegistry::get() did not return array', 'error');
            }
            
        } catch (\Exception $e) {
            $this->addResult('❌', "SchemaRegistry check failed: {$e->getMessage()}", 'error');
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
            
            $apiPrefix = config('record.api_prefix', 'api');
            $hasApiRoutes = false;
            
            foreach ($routes as $route) {
                $uri = $route->uri();
                if (str_starts_with($uri, $apiPrefix . '/')) {
                    $hasApiRoutes = true;
                    break;
                }
            }
            
            if ($hasApiRoutes) {
                $this->addResult('✅', "API routes registered with prefix: {$apiPrefix}", 'success');
            } else {
                $this->addResult('⚠️', "No API routes found with prefix: {$apiPrefix}", 'warning');
            }
            
        } catch (\Exception $e) {
            $this->addResult('❌', "Route check failed: {$e->getMessage()}", 'error');
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
                $columns = DB::select("PRAGMA table_info({$tableName})");
                return array_map(fn($col) => $col->name, $columns);
            } else {
                $columns = DB::select("DESCRIBE `{$tableName}`");
                return array_map(fn($col) => $col->Field, $columns);
            }
        } catch (\Exception $e) {
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
            'type' => $type
        ];
        
        // Display immediately if verbose
        if ($this->option('verbose')) {
            $this->line("  {$icon} {$message}");
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
                $this->line("  {$result['icon']} {$result['message']}");
            }
        }
        
        $this->newLine();
        
        // Summary
        $errors = array_filter($this->results, fn($r) => $r['type'] === 'error');
        $warnings = array_filter($this->results, fn($r) => $r['type'] === 'warning');
        $successes = array_filter($this->results, fn($r) => $r['type'] === 'success');
        
        $this->info('📊 Summary:');
        $this->line("  ✅ Passed: " . count($successes));
        $this->line("  ⚠️  Warnings: " . count($warnings));
        $this->line("  ❌ Errors: " . count($errors));
        
        if (count($errors) > 0) {
            $this->newLine();
            $this->error('❌ Setup validation failed. Please fix the errors above.');
            $this->info('💡 Run with --fix to attempt automatic fixes.');
        } elseif (count($warnings) > 0) {
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
        return !empty(array_filter($this->results, fn($r) => $r['type'] === 'error'));
    }
}