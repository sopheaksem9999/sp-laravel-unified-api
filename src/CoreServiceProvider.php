<?php

namespace Sopheak\Core;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Sopheak\Core\Http\Middleware\RequestId;
use Sopheak\Core\Console\GenerateOpenApiSpec;
use Sopheak\Core\Console\SetupPackage;
use Sopheak\Core\Console\Records\ClearRecordCache;
use Sopheak\Core\Console\Records\GetRecordCache;
use Sopheak\Core\Console\Records\RecordRefreshCache;
use Sopheak\Core\Console\CleanAuditLogs;
use Sopheak\Core\Services\ApiResponseService;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\CursorPagination;
use Sopheak\Core\Services\QueryCacheService;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-laravel-api.php', 'sp-laravel-api');

        $this->app->singleton('api.response', function () {
            return new ApiResponseService();
        });

        $this->app->singleton(AuditLogService::class);
        $this->app->singleton(CursorPagination::class);
        $this->app->singleton(QueryCacheService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/sp-laravel-api.php' => config_path('sp-laravel-api.php'),
            __DIR__ . '/../config/audit.php' => config_path('audit.php'),
            __DIR__ . '/../config/record.php' => config_path('record.php'),
            __DIR__ . '/../config/cursor_pagination.php' => config_path('cursor_pagination.php'),
        ], 'sp-laravel-api-config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'sp-laravel-api');
        
        // Load package routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateOpenApiSpec::class,
                SetupPackage::class,
                ClearRecordCache::class,
                GetRecordCache::class,
                RecordRefreshCache::class,
                CleanAuditLogs::class,
            ]);
        }

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('request.id', RequestId::class);
    }
}
