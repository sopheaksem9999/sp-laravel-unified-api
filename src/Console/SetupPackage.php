<?php

namespace Sopheak\Core\Console;

use Throwable;
use RuntimeException;
use Illuminate\Console\Command;

class SetupPackage extends Command
{
    protected $signature = 'sp-laravel-api:setup {--force : Overwrite existing configs}';

    protected $description = 'Setup SP Laravel API package: publish configs and create record/audit configurations.';

    public function handle(): int
    {
        $this->info('Setting up SP Laravel API package...');
        $this->newLine();

        // Publish package config
        $this->line('📦 Publishing package configurations...');
        try {
            $this->call('vendor:publish', [
                '--tag' => 'sp-laravel-api-config',
                '--force' => true,
            ]);
            $this->info('✅ Package configurations published successfully.');
        } catch (Throwable $throwable) {
            $this->error('❌ Vendor publish failed: ' . $throwable->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $force = (bool) $this->option('force');

        $this->line('🔧 Creating application configuration files...');
        $created = 0;

        try {
            $this->ensureDirectory('config/record/tables');
            $created += $this->ensureFile('config/record/tables/README.md', $this->defaultRecordTablesReadme(), $force);
            $created += $this->ensureFile('config/record.php', $this->defaultRecordConfig(), $force);
            $created += $this->ensureFile('config/audit.php', $this->defaultAuditConfig(), $force);
        } catch (Throwable $throwable) {
            $this->error('❌ Failed to create configuration files: ' . $throwable->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('✅ Setup complete! Created/updated %d configuration file(s).', $created));

        if ($created > 0) {
            $this->newLine();
            $this->line('📋 Next steps:');
            $this->line('  1. Review and customize the generated configuration files');
            $this->line('  2. Set up your environment variables (.env file)');
            $this->line('  3. Configure your database tables in config/record.php');
        }

        if (!$force && $created === 0) {
            $this->newLine();
            $this->line('💡 Tip: Use --force to overwrite existing configurations.');
        }

        return self::SUCCESS;
    }

    private function ensureFile(string $path, string $contents, bool $force): int
    {
        $relativePath = str_replace(base_path() . '/', '', $path);

        if (!file_exists($path)) {
            $this->writeFile($path, $contents);
            $this->info('  ✅ Created: ' . $relativePath);
            return 1;
        }

        // If file has only opening tag or empty, treat as missing
        $existing = trim(@file_get_contents($path) ?: '');
        if (!$existing || $existing === '<?php' || $force) {
            $this->writeFile($path, $contents);
            $this->info('  ✅ Updated: ' . $relativePath);
            return 1;
        }

        $this->line('  ⏭️  Skipped (exists): ' . $relativePath);
        return 0;
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

    private function defaultRecordTablesReadme(): string
    {
        return <<<'MD'
# Record Table Configs

Put table config files in this folder to keep `config/record.php` clean.

## Rules

- Each `*.php` file can return:
  - a single `RecordTableType`, or
  - an array like `['table_name' => RecordTableType, ...]`
- The filename (without `.php`) is used as the table key when returning a single `RecordTableType`.

## Example (single table)

Create `config/record/tables/customers.php`:

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

return new RecordTableType(
    pms_name: 'customer',
    table: 'customers',
    public: new RecordTablePublic(
        read: false,
        write: false,
    ),
    relationships: [],
    soft_deletes: true,
    has_tenant_id: false,
    createValidator: function (Request $request, ?int $id = null): ValidatorContract {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);
    },
);
```

## Example (multiple tables in one file)

Create `config/record/tables/core.php`:

```php
<?php

use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

return [
    'invoices' => new RecordTableType(
        pms_name: 'invoice',
        table: 'invoices',
        public: new RecordTablePublic(read: false, write: false),
        relationships: [],
        soft_deletes: true,
        has_tenant_id: false,
    ),
];
```
MD;
    }

    private function defaultRecordConfig(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Http\Request;
use Illuminate\Contracts\Validation\Validator;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordSpatiePermissionType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTableTriggerType;

$tables = [
    'users' => new RecordTableType(
        pms_name: 'user',
        table: 'users',
        public: new RecordTablePublic(
            read: false,
            write: false
        ),
        relationships: [],
        functions: [],
        soft_deletes: false,
        has_tenant_id: false,
        createValidator: fn(Request $request, ?int $id = null): Validator => \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'password' => 'required|string|min:8',
        ]),
        updateValidator: fn(Request $request, ?int $id = null): Validator => \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email',
            'password' => 'sometimes|required|string|min:8',
        ]),
        deleteValidator: fn(Request $request, ?int $id = null): Validator => \Illuminate\Support\Facades\Validator::make(['id' => $id], [
            'id' => 'required|integer',
        ]),
    ),
];

$tablesDirectory = __DIR__ . '/record/tables';

if (is_dir($tablesDirectory)) {
    foreach (glob($tablesDirectory . '/*.php') as $path) {
        $config = require $path;

        if ($config instanceof RecordTableType) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $tables[$name] = $config;
        } elseif (is_array($config)) {
            $tables = array_merge($tables, $config);
        }
    }
}

return [
    /*
    |--------------------------------------------------------------------------
    | Tenant ID Configuration
    |--------------------------------------------------------------------------
    */
    'enable_tenant_id' => false,
    'tenant_column' => 'tenant_id',
    'tenant_header' => 'X-Tenant-ID',

    /*
    |--------------------------------------------------------------------------
    | API Route Prefix Configuration
    |--------------------------------------------------------------------------
    */
    'api_prefix' => 'api/v1',

    // Maximum items returned per page for list endpoints
    'per_page_max' => 10000,

    // Maximum items returned for limit parameter (non-paginated requests)
    'limit_max' => 10000,

    // Maximum items per bulk operation
    'bulk_max' => 1000,

    // Cache configuration
    'cache' => [
        'enabled' => env('CACHE_API', false),
        'ttl' => 3600,
        'prefix' => 'sp_laravel_api',
        'per_table' => [],
        'per_table_ttl' => [],
    ],

    // Legacy cache_ttl for backward compatibility
    'cache_ttl' => 3600,

    // Maximum depth for nested relationships
    'max_relationship_depth' => 3,

    // Cascade behavior for nested operations
    'cascade_operations' => false,

    // Table configurations
    'tables' => $tables,
];
PHP;
    }

    private function defaultAuditConfig(): string
    {
        return <<<'PHP'
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Audit Logging
    |--------------------------------------------------------------------------
    */
    'enabled' => env('AUDIT_LOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */
    'queue_enabled' => env('AUDIT_LOG_QUEUE', false),
    'queue_connection' => env('AUDIT_LOG_QUEUE_CONNECTION', 'default'),
    'queue_name' => env('AUDIT_LOG_QUEUE_NAME', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Log Retention
    |--------------------------------------------------------------------------
    */
    'retention_days' => env('AUDIT_LOG_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Excluded Events
    |--------------------------------------------------------------------------
    */
    'excluded_events' => [
        // 'updated',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Attributes
    |--------------------------------------------------------------------------
    */
    'excluded_attributes' => [
        'password',
        'remember_token',
        'email_verified_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Events
    |--------------------------------------------------------------------------
    */
    'log_authentication_events' => env('AUDIT_LOG_AUTH_EVENTS', true),

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'max_relationships' => 10,
        'use_transactions' => true,
        'batch_size' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    */
    'security' => [
        'encrypt_sensitive_data' => env('AUDIT_LOG_ENCRYPT', false),
        'hash_ip_addresses' => env('AUDIT_LOG_HASH_IPS', false),
        'anonymize_old_logs' => env('AUDIT_LOG_ANONYMIZE', false),
    ],
];
PHP;
    }
}
