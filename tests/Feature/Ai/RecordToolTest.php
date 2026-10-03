<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature\Ai;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Error;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Laravel\Ai\Approvals\Approval;
use PHPUnit\Framework\Attributes\DataProvider;
use Laravel\Ai\Tools\Request;
use Sopheak\Core\Ai\RecordTool;
use Sopheak\Core\Mcp\ToolCatalog;
use Sopheak\Core\Mcp\ToolContext;
use Sopheak\Core\Mcp\ToolDefinition;
use Sopheak\Core\Mcp\ToolExecutor;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\UsesLaravelAi;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * One AI SDK tool over one catalog definition: it runs through ToolExecutor, so
 * it gets exactly the tenant, permission and sanitising rules MCP gets.
 *
 * @internal
 */
class RecordToolTest extends TestCase
{
    use BuildsGuidanceFixture;
    use RefreshDatabase;
    use UsesLaravelAi;

    /** @var array<int, string> */
    private array $granted = [];

    protected function setUp(): void
    {
        $this->requireLaravelAi();
        parent::setUp();
        $this->buildGuidanceFixture();
        $this->requireAuth();

        Gate::before(fn($user, string $ability): ?bool => in_array($ability, $this->granted, true) ? true : null);
        $this->actingAs(new GenericUser(['id' => 5, 'name' => 'u']), 'api');
    }

