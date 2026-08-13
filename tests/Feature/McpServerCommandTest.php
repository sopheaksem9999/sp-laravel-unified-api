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
    /** @test */
    public function it_resets_the_cache_context_between_json_rpc_requests_in_the_stdio_loop(): void
    {
        DB::table('mcp_cli_tasks')->insert([
            'title' => 'Task A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        stream_wrapper_register('mcp-between-iterations', McpBetweenIterationsStreamWrapper::class);
        McpBetweenIterationsStreamWrapper::reset();
        McpBetweenIterationsStreamWrapper::$onFirstResponseWritten = function (): void {
            DB::table('mcp_cli_tasks')->insert([
                'title' => 'Task B',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Simulate ANOTHER PROCESS bumping the namespace: write the store
            // directly and never touch this process's memo.
            $namespaceKey = 'sp_laravel_api:ns:table:mcp_cli_tasks:tenant:disabled';
            Cache::add($namespaceKey, 1, 315360000);
            Cache::increment($namespaceKey);
        };

        try {
            $stdin = fopen('php://memory', 'r+');

            $listCall = static fn (int $id): string => json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'list_mcp_cli_tasks',
                    'arguments' => [],
                ],
            ]) . "\n";

            fwrite($stdin, $listCall(1));
            fwrite($stdin, $listCall(2));
            rewind($stdin);

            $stdout = fopen('mcp-between-iterations://output', 'w');

            McpServerCommand::$stdinMock = $stdin;
            McpServerCommand::$stdoutMock = $stdout;

            $this->artisan('sp-laravel-api:mcp')->assertSuccessful();

            $output = McpBetweenIterationsStreamWrapper::output();
        } finally {
            stream_wrapper_unregister('mcp-between-iterations');
        }

        // Request 1 ran before "Task B" existed, so "Task B" can only appear
        // in request 2's response -- and only if request 2 re-read the
        // namespace version from the store instead of serving the memoized
        // one from request 1.
        $this->assertStringContainsString(
            'Task B',
            $output,
            'The second JSON-RPC request in the same MCP process must observe '
            . 'data written after the first request. If this fails, the '
            . 'per-iteration CacheRequestContext reset is not taking effect and '
            . 'the stdio loop is serving a memoized, stale namespace version.'
        );
    }
}

/**
 * Stream wrapper used only by
 * it_resets_the_cache_context_between_json_rpc_requests_in_the_stdio_loop().
 *
 * Intercepts McpServerCommand's writes to its stdout mock so a callback can
 * run the instant the FIRST JSON-RPC response has been fully written -- the
 * exact point between two iterations of the command's stdin loop, which is
 * otherwise inaccessible from outside a single artisan() invocation.
 */
class McpBetweenIterationsStreamWrapper
{
    /** @var resource|null */
    public $context;

    private static string $buffer = '';

    private static bool $triggered = false;

    /** @var (callable(): void)|null */
    public static $onFirstResponseWritten;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        self::$buffer .= $data;

        if (!self::$triggered && str_contains($data, '"id":1,"result"')) {
            self::$triggered = true;
            if (null !== self::$onFirstResponseWritten) {
                (self::$onFirstResponseWritten)();
            }
        }

        return strlen($data);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public static function reset(): void
    {
        self::$buffer = '';
        self::$triggered = false;
        self::$onFirstResponseWritten = null;
    }

    public static function output(): string
    {
        return self::$buffer;
    }
}
