<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\CoreSpLaravelApiProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * sp_api_list_endpoints / sp_api_get_endpoint used to only ever expose
 * list/read/create/update/delete — a frontend AI agent relying solely on the schema MCP
 * (no source access) had no way to discover that upsert, bulk operations, restore, or
 * force-delete exist for a table at all. Both tools now include them, gated the same way
 * the routes themselves are (routes/api.php): canUpsert, canUpdate && softDeletes,
 * canDelete, and RecordConfigService::bulkOperationsEnabled().
 *
 * @internal
 */
class McpSchemaEndpointCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            CoreSpLaravelApiProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('mcp_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    private function configureTable(bool $softDeletes, bool $canUpsert, bool $canDelete = true): void
    {
        Config::set('record.tables', [
            'mcp_invoices' => new RecordTableType(
                table: 'mcp_invoices',
                pmsName: 'mcp_invoices',
                hasTenantId: false,
                softDeletes: $softDeletes,
                canDelete: $canDelete,
                canUpsert: $canUpsert,
                public: new RecordTablePublic(read: true, write: true),
            ),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function getEndpointSchema(): array
    {
        $response = $this->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'sp_api_get_endpoint',
                'arguments' => ['endpoint' => 'mcp_invoices'],
            ],
        ]);

        $response->assertStatus(200);

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function listEndpoints(): array
    {
        $response = $this->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'sp_api_list_endpoints',
                'arguments' => [],
            ],
        ]);

        $response->assertStatus(200);

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    /** @test */
    public function detail_endpoint_methods_are_not_duplicated(): void
    {
        $this->configureTable(softDeletes: true, canUpsert: true);

        $detail = null;
        foreach ($this->listEndpoints() as $endpoint) {
            if (($endpoint['name'] ?? null) === 'mcp_invoices.detail') {
                $detail = $endpoint;
                break;
            }
        }

        $this->assertNotNull($detail, 'the detail endpoint must be listed');

        $methods = (array) ($detail['method'] ?? []);
        $this->assertSame(
            array_values(array_unique($methods)),
            $methods,
            'an agent reading this schema must not see the same HTTP method twice'
        );
        $this->assertSame(['GET', 'PUT', 'PATCH', 'DELETE'], $methods);
    }

    /** @test */
    public function get_endpoint_exposes_upsert_restore_force_delete_and_bulk_actions(): void
    {
        Config::set('record.bulk_operations', true);
        $this->configureTable(softDeletes: true, canUpsert: true);

        $schema = $this->getEndpointSchema();
        $actions = $schema['actions'];

        $this->assertSame(['POST', '/api/mcp_invoices/upsert'], [$actions['upsert']['method'], $actions['upsert']['uri']]);
        $this->assertSame(['POST', '/api/mcp_invoices/{id}/restore'], [$actions['restore']['method'], $actions['restore']['uri']]);
        $this->assertSame(['DELETE', '/api/mcp_invoices/{id}/force'], [$actions['forceDelete']['method'], $actions['forceDelete']['uri']]);
        $this->assertSame(['POST', '/api/mcp_invoices/bulk/create'], [$actions['bulkCreate']['method'], $actions['bulkCreate']['uri']]);
        $this->assertSame(['POST', '/api/mcp_invoices/bulk/update'], [$actions['bulkUpdate']['method'], $actions['bulkUpdate']['uri']]);
        $this->assertSame(['POST', '/api/mcp_invoices/bulk/delete'], [$actions['bulkDelete']['method'], $actions['bulkDelete']['uri']]);
        $this->assertSame(['POST', '/api/mcp_invoices/bulk/upsert'], [$actions['bulkUpsert']['method'], $actions['bulkUpsert']['uri']]);
        $this->assertSame(['POST', '/api/mcp_invoices/bulk'], [$actions['bulkMixed']['method'], $actions['bulkMixed']['uri']]);

        $this->assertStringContainsString('match_on', $actions['upsert']['note']);
        $this->assertStringContainsString('operation', $actions['bulkMixed']['note']);
    }

    /** @test */
    public function restore_is_absent_without_soft_deletes(): void
    {
        Config::set('record.bulk_operations', true);
        $this->configureTable(softDeletes: false, canUpsert: true);

        $actions = $this->getEndpointSchema()['actions'];

        $this->assertArrayNotHasKey('restore', $actions);
        // force delete does not depend on soft deletes
        $this->assertArrayHasKey('forceDelete', $actions);
    }

    /** @test */
    public function upsert_and_bulk_upsert_are_absent_when_can_upsert_is_false(): void
    {
        Config::set('record.bulk_operations', true);
        $this->configureTable(softDeletes: false, canUpsert: false);

        $actions = $this->getEndpointSchema()['actions'];

        $this->assertArrayNotHasKey('upsert', $actions);
        $this->assertArrayNotHasKey('bulkUpsert', $actions);
        $this->assertArrayHasKey('bulkCreate', $actions);
    }

    /** @test */
    public function bulk_actions_are_absent_when_bulk_operations_are_disabled(): void
    {
        Config::set('record.bulk_operations', false);
        $this->configureTable(softDeletes: false, canUpsert: true);

        $actions = $this->getEndpointSchema()['actions'];

        foreach (['bulkCreate', 'bulkUpdate', 'bulkDelete', 'bulkUpsert', 'bulkMixed'] as $bulkAction) {
            $this->assertArrayNotHasKey($bulkAction, $actions);
        }

        // Non-bulk actions are unaffected
        $this->assertArrayHasKey('upsert', $actions);
    }

    /** @test */
    public function bulk_mixed_requires_create_update_and_delete_all_enabled(): void
    {
        Config::set('record.bulk_operations', true);
        $this->configureTable(softDeletes: false, canUpsert: true, canDelete: false);

        $actions = $this->getEndpointSchema()['actions'];

        $this->assertArrayNotHasKey('bulkMixed', $actions);
        $this->assertArrayNotHasKey('bulkDelete', $actions);
        // create/update-only bulk endpoints are still available independently
        $this->assertArrayHasKey('bulkCreate', $actions);
        $this->assertArrayHasKey('bulkUpdate', $actions);
    }

    /** @test */
    public function list_endpoints_tool_also_surfaces_the_new_endpoints(): void
    {
        Config::set('record.bulk_operations', true);
        $this->configureTable(softDeletes: true, canUpsert: true);

        $endpoints = $this->listEndpoints();
        $names = collect($endpoints)->pluck('name');

        foreach (['mcp_invoices.upsert', 'mcp_invoices.restore', 'mcp_invoices.forceDelete', 'mcp_invoices.bulkCreate', 'mcp_invoices.bulkUpdate', 'mcp_invoices.bulkDelete', 'mcp_invoices.bulkUpsert', 'mcp_invoices.bulk'] as $expectedName) {
            $this->assertTrue($names->contains($expectedName), sprintf("Expected endpoint '%s' to be listed", $expectedName));
        }
    }
}
