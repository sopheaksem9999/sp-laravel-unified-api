<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\CoreSpLaravelApiProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Console\McpServerCommand;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class McpServerCommandTest extends TestCase
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

        Schema::create('mcp_cli_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Config::set('record.tables', [
            'mcp_cli_tasks' => new RecordTableType(
                table: 'mcp_cli_tasks',
                pmsName: 'mcp_cli_tasks',
                hasTenantId: false,
                public: new RecordTablePublic(read: true, write: true)
            ),
        ]);

        Config::set('record.mcp.enabled', true);
        Config::set('record.mcp.read_only', false);

        SchemaRegistryUtils::refresh();
    }

    protected function tearDown(): void
    {
        McpServerCommand::$stdinMock = null;
        McpServerCommand::$stdoutMock = null;
        parent::tearDown();
    }

    /** @test */
    public function it_processes_valid_json_rpc_requests(): void
    {
        $stdin = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]) . "\n";

        fwrite($stdin, $payload);
        rewind($stdin);

        McpServerCommand::$stdinMock = $stdin;
        McpServerCommand::$stdoutMock = $stdout;

        $this->artisan('sp-laravel-api:mcp')->assertSuccessful();

        rewind($stdout);
        $output = stream_get_contents($stdout);

        $this->assertStringContainsString('"protocolVersion":"2024-11-05"', $output);
        $this->assertStringContainsString('"serverInfo"', $output);
    }

    /** @test */
    public function it_handles_invalid_json(): void
    {
        $stdin = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');

        $payload = "invalid-json\n";

        fwrite($stdin, $payload);
        rewind($stdin);

        McpServerCommand::$stdinMock = $stdin;
        McpServerCommand::$stdoutMock = $stdout;

        $this->artisan('sp-laravel-api:mcp')->assertSuccessful();

        rewind($stdout);
        $output = stream_get_contents($stdout);

        $this->assertStringContainsString('"error":{"code":-32700,"message":"Parse error"}', $output);
    }

    /** @test */
    public function it_secures_access_by_requiring_auth_for_write_tools(): void
    {
        // In STDIO mode, there is no HTTP request, so auth()->user() is null.
        // Therefore, any tool that requires authentication should fail with an Unauthenticated exception.
        // We ensure `public` write is false so auth is required.
        Config::set('record.tables.mcp_cli_tasks', new RecordTableType(
            table: 'mcp_cli_tasks',
            pmsName: 'mcp_cli_tasks',
            hasTenantId: false,
            public: new RecordTablePublic(read: false, write: false)
        ));
        SchemaRegistryUtils::refresh();

        $stdin = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');

        // Trying to read list (auth required now)
        $payload1 = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_mcp_cli_tasks',
                'arguments' => [],
            ],
        ]) . "\n";

        fwrite($stdin, $payload1);
        rewind($stdin);

        McpServerCommand::$stdinMock = $stdin;
        McpServerCommand::$stdoutMock = $stdout;

        $this->artisan('sp-laravel-api:mcp')->assertSuccessful();

        rewind($stdout);
        $output = stream_get_contents($stdout);

        // It should contain the unauthenticated error from McpServerService
        $this->assertStringContainsString('Unauthenticated', $output);
        $this->assertStringContainsString('"error":{"code":-32001', $output);
    }
}
