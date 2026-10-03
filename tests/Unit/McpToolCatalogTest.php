<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Mcp\ToolCatalog;
use Sopheak\Core\Mcp\ToolDefinition;
use Sopheak\Core\Services\McpServerService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The transport-free tool catalog: which MCP tools exist, with the titles and
 * annotations (spec §6.1) both drivers publish.
 *
 * @internal
 */
class McpToolCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $columns = ['id' => ['type' => 'integer', 'nullable' => false], 'name' => ['type' => 'string', 'nullable' => true]];
        Config::set('record.tables', [
            'widgets' => new RecordTableType(table: 'widgets', pmsName: 'widget', hasTenantId: false, columns: $columns),
            'gadgets' => new RecordTableType(table: 'gadgets', pmsName: 'gadget', hasTenantId: false, columns: $columns),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /**
     * @param array<int, ToolDefinition> $tools
     * @return array<string, ToolDefinition>
     */
    private function byName(array $tools): array
    {
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        return $byName;
    }

    /**
     * Names of the data tools for the fixture's own tables (the registry also
     * holds the built-in permission tables).
     *
     * @param array<int, ToolDefinition> $tools
     * @return array<int, string>
     */
    private function fixtureToolNames(array $tools): array
    {
        return array_values(array_map(
            static fn(ToolDefinition $tool): string => $tool->name,
            array_filter($tools, static fn(ToolDefinition $tool): bool => in_array($tool->table, ['widgets', 'gadgets'], true))
        ));
    }

    /** @test */
    public function schema_tools_are_the_four_discovery_tools_marked_read_only(): void
    {
        $tools = (new ToolCatalog())->schema();

        $this->assertSame(
            ['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions', 'sp_api_get_api_guidance'],
            array_map(static fn(ToolDefinition $tool): string => $tool->name, $tools)
        );

        foreach ($tools as $tool) {
            $this->assertSame('schema', $tool->action);
            $this->assertNull($tool->table);
            $this->assertSame(['readOnlyHint' => true, 'openWorldHint' => false], $tool->annotations);
            $this->assertNotSame('', (string) $tool->title);
        }
    }

    /** @test */
    public function data_tools_carry_the_annotations_from_the_spec_table(): void
    {
        config(['record.mcp.read_only' => false]);
        $byName = $this->byName((new ToolCatalog())->data());

        $readOnly = ['readOnlyHint' => true, 'openWorldHint' => false];
        $this->assertSame($readOnly, $byName['list_widgets']->annotations);
        $this->assertSame($readOnly, $byName['read_widgets']->annotations);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false], $byName['create_widgets']->annotations);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false], $byName['update_widgets']->annotations);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false], $byName['delete_widgets']->annotations);

        $this->assertSame('Delete widgets', $byName['delete_widgets']->title);
        $this->assertSame('List gadgets', $byName['list_gadgets']->title);
        $this->assertSame('widgets', $byName['delete_widgets']->table);
        $this->assertSame('delete', $byName['delete_widgets']->action);
    }

    /** @test */
    public function a_caller_can_list_the_write_tools_even_when_mcp_is_read_only(): void
    {
        config(['record.mcp.read_only' => true]);
        $catalog = new ToolCatalog();

        $this->assertNotContains('create_widgets', array_map(static fn(ToolDefinition $tool): string => $tool->name, $catalog->data()));

        $names = array_map(static fn(ToolDefinition $tool): string => $tool->name, $catalog->data(readOnly: false));
        foreach (['list_widgets', 'read_widgets', 'create_widgets', 'update_widgets', 'delete_widgets'] as $name) {
            $this->assertContains($name, $names, $name);
        }
    }

    /** @test */
    public function read_only_mode_leaves_only_list_and_read(): void
    {
        config(['record.mcp.read_only' => true]);

        $this->assertSame(
            ['list_widgets', 'read_widgets', 'list_gadgets', 'read_gadgets'],
            $this->fixtureToolNames((new ToolCatalog())->data())
        );
        $this->assertTrue((new ToolCatalog())->isReadOnly());
    }

    /** @test */
    public function schema_only_lists_no_data_tools(): void
    {
        config(['record.mcp.read_only' => false]);

        $this->assertSame(
            ['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions', 'sp_api_get_api_guidance'],
            array_map(static fn(ToolDefinition $tool): string => $tool->name, (new ToolCatalog())->tools(schemaOnly: true))
        );
    }

    /** @test */
    public function full_mode_lists_the_schema_tools_first_then_the_data_tools(): void
    {
        config(['record.mcp.read_only' => false]);

        $tools = (new ToolCatalog())->tools(schemaOnly: false);
        $names = array_map(static fn(ToolDefinition $tool): string => $tool->name, $tools);

        $this->assertSame(
            ['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions', 'sp_api_get_api_guidance'],
            array_slice($names, 0, 4)
        );
        $this->assertSame(
            ['list_widgets', 'read_widgets', 'create_widgets', 'update_widgets', 'delete_widgets', 'list_gadgets', 'read_gadgets', 'create_gadgets', 'update_gadgets', 'delete_gadgets'],
            $this->fixtureToolNames($tools)
        );
    }

    /** @test */
    public function resources_describe_every_table_schema(): void
    {
        $resources = (new ToolCatalog())->resources();

        $this->assertContains('schema://widgets', array_column($resources, 'uri'));
        $this->assertSame('application/json', $resources[0]['mimeType']);
    }

    /** @test */
    public function the_json_rpc_adapter_publishes_titles_and_annotations_on_the_wire(): void
    {
        config(['record.mcp.read_only' => false]);

        $response = (new McpServerService())->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $byName = array_column($response['result']['tools'], null, 'name');

        $this->assertSame('Delete widgets', $byName['delete_widgets']['title']);
        $this->assertTrue($byName['delete_widgets']['annotations']['destructiveHint']);
        $this->assertTrue($byName['list_widgets']['annotations']['readOnlyHint']);
        $this->assertSame(['name', 'description', 'inputSchema', 'outputSchema', 'title', 'annotations'], array_keys($byName['list_widgets']));
    }
}
