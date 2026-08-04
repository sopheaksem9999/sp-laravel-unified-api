<?php

declare(strict_types=1);

namespace Sopheak\Core;

use Sopheak\Core\Http\Middleware\SetPostgresTenantContext;
use Throwable;
use Sopheak\Core\Console\CacheStatusCommand;
use Sopheak\Core\Console\CleanTempAttachmentsCommand;
use Sopheak\Core\Console\ExportBrunoCommand;
use Sopheak\Core\Console\ExportPostmanCommand;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
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
use Sopheak\Core\Console\McpServerCommand;
use Sopheak\Core\Console\EnablePgsqlRlsCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Sopheak\Core\Config\ConfigNamespaceBridge;
use Sopheak\Core\Events\RecordCreated;
use Sopheak\Core\Events\RecordDeleted;
use Sopheak\Core\Events\RecordUpdated;
use Sopheak\Core\Authorization\PermissionRegistrar;
use Sopheak\Core\Listeners\InvalidateRecordCacheListener;
use Sopheak\Core\Listeners\LogRecordAuditListener;

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


        $this->app->singleton('api.response', fn(): RecordApiResponseService => new RecordApiResponseService());
        $this->app->singleton(AuditLogService::class);
        $this->app->singleton(QueryCacheService::class);
    }

    public function boot(): void
    {
        // Pass B: mirror the resolved canonical values onto the sp-* names so a
        // migrated client can read either. In boot() rather than register() so
        // it reflects anything Testbench's getEnvironmentSetUp() changed.
        ConfigNamespaceBridge::mirror($this->app['config']);

        foreach (ConfigNamespaceBridge::deprecatedFiles() as $old => $new) {
            if ((bool) config('sp-laravel-api.suppress_config_rename_notice', false)) {
                break;
            }

            Log::info(sprintf(
                'sp-laravel-api: config/%s is deprecated; rename it to config/%s. '
                . 'It keeps working — set sp-laravel-api.suppress_config_rename_notice to silence this.',
                $old,
                $new
            ));
        }

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

        // Automatically load migrations from the package
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'sp-laravel-api');

        // Load package routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        if (config('record.mcp.enabled', false)) {
            $this->commands([
                McpServerCommand::class,
            ]);
        }

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

        if (config('permissions.enabled', false)) {
            $this->app->singleton(PermissionRegistrar::class);

            $this->app->booted(function (): void {
                try {
                    $registrar = app(PermissionRegistrar::class);
                    $registrar->autoRegisterFromConfig();
                    $registrar->registerPermissions();
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
}
