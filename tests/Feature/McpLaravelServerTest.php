<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Mcp\Servers\DataServer;
use Sopheak\Core\Mcp\Servers\SchemaServer;
use Sopheak\Core\Tests\Support\ArrayMcpTransport;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The `laravel` driver's servers, driven over an in-memory transport.
 *
 * @internal
 */
class McpLaravelServerTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $granted = [];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('record.mcp.driver', 'laravel');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        DB::table('widgets')->insert(['id' => 1, 'name' => 'ONE']);

        $columns = [
            'id' => ['type' => 'integer', 'nullable' => false],
            'name' => ['type' => 'string', 'nullable' => true],
            'created_at' => ['type' => 'datetime', 'nullable' => true],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ];
        config(['record.tables' => ['widgets' => new RecordTableType(table: 'widgets', pmsName: 'widget', hasTenantId: false, softDeletes: false, columns: $columns)]]);
        SchemaRegistryUtils::refresh();

        Gate::before(fn($user, string $ability): ?bool => in_array($ability, $this->granted, true) ? true : null);
        $this->actingAs(new GenericUser(['id' => 5, 'name' => 'u']), 'api');
    }

    private function server(string $class = DataServer::class): array
    {
        $transport = new ArrayMcpTransport();
        $server = new $class($transport);
        $server->start();

        return [$server, $transport];
    }

    /**
     * @param array<array<string, mixed>, mixed> $arguments
     */
    private function callTool(ArrayMcpTransport $transport, string $tool, array $arguments = [], int $id = 1): array
    {
        return $transport->feed(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
    }

    /**
     * A long-lived stdio process gets neither RouteMatched nor a queue worker's
     * forgetScopedInstances(), so the cache namespace memo would live as long as
     * the process and keep serving reads another process has invalidated. The
     * legacy loop resets it per message; so must the laravel server.
     *
     * @test
     */
    public function every_message_starts_with_a_fresh_cache_context(): void
    {
        config(['permissions.super_admin_callback' => static fn (): bool => true]);
        Schema::create('cached_things', function (Blueprint $t): void {
            $t->id();
            $t->string('title')->nullable();
            $t->timestamps();
        });
        config(['record.tables' => ['cached_things' => new RecordTableType(
            table: 'cached_things',
            pmsName: 'cached_things',
            disableCache: false,
            columns: ['id' => ['type' => 'integer', 'nullable' => false], 'title' => ['type' => 'string', 'nullable' => true]],
        )]]);
        SchemaRegistryUtils::refresh();
        DB::table('cached_things')->insert(['id' => 1, 'title' => 'A']);

        [, $transport] = $this->server();
        $rows = static fn (array $response): int => count($response['result']['structuredContent']['response']['data'] ?? []);

        $this->assertSame(1, $rows($this->callTool($transport, 'list_cached_things')), 'precondition: the first read sees one row');

        // Another process writes and invalidates: bump the shared namespace version
        // without touching this process's memo.
        DB::table('cached_things')->insert(['id' => 2, 'title' => 'B']);
        Cache::add('sp_laravel_api:ns:table:cached_things:tenant:disabled', 1, 315360000);
        Cache::increment('sp_laravel_api:ns:table:cached_things:tenant:disabled');

        $this->assertSame(2, $rows($this->callTool($transport, 'list_cached_things', [], 2)), 'the second message must not reuse the first message\'s memo');
    }

    /** @test */
    public function initialize_negotiates_the_protocol_version_and_names_the_server(): void
    {
        [, $transport] = $this->server();

        $response = $transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);

        $this->assertSame('2025-06-18', $response['result']['protocolVersion']);
        $this->assertSame('sp-laravel-api-mcp', $response['result']['serverInfo']['name']);
        $this->assertNotEmpty($response['result']['instructions']);
    }

    /** @test */
    public function tools_list_publishes_the_catalog_with_titles_annotations_and_union_types(): void
    {
        // tools/list hides tools the caller cannot use; a super admin sees the whole catalog.
        config(['permissions.super_admin_callback' => static fn (): bool => true]);
        [, $transport] = $this->server();

        $tools = $transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'];
        $byName = array_column($tools, null, 'name');

        $this->assertArrayHasKey('sp_api_get_endpoint', $byName);
        $this->assertSame('Delete widgets', $byName['delete_widgets']['title']);
        $this->assertTrue($byName['delete_widgets']['annotations']['destructiveHint']);
        $this->assertSame(['string', 'integer'], $byName['read_widgets']['inputSchema']['properties']['id']['type']);
        $this->assertArrayHasKey('outputSchema', $byName['list_widgets']);
    }

    /** @test */
    public function tools_list_returns_the_whole_catalog_in_one_page(): void
    {
        // tools/list hides tools the caller cannot use; a super admin sees the whole catalog.
        config(['permissions.super_admin_callback' => static fn (): bool => true]);
        [, $transport] = $this->server();

        $result = $transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result'];

        // More than laravel/mcp's default page of 15 (4 schema tools + the built-in
        // permission tables + widgets), and still a single page.
        $this->assertGreaterThan(15, count($result['tools']));
        $this->assertArrayNotHasKey('nextCursor', $result);
    }

    /** @test */
    public function the_schema_server_lists_only_the_four_schema_tools(): void
    {
        [, $transport] = $this->server(SchemaServer::class);

        $names = array_column($transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'], 'name');

        $this->assertSame(['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions', 'sp_api_get_api_guidance'], $names);
    }

    /** @test */
    public function a_permitted_call_returns_structured_content_and_the_legacy_text_copy(): void
    {
        $this->granted = ['view:widget'];
        [, $transport] = $this->server();

        $result = $this->callTool($transport, 'list_widgets', ['queryParams' => ['limit' => 5]])['result'];

        $this->assertArrayHasKey('response', $result['structuredContent']);
        $this->assertSame('text', $result['content'][0]['type']);
    }

    /** @test */
    public function a_forbidden_call_is_a_json_rpc_error_with_the_original_code(): void
    {
        [, $transport] = $this->server();

        $response = $this->callTool($transport, 'list_widgets');

        $this->assertSame(-32002, $response['error']['code']);
        $this->assertSame('Forbidden', $response['error']['message']);
        $this->assertArrayNotHasKey('result', $response);
    }

    /** @test */
    public function an_unauthenticated_call_is_minus_32001(): void
    {
        auth('api')->forgetUser();
        [, $transport] = $this->server();

        $this->assertSame(-32001, $this->callTool($transport, 'list_widgets')['error']['code']);
    }

    /** @test */
    public function an_unknown_tool_is_minus_32601_with_the_legacy_message(): void
    {
        [, $transport] = $this->server();

        $response = $this->callTool($transport, 'frobnicate_widgets');

        $this->assertSame(-32601, $response['error']['code']);
        $this->assertSame('Tool not found: frobnicate_widgets', $response['error']['message']);
    }

    /** @test */
    public function read_only_mode_refuses_a_write_tool_with_the_legacy_message(): void
    {
        config(['record.mcp.read_only' => true]);
        [, $transport] = $this->server();

        $response = $this->callTool($transport, 'create_widgets', ['payload' => ['name' => 'x']]);

        $this->assertSame(-32601, $response['error']['code']);
        $this->assertSame('Tool not found or read-only mode is enabled: create_widgets', $response['error']['message']);
    }

    /** @test */
    public function a_record_failure_is_an_is_error_result_with_the_real_message(): void
    {
        $this->granted = ['create:widget'];
        [, $transport] = $this->server();

        $result = $this->callTool($transport, 'create_widgets', ['payload' => ['not_a_column' => 'x']])['result'];

        $this->assertTrue($result['isError']);
        $this->assertStringNotContainsString('internal server error', $result['content'][0]['text']);
    }

    /** @test */
    public function the_schema_server_refuses_a_data_tool_as_not_found(): void
    {
        $this->granted = ['view:widget'];
        [, $transport] = $this->server(SchemaServer::class);

        $this->assertSame(-32601, $this->callTool($transport, 'list_widgets')['error']['code']);
    }

    /** @test */
    public function resources_list_and_read_the_table_schemas(): void
    {
        [, $transport] = $this->server();

        $uris = array_column($transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'])['result']['resources'], 'uri');
        $this->assertContains('schema://widgets', $uris);

        $read = $transport->feed(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'schema://widgets']]);
        $this->assertStringContainsString('"table": "widgets"', $read['result']['contents'][0]['text']);
    }

    /** @test */
    public function ping_answers(): void
    {
        [, $transport] = $this->server();

        $this->assertArrayHasKey('result', $transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
    }

    /** @test */
    public function the_request_tenant_scopes_tool_calls_over_a_non_http_transport(): void
    {
        // stdio has no HTTP request: the operator binds the tenant on the console
        // request (McpServerCommand --tenant) and ToolExecutor reads it from there.
        config(['record.enable_tenant_id' => true]);
        Schema::table('widgets', function (Blueprint $t): void {
            $t->string('tenant_id')->nullable();
        });
        DB::table('widgets')->where('id', 1)->update(['tenant_id' => 't1']);
        DB::table('widgets')->insert(['id' => 2, 'name' => 'TWO', 'tenant_id' => 't2']);
        $columns = [
            'id' => ['type' => 'integer', 'nullable' => false],
            'name' => ['type' => 'string', 'nullable' => true],
            'tenant_id' => ['type' => 'string', 'nullable' => true],
            'created_at' => ['type' => 'datetime', 'nullable' => true],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ];
        config(['record.tables' => ['widgets' => new RecordTableType(table: 'widgets', pmsName: 'widget', hasTenantId: true, softDeletes: false, columns: $columns)]]);
        SchemaRegistryUtils::refresh();
        $this->granted = ['view:widget'];

        $names = static fn (array $response): array => array_column($response['result']['structuredContent']['response']['data'] ?? [], 'name');

        request()->attributes->set('resolved_tenant_id', 't1');
        [, $transport] = $this->server();
        $this->assertSame(['ONE'], $names($this->callTool($transport, 'list_widgets')));

        request()->attributes->set('resolved_tenant_id', 't2');
        [, $other] = $this->server();
        $this->assertSame(['TWO'], $names($this->callTool($other, 'list_widgets')));
    }

    /** @test */
    public function a_tenant_scoped_table_fails_closed_without_a_tenant(): void
    {
        config(['record.enable_tenant_id' => true]);
        $columns = [
            'id' => ['type' => 'integer', 'nullable' => false],
            'name' => ['type' => 'string', 'nullable' => true],
            'tenant_id' => ['type' => 'string', 'nullable' => true],
        ];
        config(['record.tables' => ['widgets' => new RecordTableType(table: 'widgets', pmsName: 'widget', hasTenantId: true, softDeletes: false, columns: $columns)]]);
        SchemaRegistryUtils::refresh();
        $this->granted = ['view:widget'];
        request()->attributes->remove('resolved_tenant_id');
        [, $transport] = $this->server();

        $response = $this->callTool($transport, 'list_widgets');

        $this->assertSame(-32001, $response['error']['code']);
        $this->assertStringContainsString('Tenant context is required for widgets', $response['error']['message']);
    }

    /** @test */
    public function a_tenant_argument_from_the_model_is_refused_when_it_disagrees(): void
    {
        config(['record.enable_tenant_id' => true]);
        $columns = ['id' => ['type' => 'integer', 'nullable' => false], 'tenant_id' => ['type' => 'string', 'nullable' => true]];
        config(['record.tables' => ['widgets' => new RecordTableType(table: 'widgets', pmsName: 'widget', hasTenantId: true, softDeletes: false, columns: $columns)]]);
        SchemaRegistryUtils::refresh();
        $this->granted = ['view:widget'];
        request()->attributes->set('resolved_tenant_id', 't1');
        [, $transport] = $this->server();

        $response = $this->callTool($transport, 'list_widgets', ['tenantId' => 't2']);

        $this->assertSame(-32001, $response['error']['code']);
    }
}
