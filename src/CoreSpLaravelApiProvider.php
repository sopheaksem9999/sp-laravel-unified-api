<?php

declare(strict_types=1);

namespace Sopheak\Core;

use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Sopheak\Core\Mcp\Servers\DataServer;
use Sopheak\Core\Mcp\Servers\SchemaServer;
use Sopheak\Core\Http\Middleware\SetPostgresTenantContext;
use Throwable;
use Sopheak\Core\Console\CacheStatusCommand;
use Sopheak\Core\Console\CleanTempAttachmentsCommand;
use Sopheak\Core\Console\ExportBrunoCommand;
use Sopheak\Core\Console\ExportPostmanCommand;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Sopheak\Core\Console\CleanAuditLogsCommand;
use Sopheak\Core\Console\ExportOpenApiCommand;
use Sopheak\Core\Console\GenerateRecordTablesFromDatabaseCommand;
use Sopheak\Core\Console\ListTablesCommand;
use Sopheak\Core\Console\MakeRecordTableCommand;
use Sopheak\Core\Console\SetupPackageCommand;
use Sopheak\Core\Console\SyncRecordColumnsCommand;
use Sopheak\Core\Console\ValidateSetupCommand;
use Sopheak\Core\Http\Middleware\RecordRouteMiddleware;
use Sopheak\Core\Http\Middleware\RequestId;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Support\CacheRequestContext;
use Sopheak\Core\Console\McpServerCommand;
use Sopheak\Core\Console\EnablePgsqlRlsCommand;
use Sopheak\Core\Console\BoostInstallCommand;
use Sopheak\Core\Console\AgentSetupCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Sopheak\Core\Config\ConfigNamespaceBridge;
use Sopheak\Core\Events\RecordCreated;
use Sopheak\Core\Events\RecordDeleted;
use Sopheak\Core\Events\RecordUpdated;
use Sopheak\Core\Authorization\PermissionRegistrar;
use Sopheak\Core\Contracts\Attachment\AttachmentMultipartDriver;
use Sopheak\Core\Services\AttachmentMultipart\S3MultipartDriver;
use Sopheak\Core\Listeners\InvalidateRecordCacheListener;
use Sopheak\Core\Listeners\LogRecordAuditListener;
use Sopheak\Core\Mcp\McpDriver;

class CoreSpLaravelApiProvider extends ServiceProvider
{
    public function register(): void
    {
        // Pass A: fold a client's published sp-*.php into the canonical
        // namespaces BEFORE the merges below, because sp-permissions.php reads
        // config('record.id_type') while it is being merged.
        ConfigNamespaceBridge::adopt($this->app['config']);

        $this->mergeConfigFrom(__DIR__ . '/../config/sp-laravel-api.php', 'sp-laravel-api');
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-attachments.php', 'attachments');
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-webhooks.php', 'webhooks');
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-audit.php', 'audit');
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-permissions.php', 'permissions');
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-api-mcp.php', 'sp-api-mcp');

        // The `laravel` MCP driver needs laravel/mcp's own provider: its container
        // callback populates a tool's arguments and it adds the global middleware
        // that puts the OAuth discovery hint on a 401. Package auto-discovery
        // provides it, but an app with discovery off would not get it; registering a
        // provider twice is harmless. Done in a booting callback so the app's
        // configuration is final (a bare Testbench app applies its config after
        // register()), and so the provider still boots with the rest.
        $this->app->booting(function (): void {
            McpDriver::assertInstalled();
            if (McpDriver::isActive()) {
                $this->app->register(McpServiceProvider::class);
            }
        });


        $this->app->singleton('api.response', fn(): RecordApiResponseService => new RecordApiResponseService());
        $this->app->singleton(AuditLogService::class);
        $this->app->singleton(QueryCacheService::class);
        $this->app->scoped(CacheRequestContext::class);

        // Multipart direct uploads need the raw S3 client, which only exists
        // when the host app has the s3 driver installed. Bind the driver only
        // when the SDK is present; without it multipart endpoints report 422.
        if (class_exists(S3MultipartDriver::class)) {
            $this->app->bind(AttachmentMultipartDriver::class, S3MultipartDriver::class);
        }
    }

