<?php

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Tests\TestCase;

class AuditLogServiceTest extends TestCase
{
    /** @test */
    public function it_returns_plain_entity_type_as_table_name_when_not_namespace(): void
    {
        $this->assertSame('audit_logs', AuditLogService::getTableNameFromEntityType('audit_logs'));
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
}

class DummyAuditModel
{
    public function getTable(): string
    {
        return 'dummy_models';
    }
}

