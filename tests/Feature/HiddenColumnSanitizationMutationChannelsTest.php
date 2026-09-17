<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Events\RecordCreated;
use Sopheak\Core\Events\RecordMutated;
use Sopheak\Core\Events\RecordUpdated;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Traits\AuditableTrait;
use Sopheak\Core\Triggers\WebhookTrigger;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class TestAuditableUser extends Model
{
    use AuditableTrait;

    protected $table = 'users';
    protected $guarded = [];
}

class HiddenColumnSanitizationMutationChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('audit.enabled', true);
        config()->set('record.enabled', true);
        config()->set('audit.queue_enabled', false); // process synchronously in tests

        if (!Schema::hasTable('sp_audit_logs')) {
            Schema::create('sp_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('entity_type')->nullable();
                $table->string('entity_id')->nullable();
                $table->string('entity_name')->nullable();
                $table->string('event')->nullable();
                $table->string('title')->nullable();
                $table->string('subject')->nullable();
                $table->text('recap')->nullable();
                $table->json('old_data')->nullable();
                $table->json('new_data')->nullable();
                $table->string('user_id')->nullable();
                $table->string('tenant_id')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address')->nullable();
                $table->string('user_agent')->nullable();
                $table->string('request_id')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password')->nullable();
                $table->string('remember_token')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sp_webhook_endpoints')) {
            Schema::create('sp_webhook_endpoints', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('url');
                $table->string('secret');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sp_webhook_subscriptions')) {
            Schema::create('sp_webhook_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('endpoint_id');
                $table->string('table_name');
                $table->string('event');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sp_webhook_deliveries')) {
            Schema::create('sp_webhook_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('endpoint_id');
                $table->string('event');
                $table->json('payload');
                $table->string('status')->default('pending');
                $table->integer('response_status')->nullable();
                $table->text('response_body')->nullable();
                $table->timestamps();
            });
        }

        $userConfig = new RecordTableType('users');
        $userConfig->disableCache = true;
        $userConfig->hasTenantId = false;
        $userConfig->isAuthRead = false;
        $userConfig->public = new RecordTablePublic(read: true, write: true);
        $userConfig->columnHiddens = ['password', 'remember_token'];
        $userConfig->columns = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string'],
            'password' => ['type' => 'string'],
            'remember_token' => ['type' => 'string'],
        ];

        $endpointConfig = new RecordTableType('sp_webhook_endpoints');
        $endpointConfig->disableCache = true;
        $endpointConfig->hasTenantId = false;
        $endpointConfig->isAuthRead = false;
        $endpointConfig->isAuthWrite = false;
        $endpointConfig->public = new RecordTablePublic(read: true, write: true);
        $endpointConfig->columns = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'url' => ['type' => 'string'],
            'secret' => ['type' => 'string'],
            'is_active' => ['type' => 'boolean'],
        ];

        $subConfig = new RecordTableType('sp_webhook_subscriptions');
        $subConfig->disableCache = true;
        $subConfig->hasTenantId = false;
        $subConfig->isAuthRead = false;
        $subConfig->isAuthWrite = false;
        $subConfig->public = new RecordTablePublic(read: true, write: true);
        $subConfig->columns = [
            'id' => ['type' => 'integer'],
            'endpoint_id' => ['type' => 'integer'],
            'table_name' => ['type' => 'string'],
            'event' => ['type' => 'string'],
        ];

        $delivConfig = new RecordTableType('sp_webhook_deliveries');
        $delivConfig->disableCache = true;
        $delivConfig->hasTenantId = false;
        $delivConfig->isAuthRead = false;
        $delivConfig->isAuthWrite = false;
        $delivConfig->public = new RecordTablePublic(read: true, write: true);
        $delivConfig->columns = [
            'id' => ['type' => 'integer'],
            'endpoint_id' => ['type' => 'integer'],
            'event' => ['type' => 'string'],
            'payload' => ['type' => 'json'],
            'status' => ['type' => 'string'],
            'response_status' => ['type' => 'integer'],
            'response_body' => ['type' => 'text'],
        ];

        Config::set('record.tables', [
            'users' => $userConfig,
            'sp_webhook_endpoints' => $endpointConfig,
            'sp_webhook_subscriptions' => $subConfig,
            'sp_webhook_deliveries' => $delivConfig,
        ]);
        SchemaRegistryUtils::clearAllCache();

        SchemaRegistryUtils::register('users', $userConfig);
        SchemaRegistryUtils::register('sp_webhook_endpoints', $endpointConfig);
        SchemaRegistryUtils::register('sp_webhook_subscriptions', $subConfig);
        SchemaRegistryUtils::register('sp_webhook_deliveries', $delivConfig);

        $this->adminUser = new User();
        $this->adminUser->id = 1;
        $this->adminUser->name = 'Admin';
        $this->adminUser->email = 'admin@example.com';
        $this->actingAs($this->adminUser);

        Gate::before(fn(): bool => true);
    }

    /** @test */
    public function audit_logs_completely_strip_hidden_columns_on_create_and_update(): void
    {
        $service = app(RecordService::class);

        // 1. Create record with password
        $createPayload = [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'super_secret_123',
            'remember_token' => 'token_abc',
        ];

        $created = $service->createRecord('users', $createPayload, null);
        $userId = $created['id'];

        $service->processPostWriteLogic(Request::create('/'), 'users', 'create', [
            'id' => $userId,
            'payload' => $createPayload,
            'response' => response()->json(['data' => (array) DB::table('users')->where('id', $userId)->first()]),
        ]);

        $createLog = DB::table('sp_audit_logs')
            ->where('entity_type', 'users')
            ->where('entity_id', (string) $userId)
            ->where('event', AuditLogEventEnum::CREATED->value)
            ->first();

        $this->assertNotNull($createLog);
        $newData = json_decode($createLog->new_data, true);
        $this->assertEquals('Alice', $newData['name']);
        $this->assertArrayNotHasKey('password', $newData);
        $this->assertArrayNotHasKey('remember_token', $newData);

        $meta = json_decode($createLog->metadata, true);
        if (isset($meta['field_changes'])) {
            $this->assertArrayNotHasKey('password', $meta['field_changes']);
            $this->assertArrayNotHasKey('remember_token', $meta['field_changes']);
        }

        // 2. Update record (name and password)
        $updatePayload = [
            'name' => 'Alice Cooper',
            'password' => 'new_secret_password',
        ];
        $service->updateRecord('users', $userId, $updatePayload, null);
        $service->processPostWriteLogic(Request::create('/'), 'users', 'update', [
            'id' => $userId,
            'payload' => $updatePayload,
            'response' => response()->json(['data' => (array) DB::table('users')->where('id', $userId)->first()]),
        ]);

        $updateLog = DB::table('sp_audit_logs')
            ->where('entity_type', 'users')
            ->where('entity_id', (string) $userId)
            ->where('event', AuditLogEventEnum::UPDATED->value)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($updateLog);
        $oldData = json_decode($updateLog->old_data, true);
        $newUpdateData = json_decode($updateLog->new_data, true);

        $this->assertArrayNotHasKey('password', $oldData);
        $this->assertArrayNotHasKey('password', $newUpdateData);
        $this->assertArrayNotHasKey('remember_token', $oldData);
        $this->assertArrayNotHasKey('remember_token', $newUpdateData);

        $updateMeta = json_decode($updateLog->metadata, true);
        if (isset($updateMeta['field_changes'])) {
            $this->assertArrayHasKey('name', $updateMeta['field_changes']);
            $this->assertArrayNotHasKey('password', $updateMeta['field_changes']);
        }

        if ($updateLog->recap) {
            $this->assertStringNotContainsString('password', $updateLog->recap);
            $this->assertStringNotContainsString('new_secret_password', $updateLog->recap);
        }
    }

    /** @test */
    public function audit_logs_record_event_when_only_hidden_column_is_updated_without_leaking_it(): void
    {
        $service = app(RecordService::class);

        $created = $service->createRecord('users', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'initial_password',
        ], null);
        $userId = $created['id'];

        $service->processPostWriteLogic(Request::create('/'), 'users', 'create', [
            'id' => $userId,
            'payload' => ['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'initial_password'],
            'response' => response()->json(['data' => (array) DB::table('users')->where('id', $userId)->first()]),
        ]);

        // Update ONLY password
        $pwdPayload = ['password' => 'updated_secret_password'];
        $service->updateRecord('users', $userId, $pwdPayload, null);
        $service->processPostWriteLogic(Request::create('/'), 'users', 'update', [
            'id' => $userId,
            'payload' => $pwdPayload,
            'response' => response()->json(['data' => (array) DB::table('users')->where('id', $userId)->first()]),
        ]);

        $passwordUpdateLog = DB::table('sp_audit_logs')
            ->where('entity_type', 'users')
            ->where('entity_id', (string) $userId)
            ->where('event', AuditLogEventEnum::UPDATED->value)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($passwordUpdateLog, 'An audit log must still be created when sensitive columns are modified');
        $oldData = json_decode($passwordUpdateLog->old_data, true);
        $newData = json_decode($passwordUpdateLog->new_data, true);

        $this->assertArrayNotHasKey('password', $oldData);
        $this->assertArrayNotHasKey('password', $newData);

        $meta = json_decode($passwordUpdateLog->metadata, true);
        $this->assertEmpty($meta['field_changes'] ?? []);
    }

    /** @test */
    public function field_timeline_and_stats_block_hidden_columns(): void
    {
        $service = app(RecordService::class);

        $created = $service->createRecord('users', [
            'name' => 'Charlie',
            'email' => 'charlie@example.com',
            'password' => 'pwd_1',
        ], null);
        $userId = (int) $created['id'];

        $service->processPostWriteLogic(Request::create('/'), 'users', 'create', [
            'id' => $userId,
            'payload' => ['name' => 'Charlie', 'email' => 'charlie@example.com', 'password' => 'pwd_1'],
            'response' => response()->json(['data' => (array) DB::table('users')->where('id', $userId)->first()]),
        ]);

        $updatePayload = [
            'name' => 'Charlie Brown',
            'password' => 'pwd_2',
        ];
        $service->updateRecord('users', $userId, $updatePayload, null);
        $service->processPostWriteLogic(Request::create('/'), 'users', 'update', [
            'id' => $userId,
            'payload' => $updatePayload,
            'response' => response()->json(['data' => (array) DB::table('users')->where('id', $userId)->first()]),
        ]);

        // Name is visible
        $nameTimeline = AuditLogService::getFieldTimeline('users', $userId, 'name');
        $this->assertNotEmpty($nameTimeline);
        $nameStats = AuditLogService::getFieldStats('users', $userId, 'name');
        $this->assertGreaterThan(0, $nameStats['total_changes']);

        // Password is in columnHiddens -> must return empty
        $passwordTimeline = AuditLogService::getFieldTimeline('users', $userId, 'password');
        $this->assertSame([], $passwordTimeline);

        $passwordStats = AuditLogService::getFieldStats('users', $userId, 'password');
        $this->assertSame(0, $passwordStats['total_changes']);
        $this->assertSame([], $passwordStats['changed_by_users']);
        $this->assertSame([], $passwordStats['value_frequency']);
        $this->assertNull($passwordStats['current_value']);
    }

    /** @test */
    public function broadcast_and_event_dispatches_strip_hidden_columns(): void
    {
        config()->set('record.broadcast_events', true);

        Event::fake([
            RecordMutated::class,
            RecordCreated::class,
            RecordUpdated::class,
        ]);

        $createPayload = [
            'name' => 'Dave',
            'email' => 'dave@example.com',
            'password' => 'dave_secret',
            'remember_token' => 'token_dave',
        ];

        $service = app(RecordService::class);

        // Test via executeCreate
        $created = RecordService::executeCreate('users', $createPayload);
        $userId = is_object($created['data'] ?? null) ? $created['data']->id : ($created['data']['id'] ?? $created['id']);

        $service->processPostWriteLogic(Request::create('/'), 'users', 'create', [
            'id' => $userId,
            'payload' => $createPayload,
            'response' => response()->json(['data' => (array) DB::table('users')->where('id', $userId)->first()]),
        ]);

        Event::assertDispatched(RecordMutated::class, function (RecordMutated $event): bool {
            $this->assertSame('users', $event->table);
            $this->assertSame('created', $event->action);
            $record = (array) $event->record;
            $this->assertArrayNotHasKey('password', $record);
            $this->assertArrayNotHasKey('remember_token', $record);
            $this->assertEquals('Dave', $record['name']);
            return true;
        });

        Event::assertDispatched(RecordCreated::class, function (RecordCreated $event): bool {
            $this->assertSame('users', $event->table);
            $this->assertArrayNotHasKey('password', $event->payload);
            $this->assertArrayNotHasKey('remember_token', $event->payload);
            return true;
        });

        // Test executeUpdate
        RecordService::executeUpdate('users', $userId, [
            'name' => 'Dave Miller',
            'password' => 'dave_new_pwd',
        ]);

        Event::assertDispatched(RecordUpdated::class, function (RecordUpdated $event): bool {
            $this->assertSame('users', $event->table);
            $this->assertArrayNotHasKey('password', $event->oldPayload);
            $this->assertArrayNotHasKey('password', $event->newPayload);
            return true;
        });
    }

    /** @test */
    public function webhook_trigger_strips_hidden_columns_from_delivery_payload(): void
    {
        Http::fake();
        config()->set('webhooks.enabled', true);

        $endpointId = DB::table('sp_webhook_endpoints')->insertGetId([
            'name' => 'Test Webhook',
            'url' => 'https://example.com/webhook',
            'secret' => 'wh_secret',
            'is_active' => 'true',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sp_webhook_subscriptions')->insert([
            'endpoint_id' => $endpointId,
            'table_name' => 'users',
            'event' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/api/v1/users', 'POST');
        WebhookTrigger::afterCreate($request, 'users', [
            'data' => [
                'id' => 999,
                'name' => 'Eve',
                'email' => 'eve@example.com',
                'password' => 'eve_password_hash',
                'remember_token' => 'remember_eve',
            ],
        ]);

        $delivery = DB::table('sp_webhook_deliveries')->where('endpoint_id', $endpointId)->first();
        $this->assertNotNull($delivery);

        $payload = json_decode($delivery->payload, true);
        $this->assertEquals('Eve', $payload['name']);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
    }

    /** @test */
    public function auditable_trait_strips_hidden_columns_for_eloquent_models(): void
    {
        $user = new TestAuditableUser();
        $user->name = 'Frank';
        $user->email = 'frank@example.com';
        $user->password = 'frank_secret';
        $user->remember_token = 'frank_token';
        $user->save();

        $user->triggerAuditLog(AuditLogEventEnum::CREATED);

        $audit = DB::table('sp_audit_logs')
            ->where('entity_type', 'users')
            ->where('entity_id', (string) $user->id)
            ->where('event', AuditLogEventEnum::CREATED->value)
            ->first();

        $this->assertNotNull($audit);
        $newData = json_decode($audit->new_data, true);
        $this->assertEquals('Frank', $newData['name']);
        $this->assertArrayNotHasKey('password', $newData);
        $this->assertArrayNotHasKey('remember_token', $newData);
    }

    /** @test */
    public function mcp_module_aligns_with_rest_api_hidden_column_handling(): void
    {
        config()->set('record.mcp.enabled', true);
        config()->set('record.mcp.read_only', false);

        $mcp = new \Sopheak\Core\Services\McpServerService();

        // 1. Schema MCP: sp_api_get_endpoint
        $response = $mcp->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'sp_api_get_endpoint',
                'arguments' => ['endpoint' => 'users'],
            ],
        ]);

        $this->assertNotNull($response);
        $this->assertArrayNotHasKey('error', $response);

        $content = $response['result']['structuredContent'];
        $fields = collect($content['fields']);
        $pwdField = $fields->firstWhere('name', 'password');
        $this->assertNotNull($pwdField);
        $this->assertTrue($pwdField['hidden'] ?? false);
        $this->assertContains('write', $pwdField['in']);
        $this->assertNotContains('read', $pwdField['in']);

        // Filters and Sorts must exclude hidden columns
        $filterFields = collect($content['filters'])->pluck('field')->all();
        $this->assertNotContains('password', $filterFields);
        $this->assertNotContains('remember_token', $filterFields);
        $this->assertNotContains('password', $content['sorts']);
        $this->assertNotContains('remember_token', $content['sorts']);

        // Read response schema must exclude hidden fields
        $readResponseProperties = $content['actions']['read']['response']['dataSchema']['properties'] ?? [];
        $this->assertArrayNotHasKey('password', $readResponseProperties);
        $this->assertArrayNotHasKey('remember_token', $readResponseProperties);

        // Create request payload schema MUST include writable hidden fields (same as REST API)
        $createRequestProperties = $content['actions']['create']['request']['payload']['properties'] ?? [];
        $this->assertArrayHasKey('password', $createRequestProperties);

        // 2. Data MCP: list_users & read_users
        $userId = DB::table('users')->insertGetId([
            'name' => 'Grace',
            'email' => 'grace@example.com',
            'password' => 'grace_super_secret',
            'remember_token' => 'grace_token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->adminUser);

        $readResponse = $mcp->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'read_users',
                'arguments' => ['id' => $userId],
            ],
        ]);

        $readResult = $readResponse['result']['structuredContent']['response']['data'] ?? [];
        $this->assertEquals('Grace', $readResult['name']);
        $this->assertArrayNotHasKey('password', $readResult);
        $this->assertArrayNotHasKey('remember_token', $readResult);

        $listResponse = $mcp->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_users',
                'arguments' => [],
            ],
        ]);

        $listItems = $listResponse['result']['structuredContent']['response']['data'] ?? [];
        $graceRow = collect($listItems)->firstWhere('id', $userId);
        $this->assertNotNull($graceRow);
        $this->assertArrayNotHasKey('password', (array) $graceRow);
        $this->assertArrayNotHasKey('remember_token', (array) $graceRow);
    }
}
