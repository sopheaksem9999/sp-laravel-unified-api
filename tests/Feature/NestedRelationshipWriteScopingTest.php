<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class NestedWriteScopingUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * A nested write scoped child rows only to the parent: the parent's tenant (a
 * non-tenant parent passed none) and never the child table's own tenant or
 * own-records scope. Any child row hanging off a parent the caller could write
 * — whatever tenant or owner it belonged to — could be edited, deleted, or
 * re-created under another tenant, and any related id could be attached.
 *
 * @internal
 */
class NestedRelationshipWriteScopingTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 42;

    private const OTHER = 99;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.enable_tenant_id', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
        });
        DB::table('users')->insert([['id' => self::OWNER]]);

        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('body');
            $t->timestamps();
        });
        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('note_id')->nullable();
            $t->string('tenant_id');
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        Schema::create('note_widget', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('note_id');
            $t->unsignedBigInteger('widget_id');
        });

        $now = now();
        DB::table('notes')->insert(['id' => 1, 'body' => 'N1', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'T1-MINE', 'note_id' => 1, 'tenant_id' => '1', 'created_by_id' => self::OWNER, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'T2-SECRET', 'note_id' => 1, 'tenant_id' => '2', 'created_by_id' => self::OWNER, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'T1-THEIRS', 'note_id' => 1, 'tenant_id' => '1', 'created_by_id' => self::OTHER, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $base = [
            'id' => ['type' => 'integer', 'nullable' => false],
            'created_at' => ['type' => 'datetime', 'nullable' => true],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ];

        Config::set('record.tables', [
            'notes' => new RecordTableType(
                table: 'notes',
                pmsName: 'note',
                hasTenantId: false,
                public: new RecordTablePublic(read: true, write: true),
                columns: $base + ['body' => ['type' => 'string', 'nullable' => false]],
                relationships: [
                    'widgets' => new RecordHasManyType(table: 'widgets', foreignKey: 'note_id', type: RecordRelationshipsEnum::HAS_MANY, localKey: 'id'),
                    'linked' => new RecordMetaBelongsToManyType(related: 'widgets', table: 'note_widget', foreignPivotKey: 'note_id', relatedPivotKey: 'widget_id'),
                ],
            ),
            'widgets' => new RecordTableType(
                table: 'widgets',
                pmsName: 'widget',
                hasTenantId: true,
                public: new RecordTablePublic(read: true, write: true),
                columns: $base + [
                    'name' => ['type' => 'string', 'nullable' => false],
                    'note_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'tenant_id' => ['type' => 'string', 'nullable' => false],
                    'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                ],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
    }

    /**
     * @param array<array<string, array<int, array<string, mixed>>>, mixed> $payload
     */
    private function updateNote(array $payload): TestResponse
    {
        return $this->putJson('/api/notes/1', ['body' => 'N1'] + $payload, ['X-Tenant-ID' => '1']);
    }

    private function restrictToOwnWidgets(): void
    {
        $this->actingAs(NestedWriteScopingUser::query()->findOrFail(self::OWNER), 'api');
        Gate::before(fn($user, string $ability): bool => 'viewOwn:note' !== $ability);
    }

    /** @test */
    public function a_nested_update_cannot_edit_another_tenants_child(): void
    {
        $this->updateNote(['widgets' => [['id' => 2, 'name' => 'HIJACKED']]]);

        $this->assertSame('T2-SECRET', DB::table('widgets')->where('id', 2)->value('name'));
    }

    /** @test */
    public function a_nested_delete_cannot_remove_another_tenants_child(): void
    {
        $this->updateNote(['widgets' => [['id' => 2, '_delete' => true]]]);

        $this->assertDatabaseHas('widgets', ['id' => 2]);
    }

    /** @test */
    public function a_nested_create_is_stamped_with_the_callers_tenant_not_the_payloads(): void
    {
        $this->updateNote(['widgets' => [['name' => 'NEW', 'tenant_id' => '2']]]);

        $this->assertSame('1', (string) DB::table('widgets')->where('name', 'NEW')->value('tenant_id'));
    }

    /** @test */
    public function a_nested_update_cannot_edit_a_child_the_caller_does_not_own(): void
    {
        $this->restrictToOwnWidgets();

        $this->updateNote(['widgets' => [['id' => 3, 'name' => 'HIJACKED']]]);
        $this->updateNote(['widgets' => [['id' => 3, '_delete' => true]]]);

        $this->assertSame('T1-THEIRS', DB::table('widgets')->where('id', 3)->value('name'));
    }

    /** @test */
    public function attaching_another_tenants_record_is_rejected(): void
    {
        $this->updateNote(['linked' => [['id' => 2]]])->assertStatus(422);

        $this->assertDatabaseMissing('note_widget', ['note_id' => 1, 'widget_id' => 2]);
    }

    /** @test */
    public function attaching_a_record_the_caller_does_not_own_is_rejected(): void
    {
        $this->restrictToOwnWidgets();

        $this->updateNote(['linked' => [['id' => 3]]])->assertStatus(422);

        $this->assertDatabaseMissing('note_widget', ['note_id' => 1, 'widget_id' => 3]);
    }

    /** @test */
    public function nested_writes_on_visible_children_still_work(): void
    {
        $this->restrictToOwnWidgets();

        $this->updateNote([
            'widgets' => [['id' => 1, 'name' => 'T1-MINE-EDITED']],
            'linked' => [['id' => 1]],
        ])->assertStatus(200);

        $this->assertSame('T1-MINE-EDITED', DB::table('widgets')->where('id', 1)->value('name'));
        $this->assertDatabaseHas('note_widget', ['note_id' => 1, 'widget_id' => 1]);
    }

    /** @test */
    public function a_nested_write_to_a_tenant_scoped_child_without_a_tenant_is_refused(): void
    {
        // The child table's own endpoint refuses a write with no tenant; the
        // nested path must not be a way around that.
        $this->putJson('/api/notes/1', ['body' => 'N1', 'widgets' => [['id' => 2, 'name' => 'HIJACKED']]])->assertStatus(422);
        $this->putJson('/api/notes/1', ['body' => 'N1', 'widgets' => [['id' => 2, '_delete' => true]]])->assertStatus(422);
        $this->putJson('/api/notes/1', ['body' => 'N1', 'widgets' => [['name' => 'NO-TENANT', 'tenant_id' => '2']]])->assertStatus(422);
        $this->putJson('/api/notes/1', ['body' => 'N1', 'linked' => [['id' => 2]]])->assertStatus(422);
        $this->putJson('/api/notes/1', ['body' => 'N1', 'linked' => [['name' => 'NO-TENANT-LINKED', 'tenant_id' => '2']]])->assertStatus(422);

        $this->assertSame('T2-SECRET', DB::table('widgets')->where('id', 2)->value('name'));
        $this->assertDatabaseMissing('widgets', ['name' => 'NO-TENANT']);
        $this->assertDatabaseMissing('widgets', ['name' => 'NO-TENANT-LINKED']);
        $this->assertDatabaseMissing('note_widget', ['note_id' => 1, 'widget_id' => 2]);
    }
}