    private function requireAuth(): void
    {
        $tables = Config::get('record.tables');
        $tables['invoices']->isAuthRead = true;
        $tables['invoices']->isAuthWrite = true;
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    private function definition(string $name): ToolDefinition
    {
        foreach ((new ToolCatalog())->data(readOnly: false) as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }

        $this->fail('No catalog tool named ' . $name);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function invoke(RecordTool $tool, array $arguments = []): array
    {
        return json_decode($tool->handle(new Request($arguments)), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_name_and_description_come_from_the_catalog(): void
    {
        $definition = $this->definition('create_invoices');
        $tool = new RecordTool($definition);

        $this->assertSame('create_invoices', $tool->name());
        $this->assertSame($definition->description, $tool->description());
        $this->assertSame('create', $tool->action());
        $this->assertSame('invoices', $tool->table());
    }

    public function test_a_write_runs_through_the_executor_and_matches_a_direct_call(): void
    {
        $this->granted = ['create:invoices'];

        $viaTool = $this->invoke(new RecordTool($this->definition('create_invoices')), ['payload' => ['ref_number' => 'A-1', 'customer_id' => 1]]);
        $direct = (new ToolExecutor(honourReadOnly: false))->call('create_invoices', ['payload' => ['ref_number' => 'B-1', 'customer_id' => 1]])->structuredContent;

        $this->assertSame(2, DB::table('invoices')->count());
        $this->assertSame(array_keys($direct), array_keys($viaTool));
        $this->assertSame(array_keys($direct['response']), array_keys($viaTool['response']));
        $this->assertSame('A-1', $viaTool['response']['data']['ref_number']);
    }

    public function test_a_read_returns_the_structured_response(): void
    {
        $this->granted = ['view:invoices'];
        DB::table('invoices')->insert(['id' => 1, 'ref_number' => 'A-1', 'customer_id' => 1]);

        $result = $this->invoke(new RecordTool($this->definition('list_invoices')));

        $this->assertSame(['A-1'], array_column($result['response']['data'], 'ref_number'));
    }

    public function test_a_refusal_is_an_error_string_the_run_can_continue_from(): void
    {
        $forbidden = $this->invoke(new RecordTool($this->definition('list_invoices')));
        $this->assertSame(['error' => ['code' => -32002, 'message' => 'Forbidden']], $forbidden);

        auth('api')->forgetUser();
        $unauthenticated = $this->invoke(new RecordTool($this->definition('list_invoices')));
        $this->assertSame(-32001, $unauthenticated['error']['code']);
    }

    public function test_a_tenant_argument_from_the_model_is_refused(): void
    {
        $this->granted = ['view:invoices'];
        Config::set('record.enable_tenant_id', true);
        request()->attributes->set('resolved_tenant_id', 't1');

        $result = $this->invoke(new RecordTool($this->definition('list_invoices')), ['tenantId' => 't2']);

        $this->assertSame(-32001, $result['error']['code']);
        $this->assertStringContainsString('tenantId', $result['error']['message']);
    }

    public function test_a_record_failure_is_an_error_without_a_code(): void
    {
        $this->granted = ['create:invoices'];

        $result = $this->invoke(new RecordTool($this->definition('create_invoices')), ['payload' => ['not_a_column' => 'x']]);

        $this->assertArrayNotHasKey('code', $result['error']);
        $this->assertNotSame('', $result['error']['message']);
        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_an_unexpected_error_outside_record_execution_fails_the_run_without_leaking_its_text(): void
    {
        // Failures inside the record operation are reported by ToolExecutor as isError
        // results (as over MCP); what escapes it — here, a broken permission hook — must
        // fail the run, reported to the app, with a message that carries nothing secret.
        Gate::before(static function (): never {
            throw new Error('permission hook blew up: DSN=mysql://root:hunter2@db');
        });

        try {
            (new RecordTool($this->definition('list_invoices')))->handle(new Request([]));
            $this->fail('the failure must reach the run');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString("'list_invoices'", $runtimeException->getMessage());
            $this->assertStringNotContainsString('hunter2', $runtimeException->getMessage());
            $this->assertInstanceOf(Error::class, $runtimeException->getPrevious(), 'the original stays attached for the log');
        }
    }

    public function test_free_form_objects_can_arrive_as_json_text_or_as_objects(): void
    {
        $this->granted = ['create:invoices', 'view:invoices'];

        $asText = $this->invoke(new RecordTool($this->definition('create_invoices')), ['payload' => '{"ref_number":"TXT-1","customer_id":1}']);
        $asObject = $this->invoke(new RecordTool($this->definition('create_invoices')), ['payload' => ['ref_number' => 'OBJ-1', 'customer_id' => 1]]);
        $filtered = $this->invoke(new RecordTool($this->definition('list_invoices')), ['queryParams' => '{"ref_number":"eq.TXT-1"}']);

        $this->assertSame('TXT-1', $asText['response']['data']['ref_number']);
        $this->assertSame('OBJ-1', $asObject['response']['data']['ref_number']);
        $this->assertSame(['TXT-1'], array_column($filtered['response']['data'], 'ref_number'));
    }

    /**
     * @param array<string, array<int|string, int|string>|string|int> $arguments
     */
    #[DataProvider('badArguments')]
    public function test_arguments_of_the_wrong_shape_are_an_error_the_model_can_fix(string $tool, array $arguments, string $fragment): void
    {
        $this->granted = ['create:invoices', 'update:invoices', 'view:invoices', 'delete:invoices'];
        DB::table('invoices')->insert(['id' => 1, 'ref_number' => 'A-1', 'customer_id' => 1]);

        $result = $this->invoke(new RecordTool($this->definition($tool)), $arguments);

        $this->assertStringContainsString($fragment, $result['error']['message']);
        $this->assertArrayNotHasKey('code', $result['error']);
        $this->assertSame(1, DB::table('invoices')->count());
        $this->assertSame('A-1', DB::table('invoices')->value('ref_number'));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
    public static function badArguments(): array
    {
        return [
            'payload that is not JSON' => ['create_invoices', ['payload' => 'not json'], 'payload'],
            'payload that is a JSON scalar' => ['create_invoices', ['payload' => '5'], 'payload'],
            'payload that is an int' => ['update_invoices', ['id' => 1, 'payload' => 7], 'payload'],
            'queryParams that is an int' => ['list_invoices', ['queryParams' => 5], 'queryParams'],
            'queryParams that is a JSON list scalar' => ['list_invoices', ['queryParams' => '"x"'], 'queryParams'],
            'missing id on update' => ['update_invoices', ['payload' => ['ref_number' => 'B']], 'id'],
            'array id on read' => ['read_invoices', ['id' => [1]], 'id'],
            'empty id on delete' => ['delete_invoices', ['id' => ''], 'id'],
        ];
    }

    public function test_a_blank_or_null_query_params_means_none(): void
    {
        $this->granted = ['view:invoices'];
        DB::table('invoices')->insert(['id' => 1, 'ref_number' => 'A-1', 'customer_id' => 1]);

        foreach ([null, '', '   ', '{}', []] as $blank) {
            $result = $this->invoke(new RecordTool($this->definition('list_invoices')), ['queryParams' => $blank]);

            $this->assertSame(['A-1'], array_column($result['response']['data'], 'ref_number'), var_export($blank, true));
        }
    }

    public function test_a_tool_with_a_context_runs_as_the_context_user(): void
    {
        $this->granted = ['view:invoices'];
        auth('api')->forgetUser();
        DB::table('invoices')->insert(['id' => 1, 'ref_number' => 'A-1', 'customer_id' => 1]);

        $tool = new RecordTool($this->definition('list_invoices'), new ToolContext(new GenericUser(['id' => 9, 'name' => 'ctx'])));
        $result = $this->invoke($tool);

        $this->assertSame(['A-1'], array_column($result['response']['data'], 'ref_number'));
        $this->assertFalse(auth('api')->hasUser());
    }

    public function test_the_schema_is_the_converted_input_schema(): void
    {
        $properties = (new RecordTool($this->definition('update_invoices')))->schema(new JsonSchemaTypeFactory());

        $this->assertEqualsCanonicalizing(['id', 'payload', 'queryParams'], array_keys($properties));
    }

    public function test_writes_ask_for_approval_with_a_reason_and_reads_never_do(): void
    {
        foreach (['create' => 'create_invoices', 'update' => 'update_invoices', 'delete' => 'delete_invoices'] as $action => $name) {
            $approval = (new RecordTool($this->definition($name)))->shouldRequestApproval(new Request([]));

            $this->assertInstanceOf(Approval::class, $approval, $name);
            $this->assertSame($action . ' on invoices changes data', $approval->reason);
        }

        foreach (['list_invoices', 'read_invoices'] as $name) {
            $this->assertNull((new RecordTool($this->definition($name)))->shouldRequestApproval(new Request([])), $name);
        }
    }
}
