<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Throwable;
use Sopheak\Core\Exceptions\NestedWriteRefusedException;
use Illuminate\Support\Facades\Event;
use Sopheak\Core\Events\RecordUpdated;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Jobs\ProcessBulkOperationJob;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordMetaHasManyThroughType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\NestedWriteAuthorizer;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class NestedChildWriteTrigger
{
    public static function addItem(mixed ...$params): void
    {
        $params[0]->merge(['items' => [['name' => 'FROM-TRIGGER']]]);
    }
}

class NestedChildWriteSystemTrigger
{
    /** An app hook writing nested children under its own (system) authority. */
    public static function writeChild(mixed ...$params): void
    {
        RecordService::executeCreate('invoices', ['title' => 'SYS', 'items' => [['name' => 'SYS-ITEM']]]);
    }
}

/**
 * A nested write authorised only the parent. The child table's own
 * permissions and can* flags were never checked, so a user allowed to update
 * an invoice could create, edit and delete invoice items, even on a table
 * configured canCreate/canDelete false.
 *
 * @internal
 */
class NestedChildWriteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> abilities the current user holds */
    private array $granted = [];

    /** When set, only this user id holds $granted. */
    private ?int $grantedTo = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('record.mcp.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The test guard's provider reads this table, so the async job can
        // restore a user exactly as a real worker would.
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
        });
        DB::table('users')->insert([['id' => 5, 'name' => 'five'], ['id' => 7, 'name' => 'seven']]);

        Schema::create('invoices', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('tags', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('invoice_tag', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('tag_id');
        });
        Schema::create('modules', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('meta', function (Blueprint $t): void {
            $t->id();
            $t->string('owner');
            $t->unsignedBigInteger('owner_id');
            $t->unsignedBigInteger('target_id');
            $t->timestamps();
        });

        DB::table('invoices')->insert(['id' => 1, 'title' => 'INV-1']);
        DB::table('invoice_items')->insert(['id' => 1, 'invoice_id' => 1, 'name' => 'OLD']);
        DB::table('tags')->insert(['id' => 1, 'name' => 'EXISTING']);

        $this->configure();

        Gate::before(fn($user, string $ability): ?bool => in_array($ability, $this->granted, true)
            && (null === $this->grantedTo || (int) $user->id === $this->grantedTo) ? true : null);
        $this->actingAs(new GenericUser(['id' => 5, 'name' => 'u']), 'api');
    }

    private function configure(bool $itemsCanCreate = true, bool $itemsCanDelete = true, bool $itemsPublic = false, mixed $beforeUpdate = null, mixed $afterUpdate = null): void
    {
        $tables = [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoice',
                hasTenantId: false,
                softDeletes: false,
                relationships: [
                    'items' => new RecordHasManyType(table: 'invoice_items', foreignKey: 'invoice_id', type: RecordRelationshipsEnum::HAS_MANY, localKey: 'id'),
                    'tags' => new RecordMetaBelongsToManyType(related: 'tags', table: 'invoice_tag', foreignPivotKey: 'invoice_id', relatedPivotKey: 'tag_id'),
                    'modules' => new RecordMetaHasManyThroughType(table: 'modules', through: 'meta', firstKey: 'owner_id', secondLocalKey: 'target_id', ownerColumn: 'owner', owner: 'invoice'),
                ],
                beforeUpdate: $beforeUpdate,
                afterUpdate: $afterUpdate,
            ),
            'invoice_items' => new RecordTableType(
                table: 'invoice_items',
                pmsName: 'invoice_item',
                hasTenantId: false,
                softDeletes: false,
                canCreate: $itemsCanCreate,
                canDelete: $itemsCanDelete,
                public: $itemsPublic ? new RecordTablePublic(read: true, write: true) : new RecordTablePublic(),
            ),
            'tags' => new RecordTableType(table: 'tags', pmsName: 'tag', hasTenantId: false, softDeletes: false),
            'modules' => new RecordTableType(table: 'modules', pmsName: 'module', hasTenantId: false, softDeletes: false),
        ];
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    /**
     * @param array<array<string, array<int, array<string, mixed>>>, mixed> $nested
     */
    private function updateInvoice(array $nested): TestResponse
    {
        return $this->putJson('/api/invoices/1', ['title' => 'INV-1-EDITED'] + $nested);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame('INV-1', DB::table('invoices')->where('id', 1)->value('title'), 'the parent write must roll back too');
        $this->assertSame(['OLD'], DB::table('invoice_items')->pluck('name')->all());
    }

    /** @test */
    public function a_nested_create_update_or_delete_without_the_child_permission_is_forbidden(): void
    {
        $this->granted = ['update:invoice'];

        $this->updateInvoice(['items' => [['name' => 'NEW']]])->assertStatus(403);
        $this->updateInvoice(['items' => [['id' => 1, 'name' => 'EDIT']]])->assertStatus(403);
        $this->updateInvoice(['items' => [['id' => 1, '_delete' => true]]])->assertStatus(403);

        $this->assertNothingWritten();
    }

    /** @test */
    public function a_nested_write_with_the_child_permission_succeeds(): void
    {
        $this->granted = ['update:invoice', 'create:invoice_item', 'update:invoice_item', 'delete:invoice_item'];

        $this->updateInvoice(['items' => [['id' => 1, 'name' => 'EDIT'], ['name' => 'NEW']]])->assertStatus(200);
        $this->assertEqualsCanonicalizing(['EDIT', 'NEW'], DB::table('invoice_items')->pluck('name')->all());

        $this->updateInvoice(['items' => [['id' => 1, '_delete' => true]]])->assertStatus(200);
        $this->assertSame(['NEW'], DB::table('invoice_items')->pluck('name')->all());
    }

    /** @test */
    public function a_disabled_can_flag_on_the_child_table_is_a_422(): void
    {
        $this->granted = ['update:invoice', 'create:invoice_item', 'delete:invoice_item'];
        $this->configure(itemsCanCreate: false, itemsCanDelete: false);

        $this->updateInvoice(['items' => [['name' => 'NEW']]])
            ->assertStatus(422)
            ->assertJsonPath('message', "Cannot create item in relationship 'items' for table 'invoices': canCreate is disabled on table 'invoice_items'.");
        $this->updateInvoice(['items' => [['id' => 1, '_delete' => true]]])->assertStatus(422);

        $this->assertNothingWritten();
    }

    /** @test */
    public function a_super_admin_passes(): void
    {
        $this->granted = ['update:invoice'];
        Config::set('permissions.super_admin_callback', fn($user): bool => 5 === (int) $user->id);

        $this->updateInvoice(['items' => [['name' => 'NEW']]])->assertStatus(200);
        $this->assertDatabaseHas('invoice_items', ['name' => 'NEW']);
    }

    /** @test */
    public function a_public_child_table_needs_no_child_permission(): void
    {
        $this->granted = ['update:invoice'];
        $this->configure(itemsPublic: true);

        $this->updateInvoice(['items' => [['name' => 'NEW']]])->assertStatus(200);
    }

    /** @test */
    public function attaching_an_existing_related_row_needs_only_the_parent_permission(): void
    {
        $this->granted = ['update:invoice'];

        $this->updateInvoice(['tags' => [['id' => 1]]])->assertStatus(200);
        $this->assertDatabaseHas('invoice_tag', ['invoice_id' => 1, 'tag_id' => 1]);
    }

    /** @test */
    public function creating_a_new_related_row_through_a_many_to_many_needs_the_related_create_permission(): void
    {
        $this->granted = ['update:invoice'];

        $this->updateInvoice(['tags' => [['name' => 'NEW-TAG']]])->assertStatus(403);
        $this->assertDatabaseMissing('tags', ['name' => 'NEW-TAG']);

        $this->granted = ['update:invoice', 'create:tag'];
        $this->updateInvoice(['tags' => [['name' => 'NEW-TAG']]])->assertStatus(200);
        $this->assertDatabaseHas('tags', ['name' => 'NEW-TAG']);
    }

    /** @test */
    public function creating_a_has_many_through_target_needs_the_target_create_permission(): void
    {
        $this->granted = ['update:invoice'];

        $this->updateInvoice(['modules' => [['name' => 'NEW-MODULE']]])->assertStatus(403);
        $this->assertDatabaseMissing('modules', ['name' => 'NEW-MODULE']);
    }

    /** @test */
    public function a_nested_create_on_a_new_parent_is_checked_too(): void
    {
        $this->granted = ['create:invoice'];

        $this->postJson('/api/invoices', ['title' => 'INV-2', 'items' => [['name' => 'NEW']]])->assertStatus(403);
        $this->assertDatabaseMissing('invoices', ['title' => 'INV-2']);
    }

    /** @test */
    public function bulk_endpoints_are_checked_per_item(): void
    {
        $this->granted = ['create:invoice', 'update:invoice', 'delete:invoice'];

        $this->postJson('/api/invoices/bulk/create', [['title' => 'INV-2', 'items' => [['name' => 'NEW']]]])->assertStatus(403);
        $this->postJson('/api/invoices/bulk/update', [['id' => 1, 'title' => 'X', 'items' => [['name' => 'NEW']]]])->assertStatus(403);
        $this->postJson('/api/invoices/bulk', [['id' => 1, 'title' => 'X', 'items' => [['name' => 'NEW']]]])->assertStatus(403);

        $this->assertNothingWritten();
        $this->assertDatabaseMissing('invoices', ['title' => 'INV-2']);
    }

    private function runBulkJob(?int $userId): ?Throwable
    {
        $context = ['guard' => 'api', 'headers' => [], 'server' => []] + (null === $userId ? [] : ['user_id' => $userId]);
        $job = new ProcessBulkOperationJob('update', 'invoices', [['id' => 1, 'title' => 'X', 'items' => [['name' => 'NEW']]]], null, $context);

        try {
            app()->call($job->handle(...));
        } catch (Throwable $throwable) {
            return $throwable;
        }

        return null;
    }

    /** @test */
    public function the_async_bulk_job_checks_children_as_the_restored_user(): void
    {
        // User 5 (the request's user) holds the child permission; the job runs
        // for user 7, who does not.
        $this->granted = ['update:invoice', 'create:invoice_item'];
        $this->grantedTo = 5;

        $refused = $this->runBulkJob(7);

        $this->assertInstanceOf(NestedWriteRefusedException::class, $refused);
        $this->assertSame('forbidden', $refused->decision);
        $this->assertNothingWritten();
    }

    /** @test */
    public function the_async_bulk_job_writes_children_for_a_restored_user_who_may(): void
    {
        $this->granted = ['update:invoice', 'create:invoice_item'];
        $this->grantedTo = 7;

        $this->assertNull($this->runBulkJob(7));
        $this->assertDatabaseHas('invoice_items', ['name' => 'NEW']);
    }

    /** @test */
    public function the_async_bulk_job_never_authorises_as_a_stale_guard_user(): void
    {
        // A daemon worker keeps the previous job's user on the guard. A job
        // whose own user cannot be restored (deleted) must not inherit it.
        $this->granted = ['update:invoice', 'create:invoice_item'];
        $this->grantedTo = 5;

        $refused = $this->runBulkJob(999);

        $this->assertInstanceOf(NestedWriteRefusedException::class, $refused);
        $this->assertNothingWritten();
    }

    /** @test */
    public function an_after_trigger_writing_nested_children_as_the_system_is_trusted(): void
    {
        // App hooks are trusted code, like any internal RecordService call:
        // the user only needs the permission for their own request.
        $this->granted = ['update:invoice'];
        $this->configure(afterUpdate: new RecordTableTriggerType(class: NestedChildWriteSystemTrigger::class, functionName: 'writeChild'));

        $this->updateInvoice([])->assertStatus(200);

        $this->assertSame('INV-1-EDITED', DB::table('invoices')->where('id', 1)->value('title'));
        $this->assertDatabaseHas('invoice_items', ['name' => 'SYS-ITEM']);
    }

    /** @test */
    public function a_record_event_listener_writing_nested_children_is_trusted_on_the_data_mcp(): void
    {
        $this->granted = ['update:invoice'];
        Event::listen(RecordUpdated::class, static function (): void {
            RecordService::executeCreate('invoices', ['title' => 'SYS', 'items' => [['name' => 'SYS-ITEM']]]);
        });

        $response = $this->mcpUpdateInvoice(['title' => 'INV-1-EDITED']);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertDatabaseHas('invoice_items', ['name' => 'SYS-ITEM']);
    }

    /** @test */
    public function a_trigger_that_adds_nested_data_is_still_checked(): void
    {
        $this->granted = ['update:invoice'];
        $this->configure(beforeUpdate: new RecordTableTriggerType(class: NestedChildWriteTrigger::class, functionName: 'addItem'));

        $this->updateInvoice([])->assertStatus(403);
        $this->assertDatabaseMissing('invoice_items', ['name' => 'FROM-TRIGGER']);
    }

    /** @test */
    public function trusted_internal_calls_outside_a_scope_are_unchanged(): void
    {
        // App code and commands call RecordService directly; they never
        // authorise the parent either, so children are not authorised.
        $this->app['auth']->forgetGuards();

        RecordService::executeUpdate('invoices', 1, ['title' => 'INTERNAL', 'items' => [['name' => 'INTERNAL-ITEM']]]);

        $this->assertDatabaseHas('invoice_items', ['name' => 'INTERNAL-ITEM']);
    }

    /** @test */
    public function the_scope_is_closed_after_an_exception(): void
    {
        try {
            NestedWriteAuthorizer::enforce(static function (): never {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertFalse(NestedWriteAuthorizer::isEnforcing());
    }

    /**
     * @param array<string, string>|array<string, array<int, array<string, string>>> $payload
     */
    private function mcpUpdateInvoice(array $payload): array
    {
        return (array) $this->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'update_invoices', 'arguments' => ['id' => 1, 'payload' => $payload]],
        ])->json();
    }

    /** @test */
    public function the_data_mcp_refuses_a_nested_child_write_with_the_forbidden_code_and_writes_nothing(): void
    {
        $this->granted = ['update:invoice'];

        $response = $this->mcpUpdateInvoice(['title' => 'INV-1-EDITED', 'items' => [['name' => 'NEW']]]);

        $this->assertSame(-32002, $response['error']['code'] ?? null);
        $this->assertSame('Forbidden', $response['error']['message'] ?? null);
        $this->assertNothingWritten();
    }

    /** @test */
    public function the_data_mcp_allows_a_nested_child_write_with_the_child_permission(): void
    {
        $this->granted = ['update:invoice', 'create:invoice_item'];

        $response = $this->mcpUpdateInvoice(['title' => 'INV-1-EDITED', 'items' => [['name' => 'NEW']]]);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertDatabaseHas('invoice_items', ['name' => 'NEW']);
    }
}
