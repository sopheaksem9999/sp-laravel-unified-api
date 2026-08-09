<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\CoreSpLaravelApiProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class McpHttpControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

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
        config(['record.mcp.enabled' => true]);

        Schema::create('mcp_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Config::set('record.tables', [
            'mcp_tasks' => new RecordTableType(
                table: 'mcp_tasks',
                pmsName: 'mcp_tasks',
                hasTenantId: false,
                public: new RecordTablePublic(read: true, write: true)
            ),
        ]);

        Config::set('record.mcp.enabled', true);
        Config::set('record.mcp.read_only', false);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function it_can_handle_sse_connection(): void
    {
        $response = $this->get('/api/mcp/sse');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        // SSE streamed response doesn't immediately return content in a normal way
        // We can just verify the status and headers for now
    }

    /** @test */
    public function it_can_handle_initialize_request(): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ];

        $response = $this->postJson('/api/mcp/message', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                ],
            ]);
    }

    /** @test */
    public function it_can_list_tools(): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ];

        $response = $this->postJson('/api/mcp/message', $payload);

        $response->assertStatus(200);

        $tools = $response->json('result.tools');

        $this->assertIsArray($tools);
        $toolNames = collect($tools)->pluck('name')->toArray();

        $this->assertContains('list_mcp_tasks', $toolNames);
        $this->assertContains('read_mcp_tasks', $toolNames);
        $this->assertContains('create_mcp_tasks', $toolNames);
        $this->assertContains('update_mcp_tasks', $toolNames);
        $this->assertContains('delete_mcp_tasks', $toolNames);
    }

    /** @test */
    public function it_can_call_list_tool(): void
    {
        DB::table('mcp_tasks')->insert([
            'title' => 'Test Task',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_mcp_tasks',
                'arguments' => [],
            ],
        ];

        $response = $this->postJson('/api/mcp/message', $payload);

        $response->assertStatus(200);

        $result = $response->json('result');
        $this->assertIsArray($result['content']);

        $content = json_decode((string) $result['content'][0]['text'], true);
        $this->assertArrayHasKey('data', $content);
        $this->assertCount(1, $content['data']);
        $this->assertEquals('Test Task', $content['data'][0]['title']);
    }

    /** @test */
    public function it_respects_read_only_config(): void
    {
        Config::set('record.mcp.read_only', true);

        // list tools should only have read/list
        $listPayload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ];

        $listResponse = $this->postJson('/api/mcp/message', $listPayload);
        $tools = $listResponse->json('result.tools');
        $toolNames = collect($tools)->pluck('name')->toArray();

        $this->assertContains('list_mcp_tasks', $toolNames);
        $this->assertContains('read_mcp_tasks', $toolNames);
        $this->assertNotContains('create_mcp_tasks', $toolNames);

        // calling create should fail
        $createPayload = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_mcp_tasks',
                'arguments' => [
                    'payload' => [
                        'title' => 'New Task',
                    ],
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/mcp/message', $createPayload);

        $createResponse->assertStatus(200);
        $this->assertArrayHasKey('error', $createResponse->json());
        $this->assertEquals(-32601, $createResponse->json('error.code'));
    }

    /** @test */
    public function it_can_call_create_tool(): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_mcp_tasks',
                'arguments' => [
                    'payload' => [
                        'title' => 'New Task from MCP',
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/mcp/message', $payload);

        $response->assertStatus(200);

        $result = $response->json('result');
        $content = json_decode((string) $result['content'][0]['text'], true);

        $this->assertEquals('New Task from MCP', $content['data']['title']);
        $this->assertDatabaseHas('mcp_tasks', [
            'title' => 'New Task from MCP',
        ]);
    }

    /** @test */
    public function it_reports_unknown_payload_field_as_a_tool_error_not_a_silent_drop(): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_mcp_tasks',
                'arguments' => [
                    'payload' => [
                        'title' => 'New Task from MCP',
                        'bogus_field' => 'x',
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/mcp/message', $payload);

        $response->assertStatus(200);

        $result = $response->json('result');
        $this->assertTrue($result['isError'] ?? false);
        $this->assertStringContainsString("Unknown field 'bogus_field'", $result['content'][0]['text']);
        $this->assertDatabaseMissing('mcp_tasks', ['title' => 'New Task from MCP']);
    }
}
