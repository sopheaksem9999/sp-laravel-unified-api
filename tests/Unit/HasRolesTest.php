<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Authorization\Traits\HasRoles;
use Sopheak\Core\Tests\TestCase;

class TestUserWithRoles extends Model
{
    use HasRoles;

    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;
}

class HasRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();

        $this->app['config']->set('permissions.enabled', true);
        $this->app['config']->set('sp-laravel-api.auth.guard', 'api');
    }

    /** @test */
    public function it_can_assign_role_to_user(): void
    {
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->assignRole('admin');

        $this->assertTrue($user->hasRole('admin'));
    }

    /** @test */
    public function it_can_assign_multiple_roles(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->assignRole(['admin', 'editor']);

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('editor'));
    }

    /** @test */
    public function it_can_remove_role(): void
    {
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->assignRole('admin');
        $this->assertTrue($user->hasRole('admin'));

        $user->removeRole('admin');
        $this->assertFalse($user->hasRole('admin'));
    }

    /** @test */
    public function it_can_sync_roles(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        Role::query()->create(['name' => 'viewer', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->assignRole(['admin', 'editor']);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('editor'));

        $user->syncRoles(['viewer']);

        $this->assertFalse($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('editor'));
        $this->assertTrue($user->hasRole('viewer'));
    }

    /** @test */
    public function it_can_check_any_role(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->assignRole('admin');

        $this->assertTrue($user->hasAnyRole(['admin', 'editor']));
        $this->assertFalse($user->hasAnyRole(['superadmin']));
    }

    /** @test */
    public function it_can_check_all_roles(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->assignRole(['admin', 'editor']);

        $this->assertTrue($user->hasAllRoles(['admin', 'editor']));
        $this->assertFalse($user->hasAllRoles(['admin', 'superadmin']));
    }

    /** @test */
    public function it_can_give_permission_to_user(): void
    {
        Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->givePermissionTo('view:invoice');

        $this->assertTrue($user->hasPermissionTo('view:invoice'));
    }

    /** @test */
    public function it_can_revoke_permission(): void
    {
        Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->givePermissionTo('view:invoice');
        $this->assertTrue($user->hasPermissionTo('view:invoice'));

        $user->revokePermissionTo('view:invoice');
        $this->assertFalse($user->hasPermissionTo('view:invoice'));
    }

    /** @test */
    public function it_can_sync_permissions(): void
    {
        Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'create:invoice', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'delete:invoice', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->givePermissionTo(['view:invoice', 'create:invoice']);

        $user->syncPermissions(['delete:invoice']);

        $this->assertFalse($user->hasPermissionTo('view:invoice'));
        $this->assertFalse($user->hasPermissionTo('create:invoice'));
        $this->assertTrue($user->hasPermissionTo('delete:invoice'));
    }

    /** @test */
    public function it_can_check_any_permission(): void
    {
        Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'create:invoice', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->givePermissionTo('view:invoice');

        $this->assertTrue($user->hasAnyPermission(['view:invoice', 'create:invoice']));
        $this->assertFalse($user->hasAnyPermission(['delete:invoice']));
    }

    /** @test */
    public function it_can_check_all_permissions(): void
    {
        Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'create:invoice', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->givePermissionTo(['view:invoice', 'create:invoice']);

        $this->assertTrue($user->hasAllPermissions(['view:invoice', 'create:invoice']));
        $this->assertFalse($user->hasAllPermissions(['view:invoice', 'delete:invoice']));
    }

    /** @test */
    public function it_gets_permissions_from_roles(): void
    {
        $perm = Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        $role->givePermissionTo('view:invoice');

        $user = $this->createUserWithTrait();
        $user->assignRole('admin');

        $this->assertTrue($user->hasPermissionTo('view:invoice'));
    }

    /** @test */
    public function it_merges_role_and_direct_permissions(): void
    {
        Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'create:invoice', 'guard_name' => 'api']);
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        $role->givePermissionTo('view:invoice');

        $user = $this->createUserWithTrait();
        $user->assignRole('admin');
        $user->givePermissionTo('create:invoice');

        $allPermissions = $user->getAllPermissions();

        $this->assertCount(2, $allPermissions);
        $this->assertTrue($allPermissions->contains('view:invoice'));
        $this->assertTrue($allPermissions->contains('create:invoice'));
    }

    /** @test */
    public function it_does_not_duplicate_permissions(): void
    {
        $perm = Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        $role->givePermissionTo('view:invoice');

        $user = $this->createUserWithTrait();
        $user->assignRole('admin');
        $user->givePermissionTo('view:invoice');

        $allPermissions = $user->getAllPermissions();

        $this->assertCount(1, $allPermissions);
    }

    /** @test */
    public function it_handles_role_object_in_assign(): void
    {
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->assignRole($role);

        $this->assertTrue($user->hasRole('admin'));
    }

    /** @test */
    public function it_prevents_deleting_system_role(): void
    {
        $role = Role::query()->create(['name' => 'superadmin', 'guard_name' => 'api', 'is_system' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete system role: superadmin');

        $role->delete();
    }

    /** @test */
    public function it_allows_deleting_non_system_role(): void
    {
        $role = Role::query()->create(['name' => 'custom', 'guard_name' => 'api', 'is_system' => false]);

        $role->delete();

        $this->assertDatabaseMissing('sp_roles', ['name' => 'custom']);
    }

    /** @test */
    public function it_invalidates_cache_when_permission_is_created(): void
    {
        $user = $this->createUserWithTrait();
        $registrar = $this->app->make(\Sopheak\Core\Authorization\PermissionRegistrar::class);

        // Pre-warm cache
        $this->assertCount(0, $user->getAllPermissions());

        $versionBefore = $registrar->getCacheVersion();

        // Create a new permission and grant it to the user
        Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);
        $user->givePermissionTo('view:invoice');

        // Version should have been incremented (create + givePermissionTo = 2 increments)
        $versionAfter = $registrar->getCacheVersion();
        $this->assertEquals($versionBefore + 2, $versionAfter);

        // getAllPermissions should reflect the granted permission (not stale cache)
        $refreshed = $user->getAllPermissions();
        $this->assertCount(1, $refreshed);
        $this->assertEquals('view:invoice', $refreshed->first());
    }

    /** @test */
    public function it_invalidates_cache_when_role_is_created(): void
    {
        $user = $this->createUserWithTrait();
        $registrar = $this->app->make(\Sopheak\Core\Authorization\PermissionRegistrar::class);

        $versionBefore = $registrar->getCacheVersion();

        // Create a new permission and assign it to the role
        $perm = Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);

        // Pre-warm cache
        $this->assertCount(0, $user->getAllPermissions());

        // Create a role and give it the permission
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        $role->givePermissionTo('view:invoice');
        $user->assignRole('admin');

        // getAllPermissions should now reflect the role-based permission
        $userPermissions = $user->getAllPermissions();
        $this->assertCount(1, $userPermissions);
        $this->assertEquals('view:invoice', $userPermissions->first());
    }

    /** @test */
    public function it_handles_permission_object_in_give(): void
    {
        $perm = Permission::query()->create(['name' => 'view:invoice', 'guard_name' => 'api']);
        $user = $this->createUserWithTrait();

        $user->givePermissionTo($perm);

        $this->assertTrue($user->hasPermissionTo('view:invoice'));
    }

    protected function createPermissionTables(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
            });
        }

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
                $table->string('name');
                $table->string('key')->nullable();
                $table->string('guard_name');
                $table->text('description')->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('is_master')->default(false);
                $table->boolean('is_default')->default(false);
                $table->unique('key', 'sp_roles_key_unique');
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

        if (!Schema::hasTable('sp_model_has_roles')) {
            Schema::create('sp_model_has_roles', function (Blueprint $table) {
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

    protected function createUserWithTrait(): TestUserWithRoles
    {
        return TestUserWithRoles::query()->create(['id' => 1]);
    }
}
