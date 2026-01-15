<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Http\Request;
use Sopheak\Core\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Enums\AuditLogEventEnum;

class AuditLogTenantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create a test table
        Schema::create('test_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        // Create audit_logs table (it should be created by migration, but just in case for test env)
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('entity_name')->nullable();
                $table->string('entity_type')->nullable();
                $table->string('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('event')->nullable();
                $table->string('title')->nullable();
                $table->string('subject')->nullable();
                $table->text('recap')->nullable();
                $table->json('old_data')->nullable();
                $table->json('new_data')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        // Configure RecordService
        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'tenant_id');
        Config::set('audit.enabled', true);

        // Register table in SchemaRegistry
        $schema = [
            'test_products' => new RecordTableType(
                table: 'test_products',
                pms_name: 'test_product',
                has_tenant_id: true
            ),
        ];
        Config::set('record.tables', $schema);
        SchemaRegistry::refresh();
    }

    public function test_audit_log_stores_tenant_id_on_create(): void
    {
        $service = new RecordService();
        $request = new Request();

        $payload = ['name' => 'Test Product'];
        $tenantId = 'tenant-123';

        // We need to simulate the flow that triggers audit logs.
        // RecordService::createRecord does NOT trigger audit log directly.
        // CoreRecordController or processBulkOperations does.
        // Let's use processPostWriteLogic which we modified.

        $result = $service->createRecord(table: 'test_products', payload: $payload, tenantId: $tenantId);

        $service->processPostWriteLogic($request, 'test_products', 'create', [
            'id' => $result['id'],
            'payload' => $result['payload'],
            'tenant_id' => $tenantId, // Passed by controller usually
            'response' => $result['payload']
        ]);

        $log = DB::table('audit_logs')->where('entity_id', $result['id'])->first();

        if (!$log) {
            dump(DB::table('audit_logs')->get());
        }

        $this->assertNotNull($log);
        $this->assertEquals($tenantId, $log->tenant_id);
    }

    public function test_audit_log_stores_tenant_id_on_update(): void
    {
        // First create a record
        $tenantId = 'tenant-456';
        DB::table('test_products')->insert([
            'name' => 'Old Name',
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = DB::getPdo()->lastInsertId();

        $service = new RecordService();
        $request = new Request();

        $payload = ['name' => 'New Name'];

        $result = $service->updateRecord(table: 'test_products', id: $id, payload: $payload, tenantId: $tenantId);

        $service->processPostWriteLogic($request, 'test_products', 'update', [
            'id' => $id,
            'payload' => $result['payload'],
            'tenant_id' => $tenantId,
            'updated' => 1,
            'response' => $result['payload']
        ]);

        $log = DB::table('audit_logs')
            ->where('entity_id', $id)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($tenantId, $log->tenant_id);
    }

    public function test_auth_event_stores_tenant_id(): void
    {
        $tenantId = 'tenant-auth-789';
        $data = ['ip' => '127.0.0.1'];

        // Ensure user model is configured (though authEvent handles default)
        // We just need to check if audit log is created with tenant_id

        AuditLogService::authEvent(AuditLogEventEnum::LOGIN, $data, $tenantId);

        $log = DB::table('audit_logs')
            ->where('event', AuditLogEventEnum::LOGIN->value)
            ->where('tenant_id', $tenantId)
            ->first();

        $this->assertNotNull($log, 'Auth event log not found');
        $this->assertEquals($tenantId, $log->tenant_id);
        $this->assertEquals(json_encode($data), $log->metadata);
    }

    public function test_create_log_endpoint_uses_tenant_header(): void
    {
        $tenantId = 'tenant-header-123';
        $tenantHeader = 'X-Tenant-ID'; // Default

        $request = new Request();
        $request->headers->set($tenantHeader, $tenantId);
        $request->merge([
            'event' => 'created',
            'entity_type' => 'test_products',
            'entity_id' => 999,
            'entity_name' => 'test_product',
            'subject' => 'Test Subject',
        ]);

        // Resolve controller
        $controller = app(AuditLogController::class);

        // Call method
        $response = $controller->createLog($request);

        $this->assertEquals(200, $response->getStatusCode());

        $log = DB::table('audit_logs')
            ->where('entity_id', 999)
            ->first();

        $this->assertNotNull($log, 'Audit log should be created');
        $this->assertEquals($tenantId, $log->tenant_id, 'Tenant ID mismatch: ' . ($log->tenant_id ?? 'null'));
    }
}
