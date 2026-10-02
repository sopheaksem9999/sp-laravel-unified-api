<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolExecutor;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * One MCP tool call, independent of any transport: the JSON-RPC errors are
 * ToolErrors, the isError results are returned.
 *
 * @internal
 */
class McpToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $granted = [];

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
        Config::set('record.tables', [
            'widgets' => new RecordTableType(table: 'widgets', pmsName: 'widget', hasTenantId: false, softDeletes: false, columns: $columns),
        ]);
        SchemaRegistryUtils::refresh();

        config(['record.mcp.read_only' => false]);
        Gate::before(fn($user, string $ability): ?bool => in_array($ability, $this->granted, true) ? true : null);
        $this->actingAs(new GenericUser(['id' => 5, 'name' => 'u']), 'api');
    }

    /** @test */
    public function an_unknown_tool_is_a_minus_32601_tool_error(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionCode(-32601);
        $this->expectExceptionMessage('Tool not found: nope');

        (new ToolExecutor())->call('nope', []);
    }

    /** @test */
    public function schema_only_mode_refuses_data_tools_as_not_found(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionCode(-32601);

        (new ToolExecutor(schemaOnly: true))->call('list_widgets', []);
    }

    /** @test */
    public function read_only_mode_refuses_write_tools_as_not_found(): void
    {
        config(['record.mcp.read_only' => true]);
        $this->expectException(ToolError::class);
        $this->expectExceptionCode(-32601);
        $this->expectExceptionMessage('Tool not found or read-only mode is enabled: create_widgets');

        (new ToolExecutor())->call('create_widgets', ['payload' => []]);
    }

    /** @test */
    public function an_unknown_table_is_a_minus_32001_tool_error(): void
    {
        // The unknown-table check belongs to tenant resolution, so it runs when tenancy is on.
        config(['record.enable_tenant_id' => true]);
        $this->granted = ['view:nope'];
        $this->expectException(ToolError::class);
        $this->expectExceptionCode(-32001);
        $this->expectExceptionMessage('Unknown table: nope');

        (new ToolExecutor())->call('list_nope', []);
    }

    /** @test */
    public function a_user_without_the_permission_is_forbidden_minus_32002(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionCode(-32002);
        $this->expectExceptionMessage('Forbidden');

        (new ToolExecutor())->call('list_widgets', []);
    }

    /** @test */
    public function a_permitted_list_returns_an_ok_result(): void
    {
        $this->granted = ['view:widget'];

        $result = (new ToolExecutor())->call('list_widgets', []);

        $this->assertFalse($result->isError);
        $this->assertArrayHasKey('response', $result->structuredContent);
    }

    /** @test */
    public function a_record_failure_is_an_is_error_result_not_an_exception(): void
    {
        $this->granted = ['create:widget'];

        $result = (new ToolExecutor())->call('create_widgets', ['payload' => ['not_a_column' => 'x']]);

        $this->assertTrue($result->isError);
        $this->assertNotSame('', (string) $result->message);
    }

    /** @test */
    public function schema_tools_run_through_the_executor(): void
    {
        $result = (new ToolExecutor())->call('sp_api_list_permissions', []);

        $this->assertFalse($result->isError);
        $this->assertArrayHasKey('permissions', $result->structuredContent);
    }

    /** @test */
    public function a_schema_tool_failure_is_an_is_error_result(): void
    {
        $result = (new ToolExecutor())->call('sp_api_get_endpoint', []);

        $this->assertTrue($result->isError);
        $this->assertSame('Missing required parameter: endpoint', $result->message);
    }

    /** @test */
    public function resource_reads_validate_the_uri_and_the_table(): void
    {
        $this->assertStringContainsString('"table": "widgets"', (new ToolExecutor())->readResource('schema://widgets')['contents'][0]['text']);

        try {
            (new ToolExecutor())->readResource('file://x');
            $this->fail('An invalid URI must be refused.');
        } catch (ToolError $toolError) {
            $this->assertSame('Invalid resource URI: file://x', $toolError->getMessage());
            $this->assertSame(-32603, $toolError->getCode());
        }

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Resource not found: schema://nope');
        (new ToolExecutor())->readResource('schema://nope');
    }
}
