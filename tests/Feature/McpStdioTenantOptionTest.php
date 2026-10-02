<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Console\McpServerCommand;
use Sopheak\Core\CoreSpLaravelApiProvider;
use Sopheak\Core\Tests\Concerns\SkipsOnLaravelMcpDriver;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The stdio server runs the full (non-schemaOnly) service, so it exposes data
 * tools — but a console process has no request, and therefore no tenant header.
 * Once the tenant stopped coming from the tool arguments, every tenant-scoped
 * table became unreachable over stdio with no replacement. `--tenant` is that
 * replacement: explicit, and still fail-closed when omitted.
 *
 * @internal
 */
class McpStdioTenantOptionTest extends TestCase
{
    use SkipsOnLaravelMcpDriver;

    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [CoreSpLaravelApiProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('record.enable_tenant_id', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipOnLaravelMcpDriver('the legacy STDIN/STDOUT loop mocks; the laravel driver is covered by McpLaravelStdioTest and McpLaravelServerTest::tenant cases');

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
        });

        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'ACME-SECRET', 'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'GLOBEX-SECRET', 'tenant_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Config::set('record.tables', ['widgets' => new RecordTableType(
            table: 'widgets',
            pmsName: 'widget',
            hasTenantId: true,
            public: new RecordTablePublic(read: true, write: true),
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'name' => ['type' => 'string', 'nullable' => false],
                'tenant_id' => ['type' => 'bigInteger', 'nullable' => true],
                'created_at' => ['type' => 'datetime', 'nullable' => true],
                'updated_at' => ['type' => 'datetime', 'nullable' => true],
            ],
        )]);

        SchemaRegistryUtils::refresh();
    }

    protected function tearDown(): void
    {
        McpServerCommand::$stdinMock = null;
        McpServerCommand::$stdoutMock = null;
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runStdio(array $options = []): string
    {
        $stdin = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');

        fwrite($stdin, json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'list_widgets', 'arguments' => []],
        ]) . "\n");
        rewind($stdin);

        McpServerCommand::$stdinMock = $stdin;
        McpServerCommand::$stdoutMock = $stdout;

        $this->artisan('sp-laravel-api:mcp', $options)->assertSuccessful();

        rewind($stdout);

        return (string) stream_get_contents($stdout);
    }

    /** @test */
    public function the_tenant_option_scopes_stdio_data_tools(): void
    {
        $out = $this->runStdio(['--tenant' => '1']);

        $this->assertStringContainsString('ACME-SECRET', $out);
        $this->assertStringNotContainsString('GLOBEX-SECRET', $out);
    }

    /** @test */
    public function a_different_tenant_option_scopes_to_that_tenant(): void
    {
        $out = $this->runStdio(['--tenant' => '2']);

        $this->assertStringContainsString('GLOBEX-SECRET', $out);
        $this->assertStringNotContainsString('ACME-SECRET', $out);
    }

    /** @test */
    public function omitting_the_tenant_option_still_fails_closed(): void
    {
        $out = $this->runStdio();

        $this->assertStringNotContainsString('ACME-SECRET', $out);
        $this->assertStringNotContainsString('GLOBEX-SECRET', $out);
        $this->assertStringContainsString('Tenant context is required', $out);
    }
}
