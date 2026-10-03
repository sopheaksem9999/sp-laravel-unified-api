<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Sopheak\Core\Jobs\AuditLogJob;
use Sopheak\Core\Events\RecordMutated;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolExecutor;
use Sopheak\Core\Mcp\ToolResult;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class McpHookSpy
{
    /** @var list<string> */
    public static array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public static array $contexts = [];

    public static int $audits = 0;

    public static bool $abort = false;

    public static bool $failAfterCreate = false;

    /** @var array<string, mixed> */
    public static array $captured = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$contexts = [];
        self::$audits = 0;
        self::$abort = false;
        self::$failAfterCreate = false;
        self::$captured = [];
    }

    /** @param array<string, mixed> $context */
    private static function seen(string $hook, array $context): void
    {
        self::$calls[] = $hook;
        self::$contexts[$hook] = $context;
    }

    public static function globalBeforeCreate(Request $request, string $table, array $context): void
    {
        self::seen('global.beforeCreate', $context);
    }

    public static function beforeCreate(Request $request, string $table, array $context): Request
    {
        self::seen('beforeCreate', $context);
        if (self::$abort) {
            throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Notes are closed today'], 403));
        }

        $request->merge(['title' => strtoupper((string) $request->input('title'))]);

        return $request;
    }

    public static function afterCreate(Request $request, string $table, array $context): void
    {
        self::seen('afterCreate', $context);
        if (self::$failAfterCreate) {
            throw new RuntimeException('webhook to https://hooks.internal.example/secret-token-123 failed');
        }
    }

    public static function globalAfterCreate(Request $request, string $table, array $context): void
    {
        self::seen('global.afterCreate', $context);
    }

    public static function beforeUpdate(Request $request, string $table, array $context): void
    {
        self::seen('beforeUpdate', $context);
    }

    public static function afterUpdate(Request $request, string $table, array $context): void
    {
        self::seen('afterUpdate', $context);
    }

    public static function beforeDelete(Request $request, string $table, array $context): void
    {
        self::seen('beforeDelete', $context);
    }

    public static function afterDelete(Request $request, string $table, array $context): void
    {
        self::seen('afterDelete', $context);
    }

    /** Team visibility, the way apps scope lists in a beforeRead hook. */
    public static function beforeRead(Request $request, string $table, array $context): Request
    {
        self::seen('beforeRead', $context);
        $request->merge(['team' => 'eq.red']);

        return $request;
    }

    public static function beforeReadAddingPets(Request $request, string $table, array $context): Request
    {
        $request->merge(['select' => '*,pets(*)']);

        return $request;
    }

    public static function afterRead(Request $request, string $table, array $context): void
    {
        self::seen('afterRead', $context);
    }

    public static function beforeUpdateRecordingQuery(Request $request, string $table, array $context): void
    {
        self::$captured['queryKeys'] = array_keys($request->query->all());
        self::$captured['uri'] = $request->getRequestUri();
    }

    public static function beforeCreateRecordingTenantHeader(Request $request, string $table, array $context): void
    {
        self::$captured['tenantHeader'] = $request->header('X-Tenant-ID');
    }

    public static function beforeDeleteAddingPets(Request $request, string $table, array $context): void
    {
        $request->query->set('select', '*,pets(*)');
    }

    public static function requireBody(Request $request): ValidatorContract
    {
        return Validator::make($request->all(), ['body' => 'required']);
    }

    public static function countAudit(mixed ...$args): bool
    {
        ++self::$audits;

        return false;
    }
}

/** Counts the audit jobs that actually run instead of writing them. */
class McpAuditJobSpy extends AuditLogJob
{
    public static int $handled = 0;

    public function handle(): void
    {
        ++self::$handled;
    }
}

/**
 * MCP data tools (and the AI SDK record tools, which share the executor)
 * called RecordService::execute* directly: no record hooks, no validators, no
 * after-hooks or webhooks, no RecordMutated broadcast. A beforeRead hook that
 * scopes a list to a team did not apply to an agent.
 *
 * @internal
 */
class McpRecordHooksTest extends TestCase
{
    use RefreshDatabase;

    /** `audit.filter` takes a [class, method] callable, as in AuditLogFilterTest. */
    private const AUDIT_COUNTER = [McpHookSpy::class, 'countAudit'];

