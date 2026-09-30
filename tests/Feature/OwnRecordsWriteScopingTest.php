<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Sopheak\Core\Events\RecordUpdated;
use Sopheak\Core\Events\RecordDeleted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * viewOwn:{pmsName} was enforced for list reads only. A user who saw only
 * their own rows in a list could still read, update, delete, restore or
 * force-delete anyone's row by id.
 *
 * @internal
 */
class OwnRecordsWriteScopingTest extends TestCase
{
    use RefreshDatabase;

    protected const OWNER = 42;

    protected const OTHER = 99;

    protected const ADMIN = 1;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // MCP routes are registered at boot, so they must be enabled here for
        // Task 4's MCP tests; enabling them changes nothing for the HTTP tests.
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('record.mcp.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        DB::table('users')->insert([
            ['id' => self::ADMIN, 'name' => 'admin'],
            ['id' => self::OWNER, 'name' => 'owner'],
            ['id' => 43, 'name' => 'another viewOwn user'],
        ]);

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('orders', function (Blueprint $t): void {
            $t->id();
            $t->string('ref')->unique();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });

        Config::set('record.tables', [
            'widgets' => new RecordTableType(
                table: 'widgets',
                pmsName: 'widget',
                hasTenantId: false,
                softDeletes: true,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'deleted_at' => ['type' => 'datetime', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
            // Owner is the record's subject, not its audit author.
            'orders' => new RecordTableType(
                table: 'orders',
                pmsName: 'order',
                hasTenantId: false,
                canUpsert: true,
                ownerColumn: 'user_id',
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'ref' => ['type' => 'string', 'nullable' => false],
                    'user_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();

        // Everyone may do everything; only OWNER and user 43 are own-restricted.
        Gate::before(function ($user, string $ability): bool {
            if (str_starts_with($ability, 'viewOwn:')) {
                return in_array((int) $user->id, [self::OWNER, 43], true);
            }

            return true;
        });

        $this->seedRows();
    }

    protected function seedRows(): void
    {
        DB::table('widgets')->delete();
        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'MINE', 'created_by_id' => self::OWNER, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'THEIRS', 'created_by_id' => self::OTHER, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('orders')->delete();
        DB::table('orders')->insert([
            // Admin created it on the owner's behalf: owner is user_id, not created_by_id.
            ['id' => 1, 'ref' => 'ORD-MINE', 'user_id' => self::OWNER, 'created_by_id' => self::ADMIN, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'ref' => 'ORD-THEIRS', 'user_id' => self::OTHER, 'created_by_id' => self::OWNER, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function actAs(int $id): static
    {
        $user = new class extends Authenticatable {
            protected $table = 'users';

            public $timestamps = false;
        };
        $user->forceFill(['id' => $id, 'name' => 'u' . $id]);
        $user->exists = true;

        return $this->actingAs($user, 'api');
    }

    /** @test */
    public function reading_another_users_row_by_id_looks_like_a_missing_row(): void
    {
        $foreign = $this->actAs(self::OWNER)->getJson('/api/widgets/2');
        $missing = $this->actAs(self::OWNER)->getJson('/api/widgets/999');

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertStringNotContainsString('THEIRS', (string) $foreign->getContent());
    }

    /** @test */
    public function reading_your_own_row_still_works(): void
    {
        $this->actAs(self::OWNER)->getJson('/api/widgets/1')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'MINE');
    }

    /** @test */
    public function updating_another_users_row_is_refused_like_a_missing_row(): void
    {
        $foreign = $this->actAs(self::OWNER)->putJson('/api/widgets/2', ['name' => 'HIJACKED']);
        $missing = $this->actAs(self::OWNER)->putJson('/api/widgets/999', ['name' => 'HIJACKED']);

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame('THEIRS', DB::table('widgets')->where('id', 2)->value('name'));
    }

    /** @test */
    public function updating_your_own_row_still_works(): void
    {
        $this->actAs(self::OWNER)->putJson('/api/widgets/1', ['name' => 'RENAMED'])->assertStatus(200);

        $this->assertSame('RENAMED', DB::table('widgets')->where('id', 1)->value('name'));
    }

    /** @test */
    public function deleting_another_users_row_is_refused(): void
    {
        $foreign = $this->actAs(self::OWNER)->deleteJson('/api/widgets/2');
        $missing = $this->actAs(self::OWNER)->deleteJson('/api/widgets/999');

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertNull(DB::table('widgets')->where('id', 2)->value('deleted_at'));
    }

    /** @test */
    public function restoring_another_users_row_is_refused(): void
    {
        DB::table('widgets')->where('id', 2)->update(['deleted_at' => now()]);

        $this->actAs(self::OWNER)->postJson('/api/widgets/2/restore');

        $this->assertNotNull(DB::table('widgets')->where('id', 2)->value('deleted_at'));
    }

    /** @test */
    public function force_deleting_another_users_row_is_refused(): void
    {
        $this->actAs(self::OWNER)->deleteJson('/api/widgets/2/force');

        $this->assertSame(1, DB::table('widgets')->where('id', 2)->count());
    }

    /** @test */
    public function a_user_without_view_own_keeps_full_access(): void
    {
        $this->actAs(self::ADMIN)->getJson('/api/widgets/2')->assertStatus(200)->assertJsonPath('data.name', 'THEIRS');
        $this->actAs(self::ADMIN)->putJson('/api/widgets/2', ['name' => 'ADMIN-EDIT'])->assertStatus(200);

        $this->assertSame('ADMIN-EDIT', DB::table('widgets')->where('id', 2)->value('name'));
    }

    /** @test */
    public function writes_scope_on_the_owner_column_not_the_audit_author(): void
    {
        // Order 2: user_id = OTHER, but created_by_id = OWNER. Ownership is
        // user_id, so the OWNER must NOT be able to edit it.
        $this->actAs(self::OWNER)->putJson('/api/orders/2', ['ref' => 'ORD-HIJACKED']);
        $this->assertSame('ORD-THEIRS', DB::table('orders')->where('id', 2)->value('ref'));

        // Order 1: user_id = OWNER, created_by_id = ADMIN. The OWNER owns it.
        $this->actAs(self::OWNER)->putJson('/api/orders/1', ['ref' => 'ORD-MINE-EDITED'])->assertStatus(200);
        $this->assertSame('ORD-MINE-EDITED', DB::table('orders')->where('id', 1)->value('ref'));
    }

    /** @test */
    public function upsert_cannot_overwrite_another_users_row_through_a_match_key(): void
    {
        $this->actAs(self::OWNER)->postJson('/api/orders/upsert?match_on=ref', [
            'ref' => 'ORD-THEIRS',
            'user_id' => self::OWNER,
        ]);

        $this->assertSame(self::OTHER, (int) DB::table('orders')->where('ref', 'ORD-THEIRS')->value('user_id'));
    }

    /** @test */
    public function upsert_of_your_own_row_still_works(): void
    {
        $this->actAs(self::OWNER)->postJson('/api/orders/upsert?match_on=ref', [
            'ref' => 'ORD-MINE',
            'user_id' => self::OWNER,
        ])->assertStatus(200);

        $this->assertSame(1, DB::table('orders')->where('ref', 'ORD-MINE')->count());
    }

    /** @test */
    public function upsert_inserting_a_new_row_still_works(): void
    {
        $this->actAs(self::OWNER)->postJson('/api/orders/upsert?match_on=ref', [
            'ref' => 'ORD-NEW',
            'user_id' => self::OWNER,
        ])->assertStatus(200);

        $this->assertSame(1, DB::table('orders')->where('ref', 'ORD-NEW')->count());
    }

    /** @test */
    public function bulk_upsert_touching_any_foreign_row_changes_nothing(): void
    {
        $this->actAs(self::OWNER)->postJson('/api/orders/bulk/upsert?match_on=ref', [
            ['ref' => 'ORD-MINE', 'user_id' => self::OWNER],
            ['ref' => 'ORD-THEIRS', 'user_id' => self::OWNER],
        ]);

        $this->assertSame(self::OTHER, (int) DB::table('orders')->where('ref', 'ORD-THEIRS')->value('user_id'));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function mcp(int $userId, string $tool, array $arguments): string
    {
        $response = $this->actAs($userId)->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);

        return (string) ($response->json('result.content.0.text') ?? json_encode($response->json()));
    }

    /** @test */
    public function mcp_tools_cannot_read_update_or_delete_another_users_row(): void
    {
        $this->assertStringNotContainsString('THEIRS', $this->mcp(self::OWNER, 'read_widgets', ['id' => 2]));

        $this->mcp(self::OWNER, 'update_widgets', ['id' => 2, 'payload' => ['name' => 'HIJACKED']]);
        $this->mcp(self::OWNER, 'delete_widgets', ['id' => 2]);

        $this->assertSame('THEIRS', DB::table('widgets')->where('id', 2)->value('name'));
        $this->assertNull(DB::table('widgets')->where('id', 2)->value('deleted_at'));
    }

    /** @test */
    public function mcp_read_of_your_own_row_still_works(): void
    {
        $this->assertStringContainsString('MINE', $this->mcp(self::OWNER, 'read_widgets', ['id' => 1]));
    }

    /** @test */
    public function bulk_update_and_delete_cannot_reach_another_users_row(): void
    {
        $this->actAs(self::OWNER)->postJson('/api/widgets/bulk/update', [['id' => 2, 'name' => 'HIJACKED']]);
        $this->actAs(self::OWNER)->postJson('/api/widgets/bulk/delete', [['id' => 2]]);

        $this->assertSame('THEIRS', DB::table('widgets')->where('id', 2)->value('name'));
        $this->assertNull(DB::table('widgets')->where('id', 2)->value('deleted_at'));
    }

    /** @test */
    public function upsert_cannot_take_over_another_users_row_through_its_primary_key(): void
    {
        // match_on names `ref`, but the item also carries the foreign row's PK.
        // MySQL's ON DUPLICATE KEY UPDATE fires on ANY unique key — including the
        // PK — so a guard that probes only match_on let this overwrite row 2's
        // owner. SQLite/Postgres raise a unique violation instead (a 500 that
        // leaks that the id exists). Either way the guard must refuse first.
        $response = $this->actAs(self::OWNER)->postJson('/api/orders/upsert?match_on=ref', [
            'id' => 2,
            'ref' => 'ORD-FRESH',
            'user_id' => self::OWNER,
        ]);

        $response->assertStatus(403);
        $this->assertSame(self::OTHER, (int) DB::table('orders')->where('id', 2)->value('user_id'));
    }

    /** @test */
    public function upsert_cannot_take_over_another_users_row_through_a_unique_column_outside_match_on(): void
    {
        // match_on is the PK, but `ref` is also unique. On MySQL the duplicate
        // ref fires ON DUPLICATE KEY UPDATE against the foreign row.
        $response = $this->actAs(self::OWNER)->postJson('/api/orders/upsert?match_on=id', [
            'id' => 50,
            'ref' => 'ORD-THEIRS',
            'user_id' => self::OWNER,
        ]);

        $response->assertStatus(403);
        $this->assertSame(self::OTHER, (int) DB::table('orders')->where('ref', 'ORD-THEIRS')->value('user_id'));
    }

    /** @test */
    public function a_refused_mcp_write_emits_no_update_or_delete_event(): void
    {
        Event::fake([
            RecordUpdated::class,
            RecordDeleted::class,
        ]);

        $this->mcp(self::OWNER, 'update_widgets', ['id' => 2, 'payload' => ['name' => 'HIJACKED']]);
        $this->mcp(self::OWNER, 'delete_widgets', ['id' => 2]);

        // A false "deleted"/"updated" event would reach audit and webhooks for a
        // row the caller could not touch.
        Event::assertNotDispatched(RecordUpdated::class);
        Event::assertNotDispatched(RecordDeleted::class);
    }

    /** @test */
    public function a_permitted_mcp_write_still_emits_its_event(): void
    {
        Event::fake([
            RecordUpdated::class,
            RecordDeleted::class,
        ]);

        $this->mcp(self::OWNER, 'update_widgets', ['id' => 1, 'payload' => ['name' => 'RENAMED']]);
        $this->mcp(self::OWNER, 'delete_widgets', ['id' => 1]);

        Event::assertDispatched(RecordUpdated::class);
        Event::assertDispatched(RecordDeleted::class);
    }
}
