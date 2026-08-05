<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Tests\TestCase;

class IdTypeIntegerDefaultTest extends TestCase
{
    use RefreshDatabase;

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
}
