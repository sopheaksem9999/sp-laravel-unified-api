<?php

namespace Sopheak\Core\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Sopheak\Core\CoreSpLaravelApiProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

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
        // Clear static schema cache BEFORE the service provider boots and registers routes.
        // This prevents stale table configs from a previous test from polluting the route
        // $tableWhere regex built during this app's boot.
        SchemaRegistryUtils::refresh();

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
        $app['config']->set('record.rpc_prefix', 'rpc');
        $app['config']->set('record.enable_tenant_id', false);
        $app['config']->set('record.tables', []);
        $app['config']->set('attachments.tables', []);
        $app['config']->set('webhooks.tables', []);
        $app['config']->set('record.global_triggers', []);
        $app['config']->set('record.default_validation', [
            'enabled' => false,
        ]);
        $app['config']->set('record.cache', [
            'enabled' => true,
            'default_ttl' => 3600,
            'per_table' => [],
            'per_table_ttl' => [],
        ]);
        $app['config']->set('audit.enabled', false);
        $app['config']->set('audit.audit_log_model', 'sp_audit_logs');
        $app['config']->set('audit.queue_enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clear static schema registry cache between tests to prevent cross-test contamination
        SchemaRegistryUtils::refresh();

        // Define rate limiters used by routes to avoid missing limiter errors in tests
        RateLimiter::for('api-reads', fn() => Limit::perMinute(1000));
        RateLimiter::for('api-writes', fn() => Limit::perMinute(1000));
        RateLimiter::for('api-functions', fn() => Limit::perMinute(1000));

        // Ensure sp_audit_logs table exists to avoid runtime errors in tests
        if (!Schema::hasTable('sp_audit_logs')) {
            Schema::create('sp_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('entity_type')->nullable();
                $table->string('entity_id')->nullable();
                $table->string('entity_name')->nullable();
                $table->string('event')->nullable();
                $table->string('title')->nullable();
                $table->string('subject')->nullable();
                $table->text('recap')->nullable();
                $table->text('old_data')->nullable();
                $table->text('new_data')->nullable();
                $table->string('user_id')->nullable();
                $table->string('tenant_id')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address')->nullable();
                $table->string('user_agent')->nullable();
                $table->string('request_id')->nullable();
                $table->timestamps();
            });
        }
    }
}
