<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Authorization\Traits\HasRoles;
use Sopheak\Core\Tests\TestCase;

class UuidKeyedUser extends Model
{
    use HasRoles;

    protected $table = 'uuid_users';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}

class IntegerKeyedUser extends Model
{
    use HasRoles;

    protected $table = 'int_users';

    protected $guarded = [];

    public $timestamps = false;
}

class ClientModelReferenceColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('uuid_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
        });

        Schema::create('int_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
    }

    /** @test */
    public function model_has_roles_model_id_is_a_string_column(): void
    {
        $this->assertColumnType('sp_model_has_roles', 'model_id', 'varchar');
    }

    /** @test */
    public function model_permissions_model_id_is_a_string_column(): void
    {
        $this->assertColumnType('sp_model_permissions', 'model_id', 'varchar');
    }

    /** @test */
    public function governed_foreign_key_columns_remain_integers_under_the_default_setting(): void
    {
        // Guards against over-correcting MigrationIdHelper::foreign() calls
        // to strings: every FK that references sp_roles/sp_permissions must
        // track their integer PK under the default setting, not just one
        // column on one table.
        $this->assertColumnType('sp_role_permissions', 'role_id', 'integer');
        $this->assertColumnType('sp_role_permissions', 'permission_id', 'integer');
        $this->assertColumnType('sp_model_has_roles', 'role_id', 'integer');
        $this->assertColumnType('sp_model_permissions', 'permission_id', 'integer');
    }

    /** @test */
    public function a_uuid_keyed_user_can_be_assigned_a_role(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $user = UuidKeyedUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Uuid User',
        ]);

        $user->assignRole('admin');

        $this->assertTrue($user->hasRole('admin'));
    }

    /** @test */
    public function a_uuid_keyed_user_resolves_permissions_through_its_role(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        $role->givePermissionTo($this->createPermission('posts.edit'));

        $user = UuidKeyedUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Uuid User',
        ]);
        $user->assignRole('editor');

        $this->assertTrue($user->hasPermissionTo('posts.edit'));
    }

    /** @test */
    public function a_uuid_keyed_user_can_hold_a_direct_permission(): void
    {
        $this->createPermission('posts.publish');

        $user = UuidKeyedUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Uuid User',
        ]);
        $user->givePermissionTo('posts.publish');

        $this->assertTrue($user->hasPermissionTo('posts.publish'));
    }

    /** @test */
    public function two_uuid_keyed_users_do_not_share_roles(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $first = UuidKeyedUser::query()->create(['id' => (string) Str::uuid()]);
        $second = UuidKeyedUser::query()->create(['id' => (string) Str::uuid()]);

        $first->assignRole('admin');

        $this->assertTrue($first->hasRole('admin'));
        $this->assertFalse($second->hasRole('admin'));
    }

    /** @test */
    public function an_integer_keyed_user_still_works(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $user = IntegerKeyedUser::query()->create(['name' => 'Int User']);

        $user->assignRole('admin');

        $this->assertTrue($user->hasRole('admin'));
    }

    /** @test */
    public function integer_and_uuid_keyed_users_do_not_collide(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        Role::query()->create(['name' => 'viewer', 'guard_name' => 'api']);

        $intUser = IntegerKeyedUser::query()->create(['name' => 'Int User']);
        $uuidUser = UuidKeyedUser::query()->create(['id' => (string) Str::uuid()]);

        $intUser->assignRole('admin');
        $uuidUser->assignRole('viewer');

        $this->assertTrue($intUser->hasRole('admin'));
        $this->assertFalse($intUser->hasRole('viewer'));
        $this->assertTrue($uuidUser->hasRole('viewer'));
        $this->assertFalse($uuidUser->hasRole('admin'));
    }

    private function createPermission(string $name): string
    {
        \Sopheak\Core\Authorization\Models\Permission::query()->create([
            'name' => $name,
            'guard_name' => 'api',
        ]);

        return $name;
    }

    private function assertColumnType(string $table, string $column, string $expected): void
    {
        $columns = collect(Schema::getColumns($table))->keyBy('name');

        $this->assertTrue(
            $columns->has($column),
            "{$table} should have a {$column} column"
        );

        $this->assertSame(
            $expected,
            $columns[$column]['type'],
            "{$table}.{$column} should be {$expected}"
        );
    }
}
