<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Authorization\Traits\HasRoles;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Tests\TestCase;

class TestRoleAssignee extends Model
{
    use HasRoles;

    protected $table = 'test_role_assignees';

    protected $guarded = [];

    public $timestamps = false;
}

class IdTypeUuidTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Must be set here, not in setUp(): Testbench runs this before
        // RefreshDatabase migrates, so it is what the schema is built from.
        $app['config']->set('record.id_type', 'uuid');

        // The base TestCase sets audit.enabled = false, which gates the real
        // audit migration off. When that happens, TestCase::setUp() builds a
        // fallback sp_audit_logs table of its own so unrelated tests don't
        // blow up on a missing table. That fallback is not the migration
        // under test here, so it must be enabled to actually exercise
        // Task 5's territory (the migration is intentionally left alone by
        // this task, but the test needs to observe *it*, not the fixture).
        $app['config']->set('audit.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_role_assignees', function (Blueprint $table): void {
            $table->id();
        });
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
    public function role_permission_pivot_id_is_a_generated_uuid(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'posts.edit', 'guard_name' => 'api']);

        $role->givePermissionTo('posts.edit');

        $pivotId = DB::table('sp_role_permissions')->value('id');

        $this->assertTrue(Str::isUuid((string) $pivotId));
    }

    /** @test */
    public function model_has_role_pivot_writes_succeed_with_uuid_keys(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        $assignee = TestRoleAssignee::query()->create();

        $assignee->assignRole('editor');

        $this->assertTrue($assignee->hasRole('editor'));
        $pivotId = DB::table('sp_model_has_roles')->value('id');
        $this->assertTrue(Str::isUuid((string) $pivotId));
    }

    /** @test */
    public function model_permission_pivot_writes_succeed_with_uuid_keys(): void
    {
        Permission::query()->create(['name' => 'posts.edit', 'guard_name' => 'api']);
        $assignee = TestRoleAssignee::query()->create();

        $assignee->givePermissionTo('posts.edit');

        $this->assertTrue($assignee->hasPermissionTo('posts.edit'));
        $pivotId = DB::table('sp_model_permissions')->value('id');
        $this->assertTrue(Str::isUuid((string) $pivotId));
    }

    /** @test */
    public function audit_log_id_is_a_generated_uuid(): void
    {
        // audit.enabled = true (set in getEnvironmentSetUp) is what
        // guarantees the real audit migration ran instead of the
        // TestCase::setUp() fallback fixture — not any column shape.
        $columns = collect(Schema::getColumns('sp_audit_logs'))->keyBy('name');
        $this->assertSame('varchar', $columns['entity_id']['type']);
        $this->assertSame('varchar', $columns['id']['type']);

        AuditLogService::handleAuditDataEntry(
            AuditLogEventEnum::CREATED,
            'users',
            'users',
            ['event' => AuditLogEventEnum::CREATED->value, 'entity_id' => '1', 'new_data' => ['id' => '1']],
        );

        $id = DB::table('sp_audit_logs')->value('id');
        $this->assertTrue(Str::isUuid((string) $id));
    }

    /** @test */
    public function attachment_tables_still_use_uuid_keys(): void
    {
        // Schema assertion first: SQLite's advisory type affinity means an
        // insert/select round-trip alone cannot tell a genuine uuid column
        // from an integer column that merely tolerates a uuid string.
        $columns = collect(Schema::getColumns('sp_attachments'))->keyBy('name');
        $this->assertSame('varchar', $columns['id']['type']);

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
