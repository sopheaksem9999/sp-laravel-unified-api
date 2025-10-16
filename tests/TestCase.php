<?php

namespace Sopheak\Core\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Sopheak\Core\CoreServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
        $this->setUpConfig();
    }

    protected function getPackageProviders($app): array
    {
        return [
            CoreServiceProvider::class,
            PermissionServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup cache
        $app['config']->set('cache.default', 'array');

        // Setup queue
        $app['config']->set('queue.default', 'sync');

        // Setup session
        $app['config']->set('session.driver', 'array');

        // Setup auth
        $app['config']->set('auth.defaults.guard', 'api');
        $app['config']->set('auth.guards.api', [
            'driver' => 'jwt',
            'provider' => 'users',
        ]);

        // Setup JWT
        $app['config']->set('jwt.secret', 'test-secret-key');
        $app['config']->set('jwt.ttl', 60);

        // Setup SP Laravel API
        $app['config']->set('record.api_prefix', 'api');
        $app['config']->set('record.cache_ttl', 3600);
        $app['config']->set('record.lazy_cache_ttl', 300);
        $app['config']->set('record.max_depth', 3);
        $app['config']->set('record.enable_tenant_id', false);

        // Setup audit logging
        $app['config']->set('audit.enabled', true);
        $app['config']->set('audit.queue', 'sync');
        $app['config']->set('audit.cleanup.enabled', false);

        // Setup cursor pagination
        $app['config']->set('cursor_pagination.per_page', 15);
        $app['config']->set('cursor_pagination.max_per_page', 100);
    }

    protected function setUpDatabase(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        
        // Load Spatie Permission migrations
        $this->loadMigrationsFrom(
            dirname(__DIR__) . '/vendor/spatie/laravel-permission/database/migrations'
        );
    }

    protected function setUpConfig(): void
    {
        Config::set('record.tables', [
            'users' => [
                'model' => \Illuminate\Foundation\Auth\User::class,
                'permissions' => [
                    'view' => 'view_users',
                    'create' => 'create_users',
                    'update' => 'update_users',
                    'delete' => 'delete_users',
                ],
                'relationships' => [],
                'soft_deletes' => false,
                'has_tenant_id' => false,
            ],
        ]);
    }

    /**
     * Create a test user with permissions
     */
    protected function createTestUser(array $permissions = []): \Illuminate\Foundation\Auth\User
    {
        $user = new \Illuminate\Foundation\Auth\User([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        $user->save();

        if (!empty($permissions)) {
            foreach ($permissions as $permission) {
                $user->givePermissionTo($permission);
            }
        }

        return $user;
    }

    /**
     * Create test permissions
     */
    protected function createTestPermissions(): void
    {
        $permissions = [
            'view_users',
            'create_users',
            'update_users',
            'delete_users',
        ];

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::create(['name' => $permission]);
        }
    }

    /**
     * Assert API response structure
     */
    protected function assertApiResponse($response, int $statusCode = 200): void
    {
        $response->assertStatus($statusCode);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => [
                'request_id',
                'timestamp',
            ],
        ]);
    }

    /**
     * Assert paginated API response structure
     */
    protected function assertPaginatedApiResponse($response, int $statusCode = 200): void
    {
        $response->assertStatus($statusCode);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => [
                'request_id',
                'timestamp',
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                ],
            ],
        ]);
    }
}