    public function boot(): void
    {
        // Pass B: mirror the resolved canonical values onto the sp-* names so a
        // migrated client can read either. In boot() rather than register() so
        // it reflects anything Testbench's getEnvironmentSetUp() changed.
        ConfigNamespaceBridge::mirror($this->app['config']);

        $this->reportDeprecatedConfigFiles();

        $this->publishes([
            __DIR__ . '/../config/sp-laravel-api.php' => config_path('sp-laravel-api.php'),
            __DIR__ . '/../config/sp-audit.php' => config_path('sp-audit.php'),
            __DIR__ . '/../config/sp-record.php' => config_path('sp-record.php'),
            __DIR__ . '/../config/sp-attachments.php' => config_path('sp-attachments.php'),
            __DIR__ . '/../config/sp-webhooks.php' => config_path('sp-webhooks.php'),
            __DIR__ . '/../config/sp-permissions.php' => config_path('sp-permissions.php'),
            __DIR__ . '/../config/sp-api-mcp.php' => config_path('sp-api-mcp.php'),
        ], 'sp-laravel-api-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'sp-laravel-api-migrations');

        $this->publishes([
            __DIR__ . '/../resources/agent/skills/' => base_path('.agents/skills'),
            __DIR__ . '/../resources/agent/guidelines/' => base_path('.agents/rules'),
        ], 'sp-laravel-api-agent');

        // Automatically load migrations from the package
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'sp-laravel-api');

        // Load package routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        // stdio servers for `mcp:start` / `mcp:inspector` and the
        // sp-laravel-api:mcp command, when the `laravel` MCP driver is selected.
        if (McpDriver::isActive()) {
            Mcp::local('sp-laravel-api', DataServer::class);
            Mcp::local('sp-laravel-api-schema', SchemaServer::class);
        }

        // Opt-in OAuth 2.1 discovery (protected-resource and authorization-server
        // metadata, dynamic client registration) for connectors that require it.
        if (McpDriver::isActive() && McpDriver::oauthEnabled()) {
            McpDriver::assertOAuthAvailable();
            Mcp::oauthRoutes();
        }

        $this->commands([
            McpServerCommand::class,
        ]);

        if ($this->app->runningInConsole()) {
            $commands = [
                SetupPackageCommand::class,
                ValidateSetupCommand::class,
                SyncRecordColumnsCommand::class,
                CleanAuditLogsCommand::class,
                MakeRecordTableCommand::class,
                GenerateRecordTablesFromDatabaseCommand::class,
                ExportOpenApiCommand::class,
                ExportBrunoCommand::class,
                ExportPostmanCommand::class,
                ListTablesCommand::class,
                CacheStatusCommand::class,
                CleanTempAttachmentsCommand::class,
                EnablePgsqlRlsCommand::class,
                BoostInstallCommand::class,
                AgentSetupCommand::class,
            ];

            $commands = array_values(array_filter($commands, class_exists(...)));
            $this->commands($commands);
        }

        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('request.id', RequestId::class);
        $router->aliasMiddleware('record.route.middleware', RecordRouteMiddleware::class);
        $router->aliasMiddleware('pgsql.tenant', SetPostgresTenantContext::class);

        Event::listen([RecordCreated::class, RecordUpdated::class, RecordDeleted::class], InvalidateRecordCacheListener::class);
        Event::listen([RecordCreated::class, RecordUpdated::class, RecordDeleted::class], LogRecordAuditListener::class);

        // The container is not rebuilt per request under Octane or in Testbench, so
        // scoped bindings alone are not enough for HTTP. Queue jobs are covered by
        // QueueServiceProvider's forgetScopedInstances().
        Event::listen(RouteMatched::class, static function (): void {
            app(CacheRequestContext::class)->reset();
        });

        if (config('permissions.enabled', false)) {
            $this->app->singleton(PermissionRegistrar::class);

            $this->app->booted(function (): void {
                try {
                    $registrar = app(PermissionRegistrar::class);
                    // Needs no database, so it runs before auto-registration,
                    // which fails until the permission tables are migrated.
                    $registrar->registerPermissions();
                    $registrar->autoRegisterFromConfig();
                } catch (Throwable) {
                    // Permission tables may not exist yet (pre-migration)
                    // Silently skip — auto-registration will happen on next boot
                }
            });
        }

        Route::bind('table', function (string $value): string {
            $tableConfig = RecordConfigService::getTableConfig($value);
            abort_if(! $tableConfig, 404, sprintf('Dynamic Table [%s] not found.', $value));

            return $value;
        });

        /**
         * @param Request     $request       HTTP request carrying query parameters.
         * @param bool        $isArray       When true, shape results as an array on the client side.
         * @param string      $orderBy       Default column to sort by when sortby is not provided.
         * @param mixed       $tenantId      Optional tenant ID value for multi-tenant scoping.
         *
         * @return array{
         *     data: mixed,
         *     meta: array,
         *     headers: array,
         *     filters: array,
         *     request: Request,
         *     cursor_meta: mixed
         * }
         */
        Builder::macro('applyRequestFilters', function (Request $request, bool $isArray = true, string $orderBy = 'id', mixed $tenantId = null): array {
            /** @var Builder $this */
            return RecordService::applyRequestFilters($request, $this, $tenantId, $isArray, $orderBy);
        });
    }

    /**
     * Log one notice per deprecated (unprefixed) config file the client still has.
     *
     * Console-only, deliberately. boot() runs once per application instance,
     * which under Octane or a queue worker means once per worker — but PHP-FPM
     * is shared-nothing and boots the application afresh on every request, so
     * an unmigrated client with all five old files would otherwise get five
     * Log::info lines on *every* HTTP request at Laravel's default log level.
     * The notice's audience is a developer running artisan (migrate,
     * vendor:publish), not a request being served, so gate it on the console.
     *
     * Public so a test can exercise it without re-running the whole of boot().
     */
    public function reportDeprecatedConfigFiles(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        if ((bool) config('sp-laravel-api.suppress_config_rename_notice', false)) {
            return;
        }

        $superseded = ConfigNamespaceBridge::supersededFiles();

        foreach (ConfigNamespaceBridge::deprecatedFiles() as $old => $new) {
            // "It keeps working" is only true while the old file is the only
            // one of the pair on disk. Once the sp-* counterpart exists too,
            // adopt() gives it precedence for every key both files set, so the
            // old file has stopped being fully in effect and telling the
            // client it still works would be a false promise.
            if (isset($superseded[$old])) {
                Log::warning(sprintf(
                    'sp-laravel-api: config/%s is deprecated AND no longer fully in effect — '
                    . 'config/%s is also present and its values win for every key both files set. '
                    . 'Copy anything you still need into config/%s and delete config/%s. '
                    . 'Set sp-laravel-api.suppress_config_rename_notice to silence this.',
                    $old,
                    $new,
                    $new,
                    $old
                ));

                continue;
            }

            Log::info(sprintf(
                'sp-laravel-api: config/%s is deprecated; rename it to config/%s. '
                . 'It keeps working — set sp-laravel-api.suppress_config_rename_notice to silence this.',
                $old,
                $new
            ));
        }
    }
}
