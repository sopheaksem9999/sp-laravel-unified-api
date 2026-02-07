<?php

namespace Sopheak\Core\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Sopheak\Core\CoreSpLaravelApiProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            CoreSpLaravelApiProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('sp-laravel-api.auth.guard', 'api');
        $app['config']->set('auth.guards.api', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'database',
            'table' => 'users',
        ]);

        $app['config']->set('record.api_prefix', 'api');
        $app['config']->set('record.enable_tenant_id', false);
        $app['config']->set('record.tables', []);
        $app['config']->set('record.cache', [
            'enabled' => true,
            'default_ttl' => 3600,
            'per_table' => [],
            'per_table_ttl' => [],
        ]);
        $app['config']->set('audit.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Define rate limiters used by routes to avoid missing limiter errors in tests
        RateLimiter::for('api-reads', fn() => Limit::perMinute(1000));
        RateLimiter::for('api-writes', fn() => Limit::perMinute(1000));
        RateLimiter::for('api-functions', fn() => Limit::perMinute(1000));

        // Ensure audit_logs table exists to avoid runtime errors in tests
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('title')->nullable();
                $table->longText('old_data')->nullable();
                $table->longText('new_data')->nullable();
                $table->text('recap')->nullable();
                $table->string('subject')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('entity_type')->nullable();
                $table->string('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('entity_name')->nullable();
                $table->string('event')->nullable();
                $table->longText('metadata')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }
}
