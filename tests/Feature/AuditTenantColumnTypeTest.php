<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;

class AuditTenantColumnTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Both must be set here, not in setUp(): RefreshDatabase migrates
        // before setUp()'s body runs, so this is what the schema is built
        // from. audit.enabled turns on the real migration (the base
        // TestCase disables it and falls back to its own fixture table);
        // enable_tenant_id makes that migration add the tenant column at all.
        $app['config']->set('audit.enabled', true);
        $app['config']->set('record.enable_tenant_id', true);
    }

    /** @test */
    public function audit_logs_tenant_column_is_a_string(): void
    {
        // Every sibling package migration (attachments, webhooks, permissions)
        // hardcodes its tenant column as a string, because a string holds
        // either an integer or a uuid tenant id. sp_audit_logs must match,
        // not vary its column type with record.tenant_column_type.
        $tenantColumn = RecordConfigService::tenantColumn();
        $columns = collect(Schema::getColumns('sp_audit_logs'))->keyBy('name');

        $this->assertTrue(
            $columns->has($tenantColumn),
            "sp_audit_logs should have a {$tenantColumn} column when tenant ids are enabled"
        );
        $this->assertSame('varchar', $columns[$tenantColumn]['type']);
    }
}