    protected function setUp(): void
    {
        parent::setUp();
        McpHookSpy::reset();

        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->string('body')->nullable();
            $t->string('team')->nullable();
            $t->timestamps();
        });
        Schema::create('tags', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        DB::table('notes')->insert([
            ['id' => 1, 'title' => 'RED NOTE', 'team' => 'red'],
            ['id' => 2, 'title' => 'BLUE NOTE', 'team' => 'blue'],
        ]);

        Config::set('record.mcp.read_only', false);
        Config::set('record.global_triggers', [
            'beforeCreate' => new RecordTableTriggerType(McpHookSpy::class, 'globalBeforeCreate'),
            'afterCreate' => new RecordTableTriggerType(McpHookSpy::class, 'globalAfterCreate'),
        ]);
        $this->registerTables();
    }

    /**
     * @param array<string, mixed> $relationships
     * @param array<string, RecordTableType> $extraTables
     */
    /**
     * @param array<string, mixed> $relationships
     * @param array<string, RecordTableType> $extraTables
     * @param array<string, mixed> $overrides constructor arguments of `notes` to replace
     */
    private function registerTables(?RecordTableTriggerType $beforeRead = null, array $relationships = [], array $extraTables = [], array $overrides = []): void
    {
        $public = new RecordTablePublic(read: true, write: true);
        $hook = static fn(string $method): RecordTableTriggerType => new RecordTableTriggerType(McpHookSpy::class, $method);

        $notes = $overrides + [
            'table' => 'notes',
            'public' => $public,
            'relationships' => $relationships,
            'createValidator' => McpHookSpy::requireBody(...),
            'beforeRead' => $beforeRead ?? $hook('beforeRead'),
            'afterRead' => $hook('afterRead'),
            'beforeCreate' => $hook('beforeCreate'),
            'afterCreate' => $hook('afterCreate'),
            'beforeUpdate' => $hook('beforeUpdate'),
            'afterUpdate' => $hook('afterUpdate'),
            'beforeDelete' => $hook('beforeDelete'),
            'afterDelete' => $hook('afterDelete'),
        ];

        Config::set('record.tables', [
            'notes' => new RecordTableType(...$notes),
            'tags' => new RecordTableType(
                table: 'tags',
                public: $public,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false, 'key' => 'pri'],
                    'name' => ['type' => 'string', 'nullable' => false],
                ],
            ),
        ] + $extraTables);
        SchemaRegistryUtils::refresh();
    }

    /** @param array<string, mixed> $args */
    private function tool(string $name, array $args = []): ToolResult
    {
        return (new ToolExecutor())->call($name, $args);
    }

    public function test_create_runs_before_hooks_validators_and_after_hooks_in_http_order(): void
    {
        $result = $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        $this->assertFalse($result->isError, (string) $result->message);
        $this->assertSame('HELLO', DB::table('notes')->where('body', 'b')->value('title'), 'a before hook can change the payload');
        $this->assertSame(['global.beforeCreate', 'beforeCreate', 'afterCreate', 'global.afterCreate'], McpHookSpy::$calls);
        $this->assertNotNull(McpHookSpy::$contexts['afterCreate']['id'] ?? null);
    }

    public function test_a_failing_table_validator_refuses_the_write(): void
    {
        $result = $this->tool('create_notes', ['payload' => ['title' => 'no body']]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Validation failed', (string) $result->message);
        $this->assertStringContainsString('body', (string) $result->message);
        $this->assertSame(2, DB::table('notes')->count());
        $this->assertNotContains('afterCreate', McpHookSpy::$calls);
    }

    public function test_default_validation_applies_to_tool_writes(): void
    {
        Config::set('record.default_validation.enabled', true);

        $result = $this->tool('create_tags', ['payload' => ['name' => null]]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('name', (string) $result->message);
        $this->assertSame(0, DB::table('tags')->count());
    }

    public function test_update_and_delete_run_their_hooks(): void
    {
        $this->assertFalse($this->tool('update_notes', ['id' => 1, 'payload' => ['title' => 'changed']])->isError);
        $this->assertFalse($this->tool('delete_notes', ['id' => 1])->isError);

        $this->assertSame(['beforeUpdate', 'afterUpdate', 'beforeDelete', 'afterDelete'], McpHookSpy::$calls);
        $this->assertSame(1, McpHookSpy::$contexts['afterUpdate']['updated'], 'the affected count, as over HTTP');
        $this->assertSame(1, McpHookSpy::$contexts['afterDelete']['affected']);
        $this->assertSame('changed', McpHookSpy::$contexts['beforeDelete']['record']->title);
    }

    public function test_a_before_read_filter_narrows_lists_and_reads_and_after_read_runs(): void
    {
        $list = $this->tool('list_notes');
        $blue = $this->tool('read_notes', ['id' => 2]);

        $this->assertSame(['RED NOTE'], array_column($list->structuredContent['response']['data'], 'title'));
        $this->assertEmpty($blue->structuredContent['response']['data']);
        $this->assertSame(['beforeRead', 'afterRead', 'beforeRead'], McpHookSpy::$calls);
    }

    public function test_query_params_as_a_string_still_work(): void
    {
        $result = $this->tool('list_notes', ['queryParams' => 'select=id,title']);

        $this->assertSame(['RED NOTE'], array_column($result->structuredContent['response']['data'], 'title'));
    }

    public function test_tool_writes_broadcast_record_mutated(): void
    {
        Config::set('record.broadcast_events', true);
        Event::fake([RecordMutated::class]);

        $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        Event::assertDispatched(RecordMutated::class);
    }

    public function test_a_tool_write_is_audited_once(): void
    {
        Config::set('audit.enabled', true);
        Config::set('audit.queue_enabled', false);
        Config::set('audit.filter', self::AUDIT_COUNTER);

        $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        $this->assertSame(1, McpHookSpy::$audits);
    }

    public function test_a_hook_that_aborts_refuses_the_call(): void
    {
        McpHookSpy::$abort = true;

        $result = $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Notes are closed today', (string) $result->message);
        $this->assertSame(2, DB::table('notes')->count());
    }

    public function test_an_include_a_before_read_hook_adds_is_refused_without_a_tenant(): void
    {
        Schema::create('pets', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('note_id');
            $t->string('tenant_id')->nullable();
        });
        Config::set('record.enable_tenant_id', true);
        $this->registerTables(
            new RecordTableTriggerType(McpHookSpy::class, 'beforeReadAddingPets'),
            ['pets' => new RecordHasManyType(table: 'pets', foreignKey: 'note_id')],
            ['pets' => new RecordTableType(table: 'pets', hasTenantId: true, public: new RecordTablePublic(read: true, write: true))],
        );

        $this->expectException(ToolError::class);
        $this->expectExceptionCode(-32001);

        $this->tool('list_notes');
    }

    public function test_switching_record_hooks_off_restores_the_previous_behaviour(): void
    {
        Config::set('record.mcp.run_record_hooks', false);

        $result = $this->tool('create_notes', ['payload' => ['title' => 'no body']]);

        $this->assertFalse($result->isError);
        $this->assertSame([], McpHookSpy::$calls);
        $this->assertSame('no body', DB::table('notes')->where('id', '>', 2)->value('title'));
    }

    public function test_a_write_an_after_hook_rolls_back_queues_no_audit(): void
    {
        Config::set('audit.enabled', true);
        Config::set('audit.queue_enabled', true);
        Config::set('audit.audit_log_job', McpAuditJobSpy::class);
        Config::set('queue.default', 'sync');
        McpAuditJobSpy::$handled = 0;
        McpHookSpy::$failAfterCreate = true;

        $result = $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        $this->assertTrue($result->isError);
        $this->assertSame(2, DB::table('notes')->count(), 'the write is rolled back');
        $this->assertSame(0, McpAuditJobSpy::$handled, 'and so is its audit entry');
    }

    public function test_a_failing_hook_reaches_the_client_as_a_generic_message_and_the_log_in_full(): void
    {
        Exceptions::fake();
        McpHookSpy::$failAfterCreate = true;

        $result = $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        $this->assertTrue($result->isError);
        $this->assertStringNotContainsString('secret-token', (string) $result->message);
        $this->assertStringNotContainsString('McpHookSpy', (string) $result->message);
        $this->assertStringContainsString('record hook failed', (string) $result->message);
        Exceptions::assertReportedCount(1);
    }

    public function test_a_delete_re_checks_includes_its_before_hooks_add(): void
    {
        Schema::create('pets', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('note_id');
            $t->string('tenant_id')->nullable();
        });
        Config::set('record.enable_tenant_id', true);
        $this->registerTables(
            null,
            ['pets' => new RecordHasManyType(table: 'pets', foreignKey: 'note_id')],
            ['pets' => new RecordTableType(table: 'pets', hasTenantId: true, public: new RecordTablePublic(read: true, write: true))],
            ['beforeDelete' => new RecordTableTriggerType(McpHookSpy::class, 'beforeDeleteAddingPets')],
        );

        try {
            $this->tool('delete_notes', ['id' => 1]);
            $this->fail('a hook-added tenant-scoped include needs a tenant');
        } catch (ToolError $toolError) {
            $this->assertSame(-32001, $toolError->getCode());
        }

        $this->assertSame(2, DB::table('notes')->count(), 'nothing was deleted');
    }

    public function test_write_hooks_see_dotted_query_keys_as_sent(): void
    {
        $this->registerTables(null, [], [], ['beforeUpdate' => new RecordTableTriggerType(McpHookSpy::class, 'beforeUpdateRecordingQuery')]);

        $this->tool('update_notes', ['id' => 1, 'payload' => ['title' => 'x'], 'queryParams' => ['select' => 'id,title', 'pets.name' => 'eq.x']]);

        $this->assertContains('pets.name', McpHookSpy::$captured['queryKeys']);
        $this->assertStringContainsString('select=', McpHookSpy::$captured['uri'], 'the request URI carries the query, as over HTTP');
    }

    public function test_a_payload_that_cannot_be_encoded_is_refused_not_written_empty(): void
    {
        Schema::create('memos', function (Blueprint $t): void {
            $t->id();
            $t->string('body')->nullable();
            $t->timestamps();
        });
        $this->registerTables(null, [], ['memos' => new RecordTableType(table: 'memos', public: new RecordTablePublic(read: true, write: true))]);

        $result = $this->tool('create_memos', ['payload' => ['body' => "\xB1\x31"]]);

        $this->assertTrue($result->isError);
        $this->assertSame(0, DB::table('memos')->count());
    }

    public function test_hooks_see_the_resolved_tenant_in_the_tenant_header(): void
    {
        Config::set('record.enable_tenant_id', true);
        request()->attributes->set('resolved_tenant_id', 't1');
        $this->registerTables(null, [], [], ['beforeCreate' => new RecordTableTriggerType(McpHookSpy::class, 'beforeCreateRecordingTenantHeader')]);

        $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        $this->assertSame('t1', McpHookSpy::$captured['tenantHeader']);
    }
}
