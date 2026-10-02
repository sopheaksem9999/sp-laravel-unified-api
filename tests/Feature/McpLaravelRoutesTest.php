<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Laravel\Mcp\Server\McpServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Sopheak\Core\Tests\TestCase;

/**
 * The `laravel` driver's HTTP surface. Existing URLs keep their paths; the
 * Streamable HTTP endpoint is added at POST /api/mcp.
 *
 * @internal
 */
class McpLaravelRoutesTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.driver', 'laravel');
        $app['config']->set('record.mcp.middleware', ['api']);
        $app['config']->set('sp-api-mcp.enabled', true);
        $app['config']->set('sp-api-mcp.token', 'secret');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'u']), 'api');
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $params
     */
    private function rpc(string $uri, string $method, array $params = [], array $headers = []): TestResponse
    {
        return $this->postJson($uri, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $headers);
    }

    /** @test */
    public function the_existing_message_url_serves_plain_json_with_a_negotiated_protocol_version(): void
    {
        $response = $this->rpc('/api/mcp/message', 'initialize', ['protocolVersion' => '2025-06-18']);

        $response->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJsonPath('result.protocolVersion', '2025-06-18');
    }

    /** @test */
    public function the_new_streamable_http_endpoint_answers(): void
    {
        $this->rpc('/api/mcp', 'initialize', ['protocolVersion' => '2025-06-18'])->assertOk()->assertJsonPath('result.serverInfo.name', 'sp-laravel-api-mcp');
    }

    /** @test */
    public function ping_answers_with_a_result(): void
    {
        $this->assertArrayHasKey('result', $this->rpc('/api/mcp/message', 'ping')->json());
    }

    /** @test */
    public function a_notification_is_accepted_with_202(): void
    {
        $this->postJson('/api/mcp/message', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
    }

    /** @test */
    public function get_and_delete_are_method_not_allowed_with_an_allow_header(): void
    {
        foreach (['/api/mcp', '/api/mcp/message', '/api/mcp/schema', '/api/mcp/sse'] as $uri) {
            $this->get($uri)->assertStatus(405)->assertHeader('Allow', 'POST');
        }

        $this->deleteJson('/api/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
    }

    /** @test */
    public function the_schema_endpoint_rejects_a_missing_token(): void
    {
        $this->rpc('/api/mcp/schema', 'tools/list')
            ->assertStatus(401)
            ->assertJsonPath('error.message', 'Invalid MCP token');
    }

    /** @test */
    public function the_schema_endpoint_with_the_token_lists_exactly_the_four_schema_tools(): void
    {
        $names = array_column($this->rpc('/api/mcp/schema', 'tools/list', [], ['Authorization' => 'Bearer secret'])->json('result.tools'), 'name');

        $this->assertSame(['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions', 'sp_api_get_api_guidance'], $names);
    }

    /** @test */
    public function the_route_names_resolve_to_the_documented_urls(): void
    {
        $this->assertSame('/api/mcp/message', route('mcp.message', [], false));
        $this->assertSame('/api/mcp/schema', route('api_schema_mcp', [], false));
        $this->assertTrue(Route::has('mcp.sse'));
    }

    /** @test */
    public function the_package_registers_laravel_mcps_provider_for_the_laravel_driver(): void
    {
        // Without it a tool's arguments are empty and a 401 carries no OAuth hint.
        $this->assertNotNull($this->app->getProvider(McpServiceProvider::class));
        $this->assertTrue($this->app->bound('mcp.sdk'));
    }
}
