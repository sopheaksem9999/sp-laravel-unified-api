<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Services\McpServerService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Phase 1 moves the MCP tool catalog and executor into Sopheak\Core\Mcp\*. The
 * move must be invisible to clients: every response a fixed fixture produces
 * today is captured as a golden file, and the moved code must reproduce it.
 * Only the additive `title` and `annotations` tool keys may differ.
 *
 * Regenerate deliberately with UPDATE_MCP_GOLDEN=1.
 *
 * Phase 3 (agent guidance) rewrote the content of sp_api_list_endpoints,
 * sp_api_get_endpoint and sp_api_get_api_guidance on purpose, so their
 * snapshots were retired; McpEndpointContentTest, McpEndpointContextTest,
 * McpGuidanceReferenceTest and McpModuleRecipesTest pin that content instead.
 * The snapshots that remain guard what phase 3 must not change: the protocol
 * handshake, tool and resource lists, data-tool output and every error shape.
 *
 * @internal
 * @group extraction
 */
class McpExtractionParityTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.enable_tenant_id', true);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('audit.enabled', true);
        $app['config']->set('permissions.enabled', true);
        $app['config']->set('webhooks.enabled', true);
        $app['config']->set('attachments.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 2) . '/config/';
        Config::set('attachments.tables', (require $root . 'sp-attachments.php')['tables']);
        Config::set('webhooks.tables', (require $root . 'sp-webhooks.php')['tables']);

        $stamps = ['created_at' => ['type' => 'datetime', 'nullable' => true], 'updated_at' => ['type' => 'datetime', 'nullable' => true], 'deleted_at' => ['type' => 'datetime', 'nullable' => true]];
        $base = ['id' => ['type' => 'integer', 'nullable' => false], 'tenant_id' => ['type' => 'string', 'nullable' => true]] + $stamps;

        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoice',
                hasTenantId: true,
                softDeletes: true,
                public: new RecordTablePublic(read: true, write: true),
                columns: $base + [
                    'ref_number' => ['type' => 'string', 'nullable' => false],
                    'customer_id' => ['type' => 'bigInteger', 'nullable' => false],
                    'status' => ['type' => 'string', 'nullable' => false],
                    'total' => ['type' => 'decimal', 'nullable' => false],
                    'due_date' => ['type' => 'date', 'nullable' => true],
                    'meta' => ['type' => 'json', 'nullable' => true],
                    'paid' => ['type' => 'boolean', 'nullable' => true],
                ],
                relationships: [
                    'customer' => new RecordBelongsToType(table: 'customers', type: RecordRelationshipsEnum::BELONGS_TO, foreignKey: 'customer_id', ownerKey: 'id'),
                    'items' => new RecordHasManyType(table: 'invoice_items', foreignKey: 'invoice_id', type: RecordRelationshipsEnum::HAS_MANY, localKey: 'id'),
                    'tags' => new RecordMetaBelongsToManyType(related: 'tags', table: 'invoice_tag', foreignPivotKey: 'invoice_id', relatedPivotKey: 'tag_id'),
                ],
            ),
            'customers' => new RecordTableType(table: 'customers', pmsName: 'customer', hasTenantId: true, columns: $base + ['name' => ['type' => 'string', 'nullable' => false]]),
            'invoice_items' => new RecordTableType(table: 'invoice_items', pmsName: 'invoice_item', hasTenantId: true, columns: $base + ['invoice_id' => ['type' => 'bigInteger', 'nullable' => false], 'product' => ['type' => 'string', 'nullable' => false], 'qty' => ['type' => 'integer', 'nullable' => false]]),
            'tags' => new RecordTableType(table: 'tags', pmsName: 'tag', hasTenantId: false, columns: ['id' => ['type' => 'integer', 'nullable' => false], 'name' => ['type' => 'string', 'nullable' => false]]),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /**
     * Every response captured, name => decoded JSON-RPC response.
     *
     * @return array<string, array<string, mixed>>
     */
    private function captures(): array
    {
        $data = new McpServerService();
        $schema = new McpServerService(schemaOnly: true);
        $rpc = static fn(McpServerService $service, string $method, array $params = []): ?array => $service->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        $call = static fn(McpServerService $service, string $tool, array $arguments = []): ?array => $service->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);

        $captures = [
            'initialize' => $rpc($data, 'initialize'),
            'data_tools_list' => $rpc($data, 'tools/list'),
            'schema_tools_list' => $rpc($schema, 'tools/list'),
            'resources_list' => $rpc($data, 'resources/list'),
            'resources_read_invoices' => $rpc($data, 'resources/read', ['uri' => 'schema://invoices']),
            'resources_read_missing' => $rpc($data, 'resources/read', ['uri' => 'schema://nope']),
            'resources_read_bad_uri' => $rpc($data, 'resources/read', ['uri' => 'file://x']),
            'permissions' => $call($data, 'sp_api_list_permissions'),
            'unknown_method' => $rpc($data, 'prompts/list'),
            'unknown_tool' => $call($data, 'nope_tool'),
            'bad_action' => $call($data, 'frobnicate_invoices'),
            'schema_only_rejects_data_tool' => $call($schema, 'list_invoices'),
            'endpoint_missing_arg' => $call($data, 'sp_api_get_endpoint'),
            'endpoint_unknown' => $call($data, 'sp_api_get_endpoint', ['endpoint' => 'nope']),
        ];

        // Read-only mode removes the write tools.
        Config::set('record.mcp.read_only', true);
        $captures['data_tools_list_read_only'] = $rpc($data, 'tools/list');
        $captures['read_only_rejects_create'] = $call($data, 'create_invoices', ['payload' => []]);

        return $captures;
    }

    /**
     * title and annotations are additive (spec §6.1); everything else must match.
     *
     * @param array<string, mixed>|null $response
     * @return array<string, mixed>|null
     */
    private function withoutAdditiveToolKeys(?array $response): ?array
    {
        if (isset($response['result']['tools']) && is_array($response['result']['tools'])) {
            foreach (array_keys($response['result']['tools']) as $i) {
                unset($response['result']['tools'][$i]['title'], $response['result']['tools'][$i]['annotations']);
            }
        }

        return $response;
    }

    /** @test */
    public function every_captured_response_matches_its_golden_file(): void
    {
        $dir = dirname(__DIR__) . '/Fixtures/mcp';

        foreach ($this->captures() as $name => $response) {
            $file = $dir . '/' . $name . '.json';
            $actual = json_encode($this->withoutAdditiveToolKeys($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

            if ('1' === getenv('UPDATE_MCP_GOLDEN')) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0o775, true);
                }

                file_put_contents($file, $actual);

                continue;
            }

            $this->assertFileExists($file, 'Missing golden ' . $name . ' (run once with UPDATE_MCP_GOLDEN=1 before refactoring).');
            $this->assertSame(file_get_contents($file), $actual, 'Output drifted for ' . $name);
        }
    }
}
