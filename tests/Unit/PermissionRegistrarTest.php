<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\PermissionRegistrar;
use Sopheak\Core\Authorization\PermissionService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;

class PermissionRegistrarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();

        $this->app['config']->set('permission.enabled', true);
        $this->app['config']->set('permission.auto_register', true);
        $this->app['config']->set('permission.auto_register_functions', true);
        $this->app['config']->set('sp-laravel-api.auth.guard', 'api');
        $this->app['config']->set('record.permission_separator', ':');
    }

    /** @test */
    public function it_auto_registers_permissions_from_table_config(): void
    {
        $this->app['config']->set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoice',
                canRead: true,
                canCreate: true,
                canUpdate: true,
                canDelete: true,
            ),
        ]);

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->autoRegisterFromConfig();

        $this->assertDatabaseHas('sp_permissions', ['name' => 'view:invoice', 'group' => 'invoice']);
        $this->assertDatabaseHas('sp_permissions', ['name' => 'create:invoice', 'group' => 'invoice']);
        $this->assertDatabaseHas('sp_permissions', ['name' => 'update:invoice', 'group' => 'invoice']);
        $this->assertDatabaseHas('sp_permissions', ['name' => 'delete:invoice', 'group' => 'invoice']);
    }

    /** @test */
    public function it_auto_registers_partial_permissions(): void
    {
        $this->app['config']->set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoice',
                canRead: true,
                canCreate: false,
                canUpdate: true,
                canDelete: false,
            ),
        ]);

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->autoRegisterFromConfig();

        $this->assertDatabaseHas('sp_permissions', ['name' => 'view:invoice']);
        $this->assertDatabaseHas('sp_permissions', ['name' => 'update:invoice']);
        $this->assertDatabaseMissing('sp_permissions', ['name' => 'create:invoice']);
        $this->assertDatabaseMissing('sp_permissions', ['name' => 'delete:invoice']);
    }

    /** @test */
    public function it_auto_registers_custom_permission_map(): void
    {
        $this->app['config']->set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoice',
                permissions: [
                    'read' => 'view_invoice',
                    'create' => ['create_invoice', 'create_bill'],
                ],
            ),
        ]);

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->autoRegisterFromConfig();

        $this->assertDatabaseHas('sp_permissions', ['name' => 'view_invoice', 'group' => 'invoice']);
        $this->assertDatabaseHas('sp_permissions', ['name' => 'create_invoice', 'group' => 'invoice']);
        $this->assertDatabaseHas('sp_permissions', ['name' => 'create_bill', 'group' => 'invoice']);
    }

    /** @test */
    public function it_does_not_duplicate_permissions(): void
    {
        $this->app['config']->set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoice',
                canRead: true,
            ),
        ]);

        $registrar = $this->app->make(PermissionRegistrar::class);

        $registrar->autoRegisterFromConfig();
        $registrar->autoRegisterFromConfig();

        $this->assertEquals(1, Permission::query()->where('name', 'view:invoice')->count());
    }

    /** @test */
    public function it_auto_registers_with_array_pmsName(): void
    {
        $this->app['config']->set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: ['invoice', 'bill'],
                canRead: true,
            ),
        ]);

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->autoRegisterFromConfig();

        $this->assertDatabaseHas('sp_permissions', ['name' => 'view:invoice']);
        $this->assertDatabaseHas('sp_permissions', ['name' => 'view:bill']);
    }

    /** @test */
    public function it_increments_cache_version_on_forget_all(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $version1 = $registrar->getCacheVersion();

        $registrar->forgetAllCachedPermissions();

        $version2 = $registrar->getCacheVersion();

        $this->assertEquals($version1 + 1, $version2);
    }

    /** @test */
    public function it_skips_auto_register_when_config_unchanged(): void
    {
        $this->app['config']->set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoice',
                canRead: true,
                canCreate: false,
                canUpdate: false,
                canDelete: false,
            ),
        ]);

        $registrar = $this->app->make(PermissionRegistrar::class);

        // First call — registers and stores hash
        $registrar->autoRegisterFromConfig();
        $this->assertDatabaseHas('sp_permissions', ['name' => 'view:invoice']);

        // Manually assert the hash was stored
        $configHashKey = (new \ReflectionClass($registrar))->getProperty('configHashKey');
        $configHashKey->setAccessible(true);
        $hash = $this->app['cache']->get($configHashKey->getValue($registrar));
        $this->assertNotEmpty($hash);

        // Second call — should skip (config unchanged), permission count unchanged
        $registrar->autoRegisterFromConfig();
        $this->assertEquals(1, Permission::query()->where('name', 'view:invoice')->count());
    }

    /** @test */
    public function it_auto_registers_when_config_changes(): void
    {
        $this->app['config']->set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoice',
                canRead: true,
                canCreate: false,
                canUpdate: false,
                canDelete: false,
            ),
        ]);

        $registrar = $this->app->make(PermissionRegistrar::class);

        // First config
        $registrar->autoRegisterFromConfig();
        $this->assertDatabaseHas('sp_permissions', ['name' => 'view:invoice']);

        // Change config
        $this->app['config']->set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoice',
                canRead: true,
                canCreate: true,
                canUpdate: false,
                canDelete: false,
            ),
        ]);

        // Second call — config changed, should register new permissions
        $registrar->autoRegisterFromConfig();
        $this->assertDatabaseHas('sp_permissions', ['name' => 'create:invoice']);
    }

    /** @test */
    public function permission_service_returns_false_for_user_without_trait(): void
    {
        Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);

        $user = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'users';
            public $timestamps = false;
        };

        // Need users table
        if (!\Illuminate\Support\Facades\Schema::hasTable('users')) {
            \Illuminate\Support\Facades\Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
            });
        }

        $user->save();

        $service = $this->app->make(PermissionService::class);

        $this->assertFalse($service->userHasPermission($user, 'view:invoice'));
    }

    /** @test */
    public function permission_service_detects_when_enabled(): void
    {
        $service = $this->app->make(PermissionService::class);

        $this->assertTrue($service->isBuiltInPermissionEnabled());
    }

    protected function createPermissionTables(): void
    {
        if (!Schema::hasTable('sp_permissions')) {
            Schema::create('sp_permissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name')->unique();
                $table->string('group')->nullable();
                $table->string('guard_name');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sp_roles')) {
            Schema::create('sp_roles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name')->unique();
                $table->string('guard_name');
                $table->text('description')->nullable();
                $table->boolean('is_system')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sp_role_permissions')) {
            Schema::create('sp_role_permissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->timestamps();
                $table->unique(['role_id', 'permission_id']);
            });
        }

        if (!Schema::hasTable('sp_model_roles')) {
            Schema::create('sp_model_roles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->unsignedBigInteger('role_id');
                $table->string('tenant_id')->nullable();
                $table->timestamps();
                $table->index(['model_type', 'model_id']);
            });
        }

        if (!Schema::hasTable('sp_model_permissions')) {
            Schema::create('sp_model_permissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->unsignedBigInteger('permission_id');
                $table->string('tenant_id')->nullable();
                $table->timestamps();
                $table->index(['model_type', 'model_id']);
            });
        }
    }
}
