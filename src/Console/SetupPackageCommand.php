<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;
use RuntimeException;
use Illuminate\Console\Command;
use Sopheak\Core\Config\ConfigNamespaceBridge;

class SetupPackageCommand extends Command
{
    protected $signature = 'sp-laravel-api:setup {--force : Overwrite existing config files, including publishing packaged defaults over old-named ones}';

    protected $description = 'Setup SP Laravel API package: publish configs and create record/audit configurations using config/sp-record.php + config/records/tables/*.php + config/records/global-functions/*.php.';

    public function handle(): int
    {
        $this->info('Setting up SP Laravel API package...');
        $this->newLine();

        $force = (bool) $this->option('force');

        if (!$this->guardAgainstShadowingOldNamedConfigFiles($force)) {
            return self::FAILURE;
        }

        // Publish package config
        $this->line('📦 Publishing package configurations...');
        try {
            // --force follows the command's own flag rather than being pinned
            // on: publishing the package's packaged defaults over a client's
            // customized config is destructive, and this command is the one
            // the setup docs tell people to run.
            $this->call('vendor:publish', [
                '--tag' => 'sp-laravel-api-config',
                '--force' => $force,
            ]);
            $this->info('✅ Package configurations published successfully.');
        } catch (Throwable $throwable) {
            $this->error('❌ Vendor publish failed: ' . $throwable->getMessage());
            return self::FAILURE;
        }

        $this->newLine();

        $this->line('🔧 Creating application configuration files...');
        $created = 0;

        try {
            $this->ensureDirectory('config/records/tables');
            $this->ensureDirectory('config/records/global-functions');
            $this->ensureDirectory('app/Record/Validators');
            $created += $this->ensureFile('config/records/tables/README.md', $this->defaultRecordTablesReadme(), $force);
            // Static-method class reference, not a Closure: config/sp-record.php ships
            // with 'autoloaded' => true, which evaluates this file's RecordTableType
            // while the config file itself is being merged, so it must survive
            // php artisan config:cache. var_export() cannot serialize a Closure; a
            // [ClassName::class, 'method'] array of strings survives unchanged, but
            // the method it names must be `public static` -- HasControllerHelpers
            // invokes it as a plain PHP callable, which is only resolvable without
            // an object instance (i.e. is_callable() only returns true) when the
            // method is static.
            $created += $this->ensureFile('app/Record/Validators/UserValidator.php', $this->defaultUserValidatorClass(), $force);
            $created += $this->ensureFile('config/records/tables/users.php', $this->defaultUsersTableConfig(), $force);
            $created += $this->ensureFile('config/records/global-functions/README.md', $this->defaultRecordGlobalFunctionsReadme(), $force);
            // sp-* names: the package ships and reads these under the canonical
            // namespaces regardless, and scaffolding the old unprefixed names
            // would trip the package's own config-rename deprecation notice.
            //
            // These four are NEVER force-overwritten, whatever --force says.
            // vendor:publish above already emits all four, so by the time we
            // get here the file on disk is the package's full packaged config.
            // Forcing the scaffold over it would replace the ~540-line
            // config/sp-record.php — `autoloaded` flag, RecordConfigLoader
            // calls and all — with a ~207-line minimal file that has neither,
            // silently switching off config:cache baking of table configs, and
            // would leave a state that is neither the packaged defaults nor
            // the client's own config: keys the scaffold omits (id_type, for
            // one) survive from wherever they were, keys it sets get the
            // scaffold's value. --force means "the packaged defaults win",
            // and the packaged defaults are what vendor:publish just wrote.
            //
            // They stay here as a fallback for the one case that still needs
            // them: the file is genuinely absent after publishing (a client
            // who deleted it, or a publish that produced nothing).
            $publishedByVendorPublish = false;
            $created += $this->ensureFile('config/sp-record.php', $this->defaultRecordConfig(), $publishedByVendorPublish);
            $created += $this->ensureFile('config/sp-audit.php', $this->defaultAuditConfig(), $publishedByVendorPublish);
            $created += $this->ensureFile('config/sp-attachments.php', $this->defaultAttachmentsConfig(), $publishedByVendorPublish);
            $created += $this->ensureFile('config/sp-webhooks.php', $this->defaultWebhooksConfig(), $publishedByVendorPublish);
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

    /**
     * Refuse to publish `sp-*.php` on top of a still-present old-named config.
     *
     * Publishing writes the package's packaged DEFAULTS to the new name, and
     * ConfigNamespaceBridge::adopt() gives the new name precedence over the old
     * one for every key both files set. So on a client who has customized
     * `config/record.php` and not yet migrated, publishing silently reverts
     * their settings to the packaged defaults — `api_prefix` back to `api/v1`,
     * `id_type` back to `integer` — while `config/record.php` stays
     * byte-identical on disk. There is nothing in `git diff` to see, no error,
     * and this command even prints "Skipped (exists)" for the old file. The
     * only safe move is not to create that state.
     *
     * @return bool false when the caller must abort without publishing
     */
    private function guardAgainstShadowingOldNamedConfigFiles(bool $force): bool
    {
        $oldNamed = ConfigNamespaceBridge::deprecatedFiles();

        if ($oldNamed === []) {
            return true;
        }

        $this->newLine();
        $this->warn('⚠️  Old-named package config files are still present in config/:');
        $this->newLine();

        foreach ($oldNamed as $old => $new) {
            $this->line(sprintf('    config/%s  →  config/%s', $old, $new));
        }

        $this->newLine();
        $this->line("Publishing writes the package's packaged DEFAULTS to the sp-* names, and an");
        $this->line('sp-* file takes precedence over its old-named counterpart for every key both');
        $this->line('set. Your customizations would stop taking effect while the old file stays');
        $this->line('byte-identical on disk — no diff, no error, nothing to notice.');
        $this->newLine();
        $this->line('Rename your existing files instead, then re-run this command:');
        $this->newLine();

        foreach ($oldNamed as $old => $new) {
            $this->line(sprintf('    git mv config/%s config/%s', $old, $new));
        }

        $this->newLine();

        if ($force) {
            $this->warn('--force given: publishing packaged defaults over the names above anyway.');
            $this->warn('Copy anything you still need out of the old files before the next boot.');
            $this->newLine();

            return true;
        }

        $this->error('❌ Aborted without publishing or creating anything.');
        $this->line('   Re-run with --force only if you really want the packaged defaults to win:');
        $this->line("   it publishes the package's own sp-* files verbatim and leaves the");
        $this->line('   old-named files on disk, superseded.');

        return false;
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

            Put table config files in this folder to keep `config/sp-record.php` clean.

            ## Rules

            - Each `*.php` file can return:
              - a single `RecordTableType`, or
              - an array like `['table_name' => RecordTableType, ...]`
            - The filename (without `.php`) is used as the table key when returning a single `RecordTableType`.

            ## Example (single table)

            Create `config/records/tables/customers.php`:

            ```php
            <?php

            use App\Record\Validators\CustomerValidator;
            use Sopheak\Core\Types\RecordTableType;

            return new RecordTableType(
                pmsName: 'customer',
                table: 'customers',
                isAuthRead: true,
                isAuthWrite: true,
                relationships: [],
                softDeletes: true,
                hasTenantId: false,
                createValidator: [CustomerValidator::class, 'createCustomer'],
            );
            ```

            `config/sp-record.php` ships with `'autoloaded' => true`, which evaluates
            every file in this directory while the config file itself is being merged
            -- so its content must survive `php artisan config:cache`. Avoid Closures
            here (`var_export()` cannot serialize one); reference a `public static`
            method on a class instead, e.g.:

            ```php
            <?php

            declare(strict_types=1);

            namespace App\Record\Validators;

            use Illuminate\Contracts\Validation\Validator as ValidatorContract;
            use Illuminate\Http\Request;
            use Illuminate\Support\Facades\Validator;

            class CustomerValidator
            {
                public static function createCustomer(Request $request, int|string|null $id = null): ValidatorContract
                {
                    return Validator::make($request->all(), [
                        'name' => 'required|string|max:255',
                    ]);
                }
            }
            ```

            The method must be `public static`: the package invokes a
            `[ClassName::class, 'method']` reference as a plain PHP callable, and
            `is_callable()` only resolves that array form without an object
            instance when the method is static.

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

            use Sopheak\Core\Enums\RecordFunctionMethodEnum;
            use Sopheak\Core\Types\RecordFunctionType;

            return [
                'login' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
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

            use App\Record\Validators\UserValidator;
            use Sopheak\Core\Types\RecordTableType;

            // Validators are [ClassName::class, 'method'] references, not Closures:
            // config/sp-record.php ships with 'autoloaded' => true, which evaluates
            // this file while the config file itself is being merged, so its content
            // must survive `php artisan config:cache` (var_export() cannot serialize
            // a Closure). See app/Record/Validators/UserValidator.php.
            return new RecordTableType(
                pmsName: 'user',
                table: 'users',
                isAuthRead: true,
                isAuthWrite: true,
                relationships: [],
                functions: [],
                softDeletes: false,
                hasTenantId: false,
                createValidator: [UserValidator::class, 'createUser'],
                updateValidator: [UserValidator::class, 'updateUser'],
                deleteValidator: [UserValidator::class, 'deleteUser'],
            );
            PHP;
    }

    private function defaultUserValidatorClass(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Record\Validators;

            use Illuminate\Contracts\Validation\Validator as ValidatorContract;
            use Illuminate\Http\Request;
            use Illuminate\Support\Facades\Validator;

            /**
             * Referenced from config/records/tables/users.php as
             * [UserValidator::class, 'createUser'] etc. Methods must stay `public
             * static`: the package invokes that array as a plain callable, and
             * `is_callable()` only resolves a [Class, 'method'] array without an
             * object instance when the method is static. config/sp-record.php's
             * 'autoloaded' scan also requires the table config (including this
             * reference) to survive `php artisan config:cache`, which a Closure
             * cannot.
             */
            class UserValidator
            {
                public static function createUser(Request $request, int|string|null $id = null): ValidatorContract
                {
                    return Validator::make($request->all(), [
                        'name' => 'required|string|max:255',
                        'email' => 'required|email',
                        'password' => 'required|string|min:8',
                    ]);
                }

                public static function updateUser(Request $request, int|string|null $id = null): ValidatorContract
                {
                    return Validator::make($request->all(), [
                        'name' => 'sometimes|required|string|max:255',
                        'email' => 'sometimes|required|email',
                        'password' => 'sometimes|required|string|min:8',
                    ]);
                }

                public static function deleteUser(Request $request, int|string|null $id = null): ValidatorContract
                {
                    // Not `integer`: this package supports uuid primary keys via
                    // record.id_type, so the scaffold cannot assume the key is
                    // numeric. Narrow this to match your own table's key type.
                    return Validator::make(['id' => $id], [
                        'id' => 'required',
                    ]);
                }
            }
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
                | - route_prefix: Deprecated, no effect. The MCP routes are always /{api_prefix}/mcp/...; kept for compatibility.
                | - middleware: The middleware applied to the HTTP endpoints.
                | - driver: 'legacy' (default) serves MCP from the package's own JSON-RPC
                |   server. 'laravel' serves it through laravel/mcp (Streamable HTTP, current
                |   protocol versions, OAuth, the Inspector); install it with
                |   `composer require laravel/mcp`.
                | - oauth: With the 'laravel' driver, publish OAuth discovery routes
                |   (requires laravel/passport). Default: false.
                */
                'mcp' => [
                    'enabled' => env('SP_MCP_ENABLED', false),
                    'read_only' => env('SP_MCP_READ_ONLY', true),
                    'route_prefix' => env('SP_MCP_ROUTE_PREFIX', 'mcp'),
                    'middleware' => ['api', 'auth:sanctum'],
                    'driver' => env('SP_MCP_DRIVER', 'legacy'),
                    'oauth' => env('SP_MCP_OAUTH', false),
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
                'restrict_to_own_records' => false, // DEPRECATED — has no effect. Use the viewOwn:{pmsName} permission (see own_records_permission_prefix).
                'own_records_permission_prefix' => 'viewOwn', // example: viewOwn_invoice
                // Owner-column resolution order for viewOwn scoping (first declared column wins).
                // Prepend 'user_id' when domain tables track the record owner there.
                'own_records_owner_columns' => ['created_by_id', 'created_by'],

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

                // Optional mutation policy: interface class or [ClassName::class, 'method'].
                'filter' => null,

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
                                httpMethod: [\Sopheak\Core\Enums\RecordFunctionMethodEnum::GET->value],
                                description: 'Get audit statistics'
                            ),
                            'field-timeline/{entityType}/{entityId}/{field}' => new \Sopheak\Core\Types\RecordFunctionType(
                                class: \Sopheak\Core\Http\Controllers\AuditLogController::class,
                                functionName: 'getFieldTimeline',
                                httpMethod: [\Sopheak\Core\Enums\RecordFunctionMethodEnum::GET->value],
                                description: 'Get field timeline'
                            ),
                            'field-stats/{entityType}/{entityId}/{field}' => new \Sopheak\Core\Types\RecordFunctionType(
                                class: \Sopheak\Core\Http\Controllers\AuditLogController::class,
                                functionName: 'getFieldStats',
                                httpMethod: [\Sopheak\Core\Enums\RecordFunctionMethodEnum::GET->value],
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
        return $this->shippedConfig('sp-webhooks.php');
    }

    private function defaultAttachmentsConfig(): string
    {
        return $this->shippedConfig('sp-attachments.php');
    }

    /**
     * Read one of the package's own shipped config files verbatim.
     *
     * Throws rather than falling back to an empty config: a falsy read means
     * the shipped file moved or is unreadable, and silently scaffolding
     * `<?php return [];` into a client's application would hand them a
     * working-looking but empty config.
     */
    private function shippedConfig(string $filename): string
    {
        $path = __DIR__ . '/../../config/' . $filename;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false || $contents === '') {
            throw new RuntimeException('Failed to read packaged config file: ' . $path);
        }

        return $contents;
    }
}
