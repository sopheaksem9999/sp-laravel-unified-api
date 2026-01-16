<?php

namespace Sopheak\Core;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Sopheak\Core\Http\Middleware\RequestId;
use Sopheak\Core\Console\GenerateOpenApiSpecCommand;
use Sopheak\Core\Console\SetupPackageCommand;
use Sopheak\Core\Console\ValidateSetupCommand;
use Sopheak\Core\Console\GenerateRecordSchemaCacheCommand;
use Sopheak\Core\Console\CleanAuditLogsCommand;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordService;

class CoreSpLaravelApiProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-laravel-api.php', 'sp-laravel-api');

        $this->app->singleton('api.response', fn(): RecordApiResponseService => new RecordApiResponseService());

        $this->app->singleton(AuditLogService::class);
        $this->app->singleton(QueryCacheService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/sp-laravel-api.php' => config_path('sp-laravel-api.php'),
            __DIR__ . '/../config/audit.php' => config_path('audit.php'),
            __DIR__ . '/../config/record.php' => config_path('record.php'),
        ], 'sp-laravel-api-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'sp-laravel-api-migrations');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'sp-laravel-api');

        // Load package routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $commands = [
                GenerateOpenApiSpecCommand::class,
                SetupPackageCommand::class,
                ValidateSetupCommand::class,
                GenerateRecordSchemaCacheCommand::class,
                CleanAuditLogsCommand::class,
            ];

            $commands = array_values(array_filter($commands, class_exists(...)));
            $this->commands($commands);
        }

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('request.id', RequestId::class);

        Builder::macro('applyRequestFilters', function (Request $request, ?string $tenantColumn = ''): array {
            /** @var Builder $this */
            return RecordService::applyRequestFilters($request, $this, $tenantColumn);
        });
    }
}
