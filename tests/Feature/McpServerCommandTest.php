<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\CoreSpLaravelApiProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Console\McpServerCommand;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Support\CacheRequestContext;
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

    /**
     * The stdio loop is a single, long-running process: it never fires
     * RouteMatched and is never touched by QueueServiceProvider's
     * forgetScopedInstances(), so without a reset inside the loop itself the
     * scoped CacheRequestContext would survive for the whole process and keep
     * serving a namespace version another process has already moved past.
     *
     * This drives two JSON-RPC "list" requests through ONE artisan()
     * invocation and, between them, bumps the cache-store namespace version
     * DIRECTLY (never through invalidateTableForTenant(), which would call
     * memoizeNamespaceVersion() and hand this process the new version for
     * free -- exactly the false-pass that round 1 of this task caught). A
     * custom stream wrapper on stdout runs that out-of-band mutation the
     * moment the first response has been fully written, i.e. exactly between
     * the loop's two iterations.
     */
    /**
     * Guards the per-iteration CacheRequestContext reset in McpServerCommand's
     * stdio loop.
     *
     * This asserts the leak MECHANISM directly rather than driving two JSON-RPC
     * requests through artisan(). An earlier loop-level version of this test was
     * inert -- it passed with the reset removed -- so it was replaced rather than
     * left standing as false assurance. See the SDD ledger for Task 8.
     *
     * executeGetByFilter() is the exact call the MCP `list_*` tool makes
     * (McpServerService.php:402), so a memo that survives between iterations is
     * what makes the loop serve stale reads.
     *
     * @test
     */
    public function the_namespace_memo_leaks_across_calls_unless_the_context_is_reset(): void
    {
        $namespaceKey = 'sp_laravel_api:ns:table:mcp_cli_tasks:tenant:disabled';

        DB::table('mcp_cli_tasks')->insert([
            'title' => 'Task A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $first = RecordService::executeGetByFilter('mcp_cli_tasks');
        $this->assertCount(1, $first['data'], 'precondition: the first read sees only Task A');

        DB::table('mcp_cli_tasks')->insert([
            'title' => 'Task B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Simulate ANOTHER PROCESS invalidating: bump the store and never touch
        // this process's memo. Going through invalidateTableForTenant() would
        // memoize the new version here and make this test inert.
        Cache::add($namespaceKey, 1, 315360000);
        Cache::increment($namespaceKey);

        // Without a reset the memo still holds the pre-bump version, so the
        // second read resolves the old token and is served the stale entry.
        $stale = RecordService::executeGetByFilter('mcp_cli_tasks');
        $this->assertCount(
            1,
            $stale['data'],
            'the memo must still be leaking here -- if this sees 2 rows the test no '
            . 'longer reproduces the condition the reset exists to fix'
        );

        // This is what McpServerCommand does at the top of every loop iteration.
        app(CacheRequestContext::class)->reset();

        $fresh = RecordService::executeGetByFilter('mcp_cli_tasks');
        $this->assertCount(
            2,
            $fresh['data'],
            'after the reset the namespace version must be re-read from the store, '
            . 'so the second JSON-RPC request in an MCP process sees Task B'
        );
    }
}
