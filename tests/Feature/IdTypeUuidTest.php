<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Tests\TestCase;

class IdTypeUuidTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Must be set here, not in setUp(): Testbench runs this before
        // RefreshDatabase migrates, so it is what the schema is built from.
        $app['config']->set('record.id_type', 'uuid');
    }

    /** @test */
    public function a_role_receives_a_generated_uuid_key(): void
    {
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $this->assertIsString($role->getKey());
        $this->assertTrue(Str::isUuid($role->getKey()));
    }

    /** @test */
    public function a_permission_receives_a_generated_uuid_key(): void
    {
        $permission = Permission::query()->create([
            'name' => 'posts.edit',
            'guard_name' => 'api',
        ]);

        $this->assertTrue(Str::isUuid($permission->getKey()));
    }

    /** @test */
    public function models_report_non_incrementing_string_keys(): void
    {
        $role = new Role();

        $this->assertFalse($role->getIncrementing());
        $this->assertSame('string', $role->getKeyType());
    }

    /** @test */
    public function a_role_can_be_retrieved_by_its_uuid(): void
    {
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $found = Role::query()->find($role->getKey());

        $this->assertNotNull($found);
        $this->assertSame('admin', $found->name);
    }

    /** @test */
    public function role_permission_pivot_writes_succeed_with_uuid_keys(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'posts.edit', 'guard_name' => 'api']);

        $role->givePermissionTo('posts.edit');

        $this->assertTrue($role->hasPermissionTo('posts.edit'));
    }

    /** @test */
    public function surrogate_pivot_ids_stay_auto_incrementing_integers(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'posts.edit', 'guard_name' => 'api']);

        $role->givePermissionTo('posts.edit');

        $pivotId = DB::table('sp_role_permissions')->value('id');

        $this->assertSame(1, (int) $pivotId);
    }

    /** @test */
    public function audit_log_id_stays_an_auto_incrementing_integer(): void
    {
        DB::table('sp_audit_logs')->insert([
            'entity_type' => 'users',
            'entity_id' => '1',
            'event' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, (int) DB::table('sp_audit_logs')->value('id'));
    }

    /** @test */
    public function attachment_tables_still_use_uuid_keys(): void
    {
        $uuid = (string) Str::uuid();

        DB::table('sp_attachments')->insert([
            'id' => $uuid,
            'disk' => 'local',
            'path' => 'a/b.txt',
            'filename' => 'b.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'visibility' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($uuid, DB::table('sp_attachments')->value('id'));
    }
}
