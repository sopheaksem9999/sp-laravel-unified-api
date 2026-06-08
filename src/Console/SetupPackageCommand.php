<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;
use RuntimeException;
use Illuminate\Console\Command;

class SetupPackageCommand extends Command
{
    protected $signature = 'sp-laravel-api:setup {--force : Overwrite existing configs}';

    protected $description = 'Setup SP Laravel API package: publish configs and create record/audit configurations using config/record.php + config/records/tables/*.php + config/records/global-functions/*.php.';

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
            $this->ensureDirectory('config/records/tables');
            $this->ensureDirectory('config/records/global-functions');
            $created += $this->ensureFile('config/records/tables/README.md', $this->defaultRecordTablesReadme(), $force);
            $created += $this->ensureFile('config/records/tables/users.php', $this->defaultUsersTableConfig(), $force);
            $created += $this->ensureFile('config/records/global-functions/README.md', $this->defaultRecordGlobalFunctionsReadme(), $force);
            $created += $this->ensureFile('config/record.php', $this->defaultRecordConfig(), $force);
            $created += $this->ensureFile('config/audit.php', $this->defaultAuditConfig(), $force);
            $created += $this->ensureFile('config/attachments.php', $this->defaultAttachmentsConfig(), $force);
            $created += $this->ensureFile('config/webhooks.php', $this->defaultWebhooksConfig(), $force);
            $created += $this->ensureAppServiceProviderRateLimiters();
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
            $this->line('  3. Configure tables in config/records/tables and global functions in config/records/global-functions');
        }

        if (!$force && $created === 0) {
            $this->newLine();
            $this->line('💡 Tip: Use --force to overwrite existing configurations.');
        }

        return self::SUCCESS;
    }

    private function ensureAppServiceProviderRateLimiters(): int
    {
        $path = 'app/Providers/AppServiceProvider.php';
        $relativePath = str_replace(base_path() . '/', '', $path);

        if (!file_exists($path)) {
            $this->ensureDirectory('app/Providers');
            $this->writeFile($path, $this->defaultAppServiceProviderWithRateLimiters());
            $this->info('  ✅ Created: ' . $relativePath);
            return 1;
        }

        $existing = @file_get_contents($path) ?: '';
        if (str_contains($existing, "RateLimiter::for('api-reads'")) {
            $this->line('  ⏭️  Skipped (rate limiters already configured): ' . $relativePath);
            return 0;
        }

        $updated = $this->injectRateLimitersIntoAppServiceProvider($existing);
        if ($updated === $existing) {
            $this->line('  ⏭️  Skipped (could not safely update): ' . $relativePath);
            return 0;
        }

        $this->writeFile($path, $updated);
        $this->info('  ✅ Updated: ' . $relativePath);
        return 1;
    }

    private function injectRateLimitersIntoAppServiceProvider(string $contents): string
    {
        $rateLimiterUses = [
            Limit::class,
            Request::class,
            RateLimiter::class,
        ];

        $useMatches = [];
        preg_match_all('/^use\\s+([^;]+);\\s*$/m', $contents, $useMatches, PREG_OFFSET_CAPTURE);

        $existingUses = [];
        foreach ($useMatches[1] ?? [] as $match) {
            $existingUses[] = trim($match[0]);
        }

        $missingUses = array_values(array_filter($rateLimiterUses, fn(string $u): bool => !in_array($u, $existingUses, true)));
        if ($missingUses !== []) {
            $insertion = '';
            foreach ($missingUses as $u) {
                $insertion .= 'use ' . $u . ';' . PHP_EOL;
            }

            if (!empty($useMatches[0])) {
                $lastUse = end($useMatches[0]);
                $insertPos = (int) $lastUse[1] + strlen((string) $lastUse[0]) + 1;
                $contents = substr($contents, 0, $insertPos) . $insertion . substr($contents, $insertPos);
            } else {
                $namespacePos = strpos($contents, 'namespace ');
                if ($namespacePos === false) {
                    return $contents;
                }

                $afterNamespace = strpos($contents, "\n", $namespacePos);
                if ($afterNamespace === false) {
                    return $contents;
                }

                $insertPos = $afterNamespace + 1;
                $contents = substr($contents, 0, $insertPos) . PHP_EOL . $insertion . substr($contents, $insertPos);
            }
        }

        $snippet
            = "    RateLimiter::for('api-reads', function (Request \$request): Limit {" . PHP_EOL
            . "        \$key = \$request->user()?->getAuthIdentifier() ?? \$request->ip();" . PHP_EOL
            . "        return Limit::perMinute(200)->by((string) \$key);" . PHP_EOL
            . "    });" . PHP_EOL . PHP_EOL
            . "    RateLimiter::for('api-writes', function (Request \$request): Limit {" . PHP_EOL
            . "        \$key = \$request->user()?->getAuthIdentifier() ?? \$request->ip();" . PHP_EOL
            . "        return Limit::perMinute(100)->by((string) \$key);" . PHP_EOL
            . "    });" . PHP_EOL . PHP_EOL
            . "    RateLimiter::for('api-functions', function (Request \$request): Limit {" . PHP_EOL
            . "        \$key = \$request->user()?->getAuthIdentifier() ?? \$request->ip();" . PHP_EOL
            . "        return Limit::perMinute(100)->by((string) \$key);" . PHP_EOL
            . "    });" . PHP_EOL;

        if (str_contains($contents, "RateLimiter::for('api-reads'")) {
            return $contents;
        }

        if (preg_match('/public\\s+function\\s+boot\\s*\\([^)]*\\)\\s*(?::\\s*\\w+)?\\s*\\{/m', $contents, $m, PREG_OFFSET_CAPTURE)) {
            $match = $m[0];
            $start = $match[1];
            $bracePos = strpos($contents, '{', $start);
            if ($bracePos === false) {
                return $contents;
            }

            $insertPos = $bracePos + 1;
            return substr($contents, 0, $insertPos) . PHP_EOL . $snippet . substr($contents, $insertPos);
        }

        if (preg_match('/\\}\\s*$/', $contents, $m, PREG_OFFSET_CAPTURE)) {
            $insertPos = $m[0][1];
            $bootMethod
                = PHP_EOL
                . '    public function boot(): void' . PHP_EOL
                . '    {' . PHP_EOL
                . $snippet
                . '    }' . PHP_EOL;
            return substr($contents, 0, $insertPos) . $bootMethod . substr($contents, $insertPos);
        }

        return $contents;
    }

    private function defaultAppServiceProviderWithRateLimiters(): string
    {
        return <<<'PHP'
            <?php

            namespace App\Providers;

            use Illuminate\Cache\RateLimiting\Limit;
            use Illuminate\Http\Request;
            use Illuminate\Support\Facades\RateLimiter;
            use Illuminate\Support\ServiceProvider;

            class AppServiceProvider extends ServiceProvider
            {
                public function register(): void
                {
                }

                public function boot(): void
                {
                    RateLimiter::for('api-reads', function (Request $request): Limit {
                        $key = $request->user()?->getAuthIdentifier() ?? $request->ip();
                        return Limit::perMinute(200)->by((string) $key);
                    });

                    RateLimiter::for('api-writes', function (Request $request): Limit {
                        $key = $request->user()?->getAuthIdentifier() ?? $request->ip();
                        return Limit::perMinute(100)->by((string) $key);
                    });

                    RateLimiter::for('api-functions', function (Request $request): Limit {
                        $key = $request->user()?->getAuthIdentifier() ?? $request->ip();
                        return Limit::perMinute(100)->by((string) $key);
                    });
                }
            }
            PHP;
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
        if (!is_dir($dir) && (!mkdir($dir, 0o755, true) && !is_dir($dir))) {
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

        if (!mkdir($path, 0o755, true) && !is_dir($path)) {
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

            Create `config/records/tables/customers.php`:

            ```php
            <?php

            use Illuminate\Http\Request;
            use Illuminate\Contracts\Validation\Validator as ValidatorContract;
            use Illuminate\Support\Facades\Validator;
            use Sopheak\Core\Types\RecordTableType;

            return new RecordTableType(
                pmsName: 'customer',
                table: 'customers',
                isAuthRead: true,
                isAuthWrite: true,
                relationships: [],
                softDeletes: true,
                hasTenantId: false,
                createValidator: function (Request $request, ?int $id = null): ValidatorContract {
                    return Validator::make($request->all(), [
                        'name' => 'required|string|max:255',
                    ]);
                },
            );
            ```

            ## Example (multiple tables in one file)

            Create `config/records/tables/core.php`:

            ```php
            <?php

            use Sopheak\Core\Types\RecordTableType;

            return [
                'invoices' => new RecordTableType(
                    pmsName: 'invoice',
                    table: 'invoices',
                    isAuthRead: true,
                    isAuthWrite: true,
                    relationships: [],
                    softDeletes: true,
                    hasTenantId: false,
                ),
            ];
            ```
            MD;
    }

    private function defaultRecordGlobalFunctionsReadme(): string
    {
        return <<<'MD'
            # Record Global Function Configs

            Put global function config files in this folder.

            ## Rules

            - Each `*.php` file must return an array of function configs.
            - File name is used as group prefix for API path.
            - Example: `auth.php` + key `login` => endpoint key `auth/login`.
            - If a key already contains `/`, the key is used as-is.

            ## Example

            Create `config/records/global-functions/auth.php`:

            ```php
            <?php

            use Sopheak\Core\Types\RecordFunctionType;

            return [
                'login' => new RecordFunctionType(
                    httpMethod: ['POST'],
                    class: \App\Services\AuthService::class,
                    functionName: 'login',
                    description: 'Login',
                ),
            ];
            ```
            MD;
    }

    private function defaultUsersTableConfig(): string
    {
        return <<<'PHP'
            <?php

            use Illuminate\Http\Request;
            use Illuminate\Contracts\Validation\Validator;
            use Sopheak\Core\Types\RecordTableType;

            return new RecordTableType(
                pmsName: 'user',
                table: 'users',
                isAuthRead: true,
                isAuthWrite: true,
                relationships: [],
                functions: [],
                softDeletes: false,
                hasTenantId: false,
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
            );
            PHP;
    }

    private function defaultRecordConfig(): string
    {
        return <<<'PHP'
            <?php

            return [
                /*
                |--------------------------------------------------------------------------
                | Tenant ID Configuration
                |--------------------------------------------------------------------------
                |
                | This option controls whether tenant_id filtering is enabled across the
                | Record API system. When enabled, all queries will include tenant_id
                | filtering for multi-tenant data isolation. When disabled, tenant_id
                | filtering is completely bypassed for optimal performance in single-tenant
                | or non-tenant environments.
                |
                | Default: false (for backward compatibility with existing projects)
                |
                */
                'enable_tenant_id' => false,
                'tenant_column' => 'tenant_id',
                'tenant_column_type' => 'string',
                'tenant_header' => 'X-Tenant-ID',
                'table_config_path' => 'records/tables',

                /*
                |--------------------------------------------------------------------------
                | API Route Prefix Configuration
                |--------------------------------------------------------------------------
                |
                | This option controls the route prefix for the Record API endpoints.
                | By default, routes are registered under 'api' (e.g., /api/customers).
                | You can customize this prefix to match your application's routing structure.
                |
                */
                'api_prefix' => 'api/v1',

                 /*
                |--------------------------------------------------------------------------
                | API Docs UI Access
                |--------------------------------------------------------------------------
                |
                | login_api can point to your client project's auth route.
                | Supports:
                | - Relative path: /v1/auth/login
                | - Absolute URL: https://api.example.com/v1/auth/login
                | - access_token_key: token key in login response payload
                | - login_api: client project login route/URL used by docs login form
                | - email: optional fixed docs account email enforced by docs login proxy
                */
                'api_docs' => [
                    'is_private' => env('SP_LARAVEL_API_DOCS_PRIVATE', false),
                    'access_token_key' => 'access_token',
                    'login_api' => '/v1/auth/login',
                    'email' => env('SP_LARAVEL_API_DOCS_EMAIL'),
                ],

                /*
                |--------------------------------------------------------------------------
                | Model Context Protocol (MCP) Support
                |--------------------------------------------------------------------------
                |
                | Configuration for the AI agent MCP integration.
                | - enabled: Toggle the MCP feature entirely (default: false).
                | - read_only: Globally disable MCP write tools (create, update, delete).
                | - route_prefix: The prefix for HTTP/SSE MCP endpoints.
                | - middleware: The middleware applied to the HTTP/SSE endpoints.
                */
                'mcp' => [
                    'enabled' => env('SP_MCP_ENABLED', false),
                    'read_only' => env('SP_MCP_READ_ONLY', false),
                    'route_prefix' => env('SP_MCP_ROUTE_PREFIX', 'mcp'),
                    'middleware' => ['api', 'auth:sanctum'],
                ],

                /*
                |--------------------------------------------------------------------------
                | RPC Route Prefix Configuration
                |--------------------------------------------------------------------------
                |
                | This option controls the route prefix for the Global RPC endpoints.
                | By default, routes are registered under 'rpc' (e.g., /api/v1/rpc/my_function).
                |
                */
                'rpc_prefix' => 'rpc',

                // Maximum items returned per page for list endpoints
                'per_page_max' => 10000,

                // Maximum items returned for limit parameter (non-paginated requests)
                'limit_max' => 10000,

                // Maximum items per bulk operation
                'bulk_max' => 1000,

                'rate_limits' => [
                    // Per-table rate limits (empty = use global defaults)
                ],

                // Enable or disable bulk operation endpoints
                'bulk_operations' => env('SP_BULK_OPERATIONS', true),

                // Real-time broadcast events
                'broadcast_events' => env('SP_BROADCAST_EVENTS', false),
                'broadcast_tables' => [],

                // Cache configuration
                'cache' => [
                    // Enable/disable caching globally for the Records API
                    'enabled' => env('SP_LARAVEL_API_CACHE_API', false),

                    // Cache TTL for query results (seconds)
                    'ttl' => env('SP_LARAVEL_API_CACHE_API_TTL', 3600),

                    // Cache key prefix for Records API
                    'prefix' => 'sp_laravel_api',

                    // Per-table cache control (overrides global setting)
                    'per_table' => [
                        // Example: disable cache for specific tables
                        // 'audit_logs' => false,
                        // 'real_time_data' => false,
                    ],
                    'per_table_ttl' => [
                        // Example: override cache TTL for specific tables
                        // 'audit_logs' => 600,
                        // 'real_time_data' => 120,
                    ],
                    'admission' => [
                        // These rules are inactive while all arrays are empty.
                        'only_tables' => [],
                        'except_tables' => [],
                        'only_actions' => [],
                        'except_actions' => [],
                        'skip_query_params' => [],
                    ],
                ],

                'pgsql_tenant_context' => [
                    'mode' => env('SP_PGSQL_TENANT_CONTEXT_MODE', 'session'),
                ],

                // Maximum nesting depth to prevent performance issues (default: 10)
                'max_depth' => 10,

                'subquery_optimization_max_records' => 100,

                'pagination' => [
                    'default_mode' => env('SP_PAGINATION_DEFAULT_MODE', 'offset'),
                    'cursor' => [
                        'default_column' => 'id',
                        'composite_enabled' => true,
                    ],
                    'skip_total_default' => env('SP_PAGINATION_SKIP_TOTAL', false),
                ],

                'database' => [
                    'read_connection' => env('DB_READ_CONNECTION'),
                    'write_connection' => env('DB_WRITE_CONNECTION'),
                ],

                'index_hints' => [],

                'profiling' => [
                    'enabled' => env('SP_QUERY_PROFILING_ENABLED', false),
                ],

                // Include debug details in API error responses.
                'debug' => env('SP_LARAVEL_API_DEBUG', false),

                // permission 
                'permission_separator' => ':', // separator for permission ex: view:invoice
                'restrict_to_own_records' => false, // limit queries to records created by the authenticated user
                'own_records_permission_prefix' => 'viewOwn', // example: viewOwn_invoice

                // Config-driven middleware map (default + per-table overrides)
                'middleware_map' => [
                    'default' => [
                        '*' => [],
                        'read' => [],
                        'write' => [],
                        'function' => [],
                    ],
                    'tables' => [
                    ],
                ],

                // Global RPC functions can also be defined in config/records/global-functions/*.php.
                'global_functions' => [
                ],

                'global_triggers' => [
                ],

                'casting' => [
                ],

                'default_validation' => [
                    'enabled' => false,
                    'only_when_missing' => true,
                    'required' => true,
                    'types' => true,
                    'unique' => true,
                    'foreign_keys' => true,
                ],

                // Table configurations can also be defined in config/records/tables/*.php.
                'tables' => [
                ],
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
                /*
                |--------------------------------------------------------------------------
                | Audit Log Table Configuration
                |--------------------------------------------------------------------------
                |
                | This defines the default table configuration for the audit logs API.
                | It is automatically merged into the main record.tables configuration.
                | By default, create/update/delete are disabled (read-only).
                |
                */
                'tables' => [
                    'sp_audit_logs' => new \Sopheak\Core\Types\RecordTableType(
                        table: 'sp_audit_logs',
                        pmsName: 'audit_log',
                        primaryKey: 'id',
                        softDeletes: false,
                        hasTenantId: true,
                        isAuthRead: true,
                        isAuthWrite: false,
                        canCreate: false,
                        canUpdate: false,
                        canDelete: false,
                        canUpsert: false,
                        columns: [
                            'id' => ['type' => 'integer', 'nullable' => false],
                            'entity_name' => ['type' => 'string', 'nullable' => true],
                            'entity_type' => ['type' => 'string', 'nullable' => true],
                            'entity_id' => ['type' => 'integer', 'nullable' => true],
                            'user_id' => ['type' => 'integer', 'nullable' => true],
                            'event' => ['type' => 'string', 'nullable' => true],
                            'title' => ['type' => 'string', 'nullable' => true],
                            'subject' => ['type' => 'string', 'nullable' => true],
                            'recap' => ['type' => 'string', 'nullable' => true],
                            'old_data' => ['type' => 'json', 'nullable' => true],
                            'new_data' => ['type' => 'json', 'nullable' => true],
                            'metadata' => ['type' => 'json', 'nullable' => true],
                            'ip_address' => ['type' => 'string', 'nullable' => true],
                            'user_agent' => ['type' => 'string', 'nullable' => true],
                            'request_id' => ['type' => 'string', 'nullable' => true],
                        ],
                        relationships: [
                            'user' => new \Sopheak\Core\Types\RecordBelongsToType(
                                table: 'users',
                                type: \Sopheak\Core\Enums\RecordRelationshipsEnum::BELONGS_TO,
                                foreignKey: 'user_id',
                                ownerKey: 'id'
                            ),
                        ],
                        functions: [
                            'stats' => new \Sopheak\Core\Types\RecordFunctionType(
                                class: \Sopheak\Core\Http\Controllers\AuditLogController::class,
                                functionName: 'getStats',
                                httpMethod: ['GET'],
                                description: 'Get audit statistics'
                            ),
                            'field-timeline/{entityType}/{entityId}/{field}' => new \Sopheak\Core\Types\RecordFunctionType(
                                class: \Sopheak\Core\Http\Controllers\AuditLogController::class,
                                functionName: 'getFieldTimeline',
                                httpMethod: ['GET'],
                                description: 'Get field timeline'
                            ),
                            'field-stats/{entityType}/{entityId}/{field}' => new \Sopheak\Core\Types\RecordFunctionType(
                                class: \Sopheak\Core\Http\Controllers\AuditLogController::class,
                                functionName: 'getFieldStats',
                                httpMethod: ['GET'],
                                description: 'Get field statistics'
                            ),
                        ]
                    ),
                ],
            ];
            PHP;
    }

    private function defaultWebhooksConfig(): string
    {
        return file_get_contents(__DIR__ . '/../../config/webhooks.php') ?: "<?php\n\nreturn [];";
    }

    private function defaultAttachmentsConfig(): string
    {
        return file_get_contents(__DIR__ . '/../../config/attachments.php') ?: "<?php\n\nreturn [];";
    }
}
