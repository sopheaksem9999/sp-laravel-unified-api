<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\CoreSpLaravelApiProvider;
use Sopheak\Core\Services\McpServerService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class McpStructuredOutputTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [CoreSpLaravelApiProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('record.api_prefix', 'api/v1');
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('sp-api-mcp.enabled', true);
        $app['config']->set('sp-api-mcp.token', 'mcp-structured-output-test-token');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('mcp_agent_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference');
            $table->timestamps();
        });

        Config::set('record.tables', [
            'mcp_agent_orders' => new RecordTableType(
                table: 'mcp_agent_orders',
                pmsName: 'mcp_agent_orders',
                hasTenantId: false,
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'bigint', 'nullable' => false],
                    'reference' => ['type' => 'string', 'nullable' => false],
                ],
                functions: [
                    'sync' => new RecordFunctionType(
                        httpMethod: 'POST',
                        class: 'App\\McpAgentOrderFunctions',
                        functionName: 'sync',
                        payloadSchema: [
                            'type' => 'object',
                            'properties' => ['source' => ['type' => 'string']],
                        ],
                        responseSchema: [
                            'type' => 'object',
                            'properties' => ['accepted' => ['type' => 'boolean']],
                        ],
                    ),
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function schema_tools_advertise_structured_output_and_keep_legacy_text_results(): void
    {
        $tools = collect($this->schemaRequest('tools/list')['result']['tools']);
        $guidance = $tools->firstWhere('name', 'sp_api_get_api_guidance');

        $this->assertNotNull($guidance);
        $this->assertSame(['type' => 'object', 'additionalProperties' => false], $guidance['inputSchema']);
        $this->assertSame('object', $guidance['outputSchema']['type']);

        $result = $this->schemaToolCall('sp_api_list_endpoints');

        $this->assertSame(
            json_decode((string) $result['content'][0]['text'], true),
            $result['structuredContent']['endpoints'],
        );
    }

    /** @test */
    public function endpoint_schema_marks_get_as_bodyless_and_describes_its_response(): void
    {
        $result = $this->schemaToolCall('sp_api_get_endpoint', ['endpoint' => 'mcp_agent_orders']);
        $list = $result['structuredContent']['actions']['list'];

        $this->assertNull($list['request']['payload']);
        $this->assertSame('array', $list['response']['dataSchema']['type']);
        $this->assertStringContainsString('query parameters', $list['guidance']);
    }

    /** @test */
    public function schema_guidance_explains_the_two_mcp_endpoints_and_safe_calling_flow(): void
    {
        $guidance = $this->schemaToolCall('sp_api_get_api_guidance')['structuredContent'];

        $this->assertSame('/api/v1/mcp/message', $guidance['dataMcp']['route']);
        $this->assertSame('/api/v1/mcp/schema', $guidance['schemaMcp']['route']);
        $this->assertContains('Call sp_api_list_endpoints to discover an endpoint.', $guidance['workflow']);
        $this->assertStringContainsString('query parameters', $guidance['httpRules']['get']);
        $this->assertArrayHasKey('fieldFiltering', $guidance['querySyntaxExamples']);
        $this->assertArrayHasKey('relationshipSelection', $guidance['querySyntaxExamples']);
        $this->assertArrayHasKey('recordLimiting', $guidance['querySyntaxExamples']['paginationAndSorting']);
        $this->assertTrue($guidance['querySyntaxExamples']['paginationAndSorting']['recordLimiting']['recommendedForAgents']);
        $this->assertArrayHasKey('childCollections', $guidance['nestedWriteExamples']);
        $this->assertArrayHasKey('parentBelongsTo', $guidance['nestedWriteExamples']);
    }

    /** @test */
    public function endpoint_schema_includes_configured_rpc_request_and_response_context(): void
    {
        $endpoint = $this->schemaToolCall('sp_api_get_endpoint', ['endpoint' => 'mcp_agent_orders'])['structuredContent'];
        $sync = collect($endpoint['rpcFunctions'])->firstWhere('name', 'sync');

        $this->assertSame('object', $sync['request']['payload']['type']);
        $this->assertSame('string', $sync['request']['payload']['properties']['source']['type']);
        $this->assertSame('boolean', $sync['response']['dataSchema']['properties']['accepted']['type']);
    }

    /** @test */
    public function endpoint_list_summarizes_bodyless_crud_and_configured_rpc_contracts(): void
    {
        $endpoints = $this->schemaToolCall('sp_api_list_endpoints')['structuredContent']['endpoints'];
        $list = collect($endpoints)->firstWhere('name', 'mcp_agent_orders');
        $sync = collect($endpoints)->firstWhere('name', 'mcp_agent_orders.sync');

        $this->assertNull($list['request']['payload']);
        $this->assertStringContainsString('response', $list['response']['summary']);
        $this->assertSame('string', $sync['request']['payload']['properties']['source']['type']);
        $this->assertSame('boolean', $sync['response']['dataSchema']['properties']['accepted']['type']);
    }

    /** @test */
    public function data_mcp_tools_advertise_and_return_standard_structured_output(): void
    {
        $server = app(McpServerService::class);
        $tools = collect($server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ])['result']['tools']);
        $listOrders = $tools->firstWhere('name', 'list_mcp_agent_orders');

        $this->assertNotNull($listOrders);
        $this->assertSame('object', $listOrders['outputSchema']['type']);

        $result = $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'list_mcp_agent_orders', 'arguments' => []],
        ])['result'];

        $legacyResponse = json_decode((string) $result['content'][0]['text'], true);

        $this->assertArrayNotHasKey('request', $legacyResponse);
        $this->assertArrayNotHasKey('request', $result['structuredContent']['response']);
        $this->assertSame($legacyResponse, $result['structuredContent']['response']);
    }

    /** @return array<string, mixed> */
    private function schemaRequest(string $method, array $params = []): array
    {
        $response = $this->withToken('mcp-structured-output-test-token')->postJson('/api/v1/mcp/schema', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);

        $response->assertOk();

        return $response->json();
    }

    /** @return array<string, mixed> */
    private function schemaToolCall(string $name, array $arguments = []): array
    {
        return $this->schemaRequest('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ])['result'];
    }
}
