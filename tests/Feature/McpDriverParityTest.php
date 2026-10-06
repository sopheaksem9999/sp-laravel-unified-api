<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\McpServerService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * A plain JSON-RPC client must get the same result fields and the same error
 * codes and messages from the `laravel` driver (over HTTP) as from the `legacy`
 * JSON-RPC service (spec §7.1, §10 item 1). Differences that are deliberate
 * (protocol negotiation, HTTP status of an error response) are pinned below.
 *
 * @internal
 */
class McpDriverParityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $granted = [];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('record.mcp.middleware', ['api']);
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

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function legacy(string $method, array $params = []): array
    {
        $response = (new McpServerService())->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);

        // Compare what travels on the wire: JSON turns 150.0 into 150.
        return (array) json_decode((string) json_encode($response), true);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function laravel(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/api/mcp/message', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: array<string, mixed>, 1: TestResponse}
     */
    private function both(string $tool, array $arguments = []): array
    {
        $params = ['name' => $tool, 'arguments' => (object) $arguments];

        return [$this->legacy('tools/call', ['name' => $tool, 'arguments' => $arguments]), $this->laravel('tools/call', $params)];
    }

    /** @test */
    public function the_schema_tools_return_the_same_content_and_structured_content(): void
    {
        foreach ([['sp_api_list_permissions', []], ['sp_api_get_endpoint', ['endpoint' => 'widgets']], ['sp_api_get_api_guidance', []]] as [$tool, $arguments]) {
            [$legacy, $laravel] = $this->both($tool, $arguments);

            $laravel->assertOk();
            $this->assertSame($legacy['result']['content'], $laravel->json('result.content'), $tool);
            $this->assertSame($legacy['result']['structuredContent'], $laravel->json('result.structuredContent'), $tool);
        }
    }

    /** @test */
    public function a_schema_tool_failure_is_the_same_is_error_result(): void
    {
        [$legacy, $laravel] = $this->both('sp_api_get_endpoint', []);

        $laravel->assertOk();
        $this->assertTrue($laravel->json('result.isError'));
        $this->assertSame($legacy['result']['content'], $laravel->json('result.content'));
    }

    /** @test */
    public function a_permitted_data_call_returns_the_same_envelope(): void
    {
        $this->granted = ['view:widget'];

        [$legacy, $laravel] = $this->both('list_widgets', ['queryParams' => ['limit' => 5]]);

        $laravel->assertOk();
        $this->assertSame($legacy['result']['structuredContent'], $laravel->json('result.structuredContent'));
        $this->assertSame($legacy['result']['content'], $laravel->json('result.content'));
    }

    /** @test */
    public function a_record_failure_is_the_same_is_error_result(): void
    {
        $this->granted = ['create:widget'];

        [$legacy, $laravel] = $this->both('create_widgets', ['payload' => ['not_a_column' => 'x']]);

        $laravel->assertOk();
        $this->assertTrue($laravel->json('result.isError'));
        $this->assertSame($legacy['result']['content'], $laravel->json('result.content'));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: int, 3: string, 4: int}>
     */
    public static function refusals(): array
    {
        // tool, arguments, JSON-RPC code, message, HTTP status on the laravel driver
        return [
            'forbidden' => ['list_widgets', [], -32002, 'Forbidden', 400],
            'unknown tool' => ['frobnicate_widgets', [], -32601, 'Tool not found: frobnicate_widgets', 404],
        ];
    }

    /**
     * @dataProvider refusals
     * @param array<string, mixed> $arguments
     * @test
     */
    public function a_refusal_has_the_same_code_and_message_and_the_laravel_http_status(string $tool, array $arguments, int $code, string $message, int $status): void
    {
        [$legacy, $laravel] = $this->both($tool, $arguments);

        $this->assertSame($code, $legacy['error']['code']);
        $this->assertSame($message, $legacy['error']['message']);

        $laravel->assertStatus($status);
        $this->assertSame($code, $laravel->json('error.code'));
        $this->assertSame($message, $laravel->json('error.message'));
        $this->assertSame(1, $laravel->json('id'));
    }

    /** @test */
    public function an_unauthenticated_call_has_the_same_code_and_message(): void
    {
        auth('api')->forgetUser();

        [$legacy, $laravel] = $this->both('list_widgets');

        $this->assertSame(-32001, $legacy['error']['code']);
        $this->assertSame(-32001, $laravel->json('error.code'));
        $this->assertSame($legacy['error']['message'], $laravel->json('error.message'));
    }

    /** @test */
    public function read_only_mode_refuses_a_write_tool_with_the_same_message(): void
    {
        config(['record.mcp.read_only' => true]);

        [$legacy, $laravel] = $this->both('create_widgets', ['payload' => ['name' => 'x']]);

        $this->assertSame($legacy['error']['message'], $laravel->json('error.message'));
        $this->assertSame(-32601, $laravel->json('error.code'));
    }

    /** @test */
    public function the_resource_list_and_a_resource_read_match(): void
    {
        $legacyList = $this->legacy('resources/list');
        $laravelList = $this->laravel('resources/list')->json('result.resources');

        $this->assertSame(array_column($legacyList['result']['resources'], 'uri'), array_column($laravelList, 'uri'));

        $legacyRead = $this->legacy('resources/read', ['uri' => 'schema://widgets']);
        $laravelRead = $this->laravel('resources/read', ['uri' => 'schema://widgets']);

        $this->assertSame($legacyRead['result']['contents'][0]['text'], $laravelRead->json('result.contents.0.text'));
    }

    /** @test */
    public function the_tool_names_are_identical_to_the_legacy_driver(): void
    {
        $legacy = array_column($this->legacy('tools/list')['result']['tools'], 'name');
        $laravel = array_column($this->laravel('tools/list')->json('result.tools'), 'name');

        // The laravel driver may hide tools the caller cannot use (spec §6.3), but never adds any.
        $this->assertSame([], array_values(array_diff($laravel, $legacy)));
        $this->assertContains('sp_api_get_endpoint', $laravel);
    }

    /** @test */
    public function the_protocol_version_is_negotiated_unlike_the_legacy_fixed_answer(): void
    {
        $this->assertSame('2024-11-05', $this->legacy('initialize')['result']['protocolVersion']);
        $this->assertSame('2025-06-18', $this->laravel('initialize', ['protocolVersion' => '2025-06-18'])->json('result.protocolVersion'));
        // A client that only speaks an older version is answered the newest supported one and decides.
        $this->assertSame('2025-11-25', $this->laravel('initialize', ['protocolVersion' => '2024-11-05'])->json('result.protocolVersion'));
    }
}
