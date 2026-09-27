<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * `record.id_type = 'integer'` is a supported configuration, so a tenant column
 * holds an int. The audit path declared `?string $tenantId` in several places
 * while its callers pass the tenant through as `mixed`, and the file is under
 * `strict_types=1` — so no coercion happened and every tenant-scoped write
 * crashed with a TypeError before the audit row could be written.
 *
 * Widening one signature was not enough: the failure simply moved to the next
 * `?string $tenantId` in the same call stack, which is why these tests drive the
 * whole write path rather than a single method.
 *
 * @internal
 */
class IntegerTenantAuditLoggingTest extends TestCase
{
    private const TENANT = 1;

    private const RECORD_ID = 3;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->timestamps();
        });

        DB::table('settings')->insert([
            'id' => self::RECORD_ID,
            'name' => 'before',
            'tenant_id' => self::TENANT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config([
            'audit.enabled' => true,
            'audit.queue_enabled' => false,
            'record.id_type' => 'integer',
            'record.enable_tenant_id' => true,
            'record.tables' => ['settings' => new RecordTableType(
                table: 'settings',
                pmsName: 'setting',
                hasTenantId: true,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'tenant_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            )],
        ]);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function get_old_audit_log_date_accepts_an_integer_tenant_id(): void
    {
        $this->assertNull(
            AuditLogService::getOldAuditLogDate(self::RECORD_ID, 'settings', self::TENANT)
        );
    }

    /** @test */
    public function a_tenant_scoped_update_writes_an_audit_row_with_an_integer_tenant(): void
    {
        app(RecordService::class)->updateRecord('settings', self::RECORD_ID, ['name' => 'after'], self::TENANT);

        app(RecordService::class)->processPostWriteLogic(
            Request::create('/'),
            'settings',
            'update',
            ['id' => self::RECORD_ID, 'payload' => ['name' => 'after'], 'tenant_id' => self::TENANT]
        );

        $row = DB::table('sp_audit_logs')->latest('id')->first();

        $this->assertNotNull($row, 'a tenant-scoped update must still write an audit row');
        $this->assertSame('updated', $row->event);
        $this->assertSame('settings', $row->entity_name);
        $this->assertSame('settings', $row->entity_type);
        $this->assertSame((string) self::RECORD_ID, (string) $row->entity_id);
        $this->assertSame('after', DB::table('settings')->where('id', self::RECORD_ID)->value('name'));
    }

    /** @test */
    public function audit_metadata_accepts_an_integer_tenant_id(): void
    {
        // The second throw point: widening only getOldAuditLogDate moved the
        // TypeError here, in the same request.
        $metadata = AuditLogService::getAuditMetadata(
            changedFields: ['name'],
            oldData: ['name' => 'before'],
            newData: ['name' => 'after'],
            entityType: 'settings',
            entityId: self::RECORD_ID,
            event: 'updated',
            tenantId: self::TENANT,
        );

        $this->assertIsArray($metadata);
    }

    /** @test */
    public function entity_audit_log_lookups_accept_an_integer_tenant_id(): void
    {
        $logs = AuditLogService::getEntityAuditLogs('settings', self::RECORD_ID, self::TENANT);

        $this->assertCount(0, $logs);
    }

    /** @test */
    public function string_and_uuid_tenant_ids_still_work(): void
    {
        $this->assertNull(AuditLogService::getOldAuditLogDate('3', 'settings', 'tenant-uuid'));
        // Kept as a variable so the explicit "no tenant" case stays covered.
        $noTenant = null;
        $this->assertNull(AuditLogService::getOldAuditLogDate(self::RECORD_ID, 'settings', $noTenant));
        $this->assertCount(0, AuditLogService::getEntityAuditLogs('settings', '3', 'tenant-uuid'));
    }
}
