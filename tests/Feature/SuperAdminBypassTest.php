<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;

class SuperAdminBypassTest extends TestCase
{
    use RefreshDatabase;

    private Authenticatable $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();
        $this->createUsersTable();

        $this->app['config']->set('permissions.enabled', true);

        $this->user = (new class extends Authenticatable {
            protected $table = 'users';

            public $timestamps = false;

            protected $fillable = ['id', 'name', 'is_admin'];
        });
        $this->user->forceFill(['id' => 1, 'name' => 'Test', 'is_admin' => true]);
        $this->user->save();
    }

    /** @test */
    public function it_returns_forbidden_without_super_admin_callback(): void
    {
        $this->app['config']->set('permissions.super_admin_callback', null);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/sp_roles')
            ->assertStatus(403)
            ->assertJson(['success' => false, 'error_code' => 10008]);
    }

    /** @test */
    public function it_allows_super_admin_callback_to_bypass_permissions(): void
    {
        $this->app['config']->set('permissions.super_admin_callback', fn($user): true => true);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/sp_roles')
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /** @test */
    public function it_respects_super_admin_callback_returning_false(): void
    {
        $this->app['config']->set('permissions.super_admin_callback', fn($user): false => false);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/sp_roles')
            ->assertStatus(403)
            ->assertJson(['success' => false, 'error_code' => 10008]);
    }

    /** @test */
    public function it_passes_authenticated_user_to_super_admin_callback(): void
    {
        $calledWith = null;
        $this->app['config']->set('permissions.super_admin_callback', function ($user) use (&$calledWith): true {
            $calledWith = $user;
            return true;
        });

        $this->actingAs($this->user, 'api')
            ->getJson('/api/sp_roles')
            ->assertStatus(200);

        $this->assertNotNull($calledWith);
        $this->assertEquals(1, $calledWith->id);
    }

    private function createPermissionTables(): void
    {
        if (!Schema::hasTable('sp_permissions')) {
            Schema::create('sp_permissions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name')->unique();
                $table->string('group')->nullable();
                $table->string('guard_name');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sp_roles')) {
            Schema::create('sp_roles', function (Blueprint $table): void {
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
            Schema::create('sp_role_permissions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->timestamps();
                $table->unique(['role_id', 'permission_id']);
            });
        }

        if (!Schema::hasTable('sp_model_has_roles')) {
            Schema::create('sp_model_has_roles', function (Blueprint $table): void {
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
            Schema::create('sp_model_permissions', function (Blueprint $table): void {
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

    private function createUsersTable(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('is_admin')->default(false);
            });
        }
    }
}
