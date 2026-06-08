<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Enums\AuditLogEventEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Tests\TestCase;

class AuditLogServiceTest extends TestCase
{
    /** @test */
    public function it_returns_plain_entity_type_as_table_name_when_not_namespace(): void
    {
        $this->assertSame('sp_audit_logs', AuditLogService::getTableNameFromEntityType('sp_audit_logs'));
        $this->assertSame('custom_type', AuditLogService::getTableNameFromEntityType('custom_type'));
    }

    /** @test */
    public function it_resolves_table_name_from_model_class_with_get_table_method(): void
    {
        $this->assertSame('dummy_models', AuditLogService::getTableNameFromEntityType(DummyAuditModel::class));
    }

    /** @test */
    public function it_generates_table_name_from_unknown_class_like_string(): void
    {
        $this->assertSame('unknowns', AuditLogService::getTableNameFromEntityType('App\\Models\\Unknown'));
    }

    /** @test */
    public function it_uses_configured_subject_fields(): void
    {
        Config::set('audit.subject_fields', ['code', 'name']);

        $label = AuditLogService::getAuditSubject([
            'name' => 'Fallback',
            'code' => 'INV-001',
        ]);

        $this->assertSame('Inv 001', $label);
    }

    /** @test */
    public function it_uses_configured_entity_labels(): void
    {
        Config::set('audit.entity_labels', [
            'estimates' => 'Custom Estimate',
            'projects' => 'Project',
        ]);

        $this->assertSame('Custom Estimate', AuditLogService::getEntityLabel('estimates'));
        $this->assertSame('Project', AuditLogService::getEntityLabel('projects'));
    }

    /** @test */
    public function it_uses_configured_recap_entities_and_field_labels(): void
    {
        Config::set('audit.recap_entities', ['customs']);
        Config::set('audit.main_field_labels', [
            'custom_field' => 'Custom Field',
        ]);

        $recap = AuditLogService::generateRecap(
            event: AuditLogEventEnum::UPDATED,
            entityName: 'customs',
            oldData: ['custom_field' => 'old'],
            newData: ['custom_field' => 'new']
        );

        $this->assertStringContainsString('Custom Field', $recap);
    }

    /** @test */
    public function it_generates_generic_recap_for_all_tables_excluding_technical_fields(): void
    {
        Config::set('audit.recap_entities', []);
        Config::set('audit.main_field_labels', []);
        Config::set('audit.excluded_attributes', []);

        $recap = AuditLogService::generateRecap(
            event: AuditLogEventEnum::UPDATED,
            entityName: 'any_table',
            oldData: [
                'id' => 1,
                'name' => 'Old',
                'created_at' => '2025-01-01 00:00:00',
            ],
            newData: [
                'id' => 2,
                'name' => 'New',
                'created_at' => '2025-02-01 00:00:00',
            ]
        );

        $this->assertSame('Updated Any Table: Name', $recap);
    }

    /** @test */
    public function it_returns_empty_recap_when_only_technical_fields_change(): void
    {
        Config::set('audit.recap_entities', []);
        Config::set('audit.excluded_attributes', []);

        $recap = AuditLogService::generateRecap(
            event: AuditLogEventEnum::UPDATED,
            entityName: 'any_table',
            oldData: [
                'created_at' => '2025-01-01 00:00:00',
                'updated_at' => '2025-01-01 00:00:00',
            ],
            newData: [
                'created_at' => '2025-02-01 00:00:00',
                'updated_at' => '2025-02-01 00:00:00',
            ]
        );

        $this->assertSame('', $recap);
    }

    /** @test */
    public function it_limits_generic_recap_fields_when_too_many_changes(): void
    {
        Config::set('audit.recap_entities', []);
        Config::set('audit.excluded_attributes', []);
        Config::set('audit.main_field_labels', []);
        Config::set('audit.recap_max_fields', 2);

        $recap = AuditLogService::generateRecap(
            event: AuditLogEventEnum::UPDATED,
            entityName: 'any_table',
            oldData: [
                'name' => 'Old',
                'title' => 'Old',
                'code' => 'Old',
                'amount' => 10,
            ],
            newData: [
                'name' => 'New',
                'title' => 'New',
                'code' => 'New',
                'amount' => 11,
            ]
        );

        $this->assertSame('Updated Any Table: Name, Title, and 2 more', $recap);
    }

    /** @test */
    public function it_uses_previous_metadata_for_field_changes(): void
    {
        DB::table('sp_audit_logs')->truncate();

        $entityType = 'invoices';
        $entityId = 1;
        $prevChangedAt = Carbon::parse('2025-01-01 10:00:00');
        $prevCreatedAt = Carbon::parse('2025-01-02 12:30:00');

        DB::table('sp_audit_logs')->insert([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => json_encode([
                'field_changes' => [
                    'status' => [
                        'changed_at' => $prevChangedAt->toISOString(),
                        'change_count' => 2,
                        'previous_user' => 'user_5',
                    ],
                ],
            ]),
            'user_id' => 7,
            'created_at' => $prevCreatedAt->toDateTimeString(),
            'updated_at' => $prevCreatedAt->toDateTimeString(),
        ]);

        $result = AuditLogService::getAuditMetadata(
            changedFields: ['status', 'title'],
            oldData: ['status' => 'draft', 'title' => 'Old'],
            newData: ['status' => 'sent', 'title' => 'New'],
            entityType: $entityType,
            entityId: $entityId,
            event: 'updated'
        );

        $status = $result['field_changes']['status'];
        $this->assertSame(3, $status['change_count']);
        $this->assertSame($prevChangedAt->toISOString(), $status['previous_change']);
        $this->assertSame('user_5', $status['previous_user']);

        $title = $result['field_changes']['title'];
        $this->assertSame($prevCreatedAt->toISOString(), $title['previous_change']);
        $this->assertSame(1, $title['change_count']);
        $this->assertSame('user_7', $title['previous_user']);
    }
}

class DummyAuditModel
{
    public function getTable(): string
    {
        return 'dummy_models';
    }
}
