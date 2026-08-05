<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Tests\TestCase;

class IdTypeIntegerDefaultTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // The base TestCase sets audit.enabled = false, which gates the real
        // audit migration off and falls back to a fixture table of its own
        // (see TestCase::setUp()). Enable it here so the default-config
        // assertion below exercises the real migration, not the fixture.
        $app['config']->set('audit.enabled', true);
    }

    /** @test */
    public function governed_tables_use_integer_keys_by_default(): void
    {
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $this->assertIsInt($role->getKey());
        $this->assertSame(1, $role->getKey());
    }

    /** @test */
    public function role_model_reports_incrementing_integer_keys_by_default(): void
    {
        $role = new Role();

        $this->assertTrue($role->getIncrementing());
        $this->assertSame('int', $role->getKeyType());
    }

    /** @test */
    public function surrogate_ids_are_integers_by_default(): void
    {
        // Create the role first: sp_model_has_roles.role_id has a foreign key
        // constraint, and Laravel enables SQLite FK enforcement by default.
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        DB::table('sp_model_has_roles')->insert([
            'model_type' => 'App\\Models\\User',
            'model_id' => '1',
            'role_id' => $role->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, (int) DB::table('sp_model_has_roles')->value('id'));
    }

    /** @test */
    public function role_permission_pivot_id_stays_auto_incrementing_integer_by_default(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'posts.edit', 'guard_name' => 'api']);

        $role->givePermissionTo('posts.edit');

        $pivotId = DB::table('sp_role_permissions')->value('id');

        $this->assertSame(1, (int) $pivotId);
    }

    /** @test */
    public function governed_columns_are_integers_by_default(): void
    {
        $columns = collect(Schema::getColumns('sp_roles'))->keyBy('name');

        $this->assertSame('integer', $columns['id']['type']);
    }

    /** @test */
    public function attachment_and_webhook_tables_use_integer_keys_by_default(): void
    {
        // Attachments/webhooks used to always use uuid keys regardless of
        // record.id_type. They now follow it like every other governed
        // table, which is an accepted breaking change for the default: see
        // docs/superpowers/specs/2026-08-05-expand-id-type-governance-design.md.
        //
        // Schema assertion first: SQLite's advisory type affinity means an
        // insert/select round-trip alone cannot tell a genuine integer
        // column from a string column that merely tolerates a numeric value.
        $columns = collect(Schema::getColumns('sp_attachments'))->keyBy('name');
        $this->assertSame('integer', $columns['id']['type']);

        DB::table('sp_attachments')->insert([
            'disk' => 'local',
            'path' => 'a/b.txt',
            'filename' => 'b.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'visibility' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, (int) DB::table('sp_attachments')->value('id'));
    }

    /** @test */
    public function audit_log_id_stays_an_auto_incrementing_integer_by_default(): void
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
}
