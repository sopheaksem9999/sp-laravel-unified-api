<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Types\RecordHasManyType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Sopheak\Core\CoreSpLaravelApiProvider;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The MCP data tools took the tenant from the model's own tool arguments and
 * never consulted the request, so a caller could read or write another
 * company's rows by naming their tenant id — or omit the argument and get
 * every tenant's rows at once. The request is the only authority now.
 *
 * @internal
 */
class McpTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [CoreSpLaravelApiProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('record.mcp.middleware', []);
        $app['config']->set('record.enable_tenant_id', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('note_id')->nullable();
            $t->timestamps();
        });

        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'ACME-SECRET', 'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'GLOBEX-SECRET', 'tenant_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Config::set('record.tables', ['widgets' => new RecordTableType(
            table: 'widgets',
            pmsName: 'widget',
            hasTenantId: true,
            public: new RecordTablePublic(read: true, write: true),
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'name' => ['type' => 'string', 'nullable' => false],
                'tenant_id' => ['type' => 'bigInteger', 'nullable' => true],
                'note_id' => ['type' => 'bigInteger', 'nullable' => true],
                'created_at' => ['type' => 'datetime', 'nullable' => true],
                'updated_at' => ['type' => 'datetime', 'nullable' => true],
            ],
        )]);

        SchemaRegistryUtils::refresh();
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, string> $headers
     */
    protected function callTool(string $tool, array $arguments, array $headers = []): TestResponse
    {
        return $this->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], $headers);
    }

    protected function bodyText(TestResponse $response): string
    {
        return (string) ($response->json('result.content.0.text') ?? json_encode($response->json()));
    }

    /** @test */
    public function the_request_tenant_scopes_the_result(): void
    {
        $body = $this->bodyText($this->callTool('list_widgets', [], ['X-Tenant-ID' => '1']));

        $this->assertStringContainsString('ACME-SECRET', $body);
        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
    }

    /** @test */
    public function a_tenant_id_argument_for_another_company_is_rejected(): void
    {
        $response = $this->callTool('list_widgets', ['tenantId' => 2], ['X-Tenant-ID' => '1']);
        $body = $this->bodyText($response);

        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
        $this->assertStringContainsString('tenant', strtolower($body));
    }

    /** @test */
    public function omitting_the_tenant_id_no_longer_returns_every_tenant(): void
    {
        $body = $this->bodyText($this->callTool('list_widgets', [], ['X-Tenant-ID' => '2']));

        $this->assertStringContainsString('GLOBEX-SECRET', $body);
        $this->assertStringNotContainsString('ACME-SECRET', $body);
    }

    /** @test */
    public function a_matching_tenant_id_argument_is_accepted(): void
    {
        // Well-behaved existing clients pass the tenant they are already scoped
        // to; that must keep working.
        $body = $this->bodyText($this->callTool('list_widgets', ['tenantId' => 1], ['X-Tenant-ID' => '1']));

        $this->assertStringContainsString('ACME-SECRET', $body);
        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
    }

    /** @test */
    public function a_string_tenant_id_argument_matches_an_integer_tenant(): void
    {
        $body = $this->bodyText($this->callTool('list_widgets', ['tenantId' => '1'], ['X-Tenant-ID' => '1']));

        $this->assertStringContainsString('ACME-SECRET', $body);
    }

    /** @test */
    public function a_tenant_scoped_table_with_no_request_tenant_is_refused(): void
    {
        $body = $this->bodyText($this->callTool('list_widgets', []));

        $this->assertStringNotContainsString('ACME-SECRET', $body);
        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
        $this->assertStringContainsString('tenant', strtolower($body));
    }

    /** @test */
    public function a_table_without_tenant_id_is_unaffected(): void
    {
        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('body');
            $t->timestamps();
        });
        DB::table('notes')->insert(['id' => 1, 'body' => 'SHARED-NOTE', 'created_at' => now(), 'updated_at' => now()]);

        $tables = Config::get('record.tables');
        $tables['notes'] = new RecordTableType(
            table: 'notes',
            pmsName: 'note',
            hasTenantId: false,
            public: new RecordTablePublic(read: true, write: true),
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'body' => ['type' => 'string', 'nullable' => false],
                'created_at' => ['type' => 'datetime', 'nullable' => true],
                'updated_at' => ['type' => 'datetime', 'nullable' => true],
            ],
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();

        // No tenant header at all: a non-tenant table must still answer.
        $body = $this->bodyText($this->callTool('list_notes', []));

        $this->assertStringContainsString('SHARED-NOTE', $body);
    }

    /** @test */
    public function reading_another_companys_row_by_id_is_refused(): void
    {
        // id 2 belongs to tenant 2; the caller is tenant 1.
        $body = $this->bodyText($this->callTool('read_widgets', ['id' => 2], ['X-Tenant-ID' => '1']));

        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
    }

    /** @test */
    public function updating_another_companys_row_does_not_change_it(): void
    {
        $this->callTool('update_widgets', [
            'id' => 2,
            'payload' => ['name' => 'HIJACKED'],
            'tenantId' => 2,
        ], ['X-Tenant-ID' => '1']);

        $this->assertSame('GLOBEX-SECRET', DB::table('widgets')->where('id', 2)->value('name'));
    }

    /** @test */
    public function deleting_another_companys_row_does_not_remove_it(): void
    {
        $this->callTool('delete_widgets', [
            'id' => 2,
            'tenantId' => 2,
        ], ['X-Tenant-ID' => '1']);

        $this->assertSame(1, DB::table('widgets')->where('id', 2)->count());
    }

    /** @test */
    public function a_created_row_is_stamped_with_the_request_tenant(): void
    {
        $this->callTool('create_widgets', [
            'payload' => ['name' => 'NEW-ACME'],
        ], ['X-Tenant-ID' => '1']);

        $this->assertSame(1, (int) DB::table('widgets')->where('name', 'NEW-ACME')->value('tenant_id'));
    }

    /** @test */
    public function updating_your_own_row_still_works(): void
    {
        // Positive control: the refusals above must be specific to the tenant
        // boundary, not the update tool being broken for every caller.
        $this->callTool('update_widgets', [
            'id' => 1,
            'payload' => ['name' => 'ACME-RENAMED'],
        ], ['X-Tenant-ID' => '1']);

        $this->assertSame('ACME-RENAMED', DB::table('widgets')->where('id', 1)->value('name'));
    }

    /** @test */
    public function data_tools_no_longer_advertise_a_tenant_id_argument(): void
    {
        $response = $this->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $tools = (array) $response->json('result.tools');
        $this->assertNotEmpty($tools);

        foreach ($tools as $tool) {
            $properties = (array) ($tool['inputSchema']['properties'] ?? []);
            $this->assertArrayNotHasKey(
                'tenantId',
                $properties,
                sprintf('tool %s must not advertise tenantId', (string) ($tool['name'] ?? '?'))
            );
        }
    }

    /**
     * A parent table that is not tenant-scoped is still a pivot into tenant-scoped
     * children. `resolveToolTenantId()` returned null for such a parent, so
     * `executeGetByFilter()` built a synthetic Request carrying no tenant at all and
     * `resolveRelationshipTenantId()` fell through to `input('tenant_id')` — which is
     * model-supplied `queryParams`. Both tenants' children came back, and naming
     * another tenant in queryParams returned theirs.
     */
    private function seedNotesWithWidgetChildren(): void
    {
        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('body');
            $t->timestamps();
        });
        DB::table('notes')->insert(['id' => 1, 'body' => 'SHARED-NOTE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('widgets')->update(['note_id' => 1]);

        $tables = Config::get('record.tables');
        $tables['notes'] = new RecordTableType(
            table: 'notes',
            pmsName: 'note',
            hasTenantId: false,
            public: new RecordTablePublic(read: true, write: true),
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'body' => ['type' => 'string', 'nullable' => false],
                'created_at' => ['type' => 'datetime', 'nullable' => true],
                'updated_at' => ['type' => 'datetime', 'nullable' => true],
            ],
            relationships: [
                'widgets' => new RecordHasManyType(
                    table: 'widgets',
                    foreignKey: 'note_id',
                    type: RecordRelationshipsEnum::HAS_MANY,
                    localKey: 'id',
                ),
            ],
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function a_relationship_from_a_non_tenant_parent_is_still_tenant_scoped(): void
    {
        $this->seedNotesWithWidgetChildren();

        $body = $this->bodyText($this->callTool(
            'list_notes',
            ['queryParams' => ['select' => '*,widgets(*)']],
            ['X-Tenant-ID' => '1'],
        ));

        $this->assertStringContainsString('ACME-SECRET', $body);
        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
    }

    /** @test */
    public function query_params_cannot_name_another_tenant_through_a_non_tenant_parent(): void
    {
        $this->seedNotesWithWidgetChildren();

        $body = $this->bodyText($this->callTool(
            'list_notes',
            ['queryParams' => ['select' => '*,widgets(*)', 'tenant_id' => 2]],
            ['X-Tenant-ID' => '1'],
        ));

        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
    }

    /** @test */
    public function reading_your_own_row_by_id_still_works(): void
    {
        // Positive control for reading_another_companys_row_by_id_is_refused, which
        // asserts only an absence and would pass if read_* were broken for everyone.
        $body = $this->bodyText($this->callTool('read_widgets', ['id' => 1], ['X-Tenant-ID' => '1']));

        $this->assertStringContainsString('ACME-SECRET', $body);
    }

    /** @test */
    public function a_non_scalar_tenant_id_argument_is_refused_without_a_php_warning(): void
    {
        $body = $this->bodyText($this->callTool('list_widgets', ['tenantId' => []], ['X-Tenant-ID' => '1']));

        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
        $this->assertStringNotContainsString('ACME-SECRET', $body);
    }

    /** @test */
    public function a_nested_write_through_a_non_tenant_parent_cannot_touch_another_tenants_child(): void
    {
        $this->seedNotesWithWidgetChildren();

        $this->callTool('update_notes', [
            'id' => 1,
            'payload' => [
                'body' => 'SHARED-NOTE',
                'widgets' => [['id' => 2, 'name' => 'HIJACKED-VIA-NESTED']],
            ],
        ], ['X-Tenant-ID' => '1']);

        $this->assertSame('GLOBEX-SECRET', DB::table('widgets')->where('id', 2)->value('name'));
    }
}
