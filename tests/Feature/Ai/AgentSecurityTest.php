<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature\Ai;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Sopheak\Core\Ai\RecordTools;
use Sopheak\Core\Mcp\ToolExecutor;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\UsesLaravelAi;
use Sopheak\Core\Tests\Support\AiRecordAgent;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * An agent gets the guarantees an HTTP or MCP caller gets, because the same
 * executor enforces them (spec §8). Each case drives a whole agent run and
 * reads what the model was handed back.
 *
 * @internal
 */
class AgentSecurityTest extends TestCase
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
    }

    private function boot(bool $tenant = false): void
    {
        $this->buildGuidanceFixture($tenant);
        $tables = Config::get('record.tables');
        foreach (['invoices', 'customers', 'invoice_items', 'tags'] as $name) {
            $tables[$name]->isAuthRead = true;
            $tables[$name]->isAuthWrite = true;
        }

        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();

        Gate::before(fn($user, string $ability): ?bool => in_array($ability, $this->granted, true) ? true : null);
        $this->actingAs(new GenericUser(['id' => 5, 'name' => 'u']), 'api');
    }

    /**
     * Run one tool call through an agent and return what the model received.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function modelSees(string $tool, array $arguments = [], bool $withoutApproval = true): array
    {
        $this->scriptedGateway([new ToolCall('call_1', $tool, $arguments), 'done']);
        $tools = RecordTools::for('invoices');
        $response = (new AiRecordAgent($withoutApproval ? $tools->withoutApproval() : $tools))->prompt('go');

        $this->assertSame('done', $response->text, 'the run continues after the tool result');

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(AgentResponse $response): array
    {
        return json_decode((string) $response->toolResults->first()->result, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_a_user_without_the_permission_gets_an_error_the_run_survives(): void
    {
        $this->boot();

        $seen = $this->modelSees('list_invoices');

        $this->assertSame(['error' => ['code' => -32002, 'message' => 'Forbidden']], $seen);
    }

    public function test_another_tenants_rows_are_not_returned(): void
    {
        $this->boot(tenant: true);
        $this->granted = ['view:invoices'];
        Config::set('record.enable_tenant_id', true);
        request()->attributes->set('resolved_tenant_id', 't1');
        DB::table('invoices')->insert([
            ['id' => 1, 'tenant_id' => 't1', 'ref_number' => 'MINE', 'customer_id' => 1],
            ['id' => 2, 'tenant_id' => 't2', 'ref_number' => 'THEIRS', 'customer_id' => 1],
        ]);

        $seen = $this->modelSees('list_invoices');

        $this->assertSame(['MINE'], array_column($seen['response']['data'], 'ref_number'));
    }

    public function test_a_tenant_scoped_table_without_a_tenant_is_refused(): void
    {
        $this->boot(tenant: true);
        $this->granted = ['view:invoices'];
        Config::set('record.enable_tenant_id', true);

        $seen = $this->modelSees('list_invoices');

        $this->assertSame(-32001, $seen['error']['code']);
    }

    public function test_a_tenant_argument_from_the_model_is_refused(): void
    {
        $this->boot(tenant: true);
        $this->granted = ['view:invoices'];
        Config::set('record.enable_tenant_id', true);
        request()->attributes->set('resolved_tenant_id', 't1');

        $seen = $this->modelSees('list_invoices', ['tenantId' => 't2']);

        $this->assertSame(-32001, $seen['error']['code']);
    }

    public function test_a_view_own_user_sees_only_their_own_rows(): void
    {
        $this->boot();
        // viewOwn narrows a user who may already view: view:invoices lets the call in,
        // viewOwn:invoices limits it to rows the user created.
        $this->granted = ['view:invoices'];
        Gate::define('viewOwn:invoices', fn($user): bool => 5 === (int) $user->getAuthIdentifier());
        DB::table('invoices')->insert([
            ['id' => 1, 'ref_number' => 'MINE', 'customer_id' => 1, 'created_by_id' => 5],
            ['id' => 2, 'ref_number' => 'NOT-MINE', 'customer_id' => 1, 'created_by_id' => 9],
        ]);

        $seen = $this->modelSees('list_invoices');

        $this->assertSame(['MINE'], array_column($seen['response']['data'], 'ref_number'));
    }

    public function test_a_hidden_column_never_reaches_the_model(): void
    {
        $this->boot();
        $this->granted = ['view:invoices'];
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('internal_note')->nullable();
        });
        DB::table('invoices')->insert(['id' => 1, 'ref_number' => 'A-1', 'customer_id' => 1, 'internal_note' => 'TOP-SECRET']);
        $tables = Config::get('record.tables');
        $tables['invoices'] = new RecordTableType(
            table: 'invoices',
            pmsName: 'invoices',
            isAuthRead: true,
            isAuthWrite: true,
            columnHiddens: ['internal_note'],
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();

        $list = $this->modelSees('list_invoices');
        $read = $this->modelSees('read_invoices', ['id' => 1]);

        $this->assertStringNotContainsString('TOP-SECRET', (string) json_encode($list));
        $this->assertStringNotContainsString('TOP-SECRET', (string) json_encode($read));
        $this->assertStringNotContainsString('internal_note', (string) json_encode($list));
    }

    public function test_a_nested_child_write_needs_the_childs_own_permission(): void
    {
        $this->boot();
        $this->granted = ['create:invoices'];
        $arguments = ['payload' => ['ref_number' => 'A-1', 'customer_id' => 1, 'items' => [['description' => 'line']]]];

        $refused = $this->modelSees('create_invoices', $arguments);

        $this->assertSame(-32002, $refused['error']['code']);
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(0, DB::table('invoice_items')->count());

        $this->granted = ['create:invoices', 'create:invoice_items'];
        $written = $this->modelSees('create_invoices', $arguments);

        $this->assertArrayNotHasKey('error', $written);
        $this->assertSame(1, DB::table('invoice_items')->count());
    }

    public function test_an_approved_nested_write_is_still_authorised_per_child_table(): void
    {
        // Approval covers the write the person saw; it does not grant what the user lacks.
        $this->boot();
        $this->granted = ['create:invoices'];
        $arguments = ['payload' => ['ref_number' => 'A-1', 'customer_id' => 1, 'items' => [['description' => 'line']]]];
        $this->scriptedGateway([new ToolCall('call_1', 'create_invoices', $arguments), 'done']);
        $agent = new AiRecordAgent(RecordTools::for('invoices'));

        $resumed = $agent->resume(Decision::approveAll(), $agent->prompt('make an invoice'));

        $this->assertSame(-32002, $this->decode($resumed)['error']['code']);
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(0, DB::table('invoice_items')->count());
    }

    public function test_a_database_error_does_not_echo_values_to_the_model(): void
    {
        $this->boot();
        $this->granted = ['create:invoices'];
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('internal_note')->nullable();
        });
        $tables = Config::get('record.tables');
        $tables['invoices'] = new RecordTableType(
            table: 'invoices',
            pmsName: 'invoices',
            isAuthRead: true,
            isAuthWrite: true,
            columnHiddens: ['internal_note'],
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();

        // ref_number and customer_id are NOT NULL, so the insert fails and its SQL, with
        // the bound values, is the exception message.
        $seen = $this->modelSees('create_invoices', ['payload' => ['internal_note' => 'HIDDEN-VALUE-SENT-BY-MODEL']]);

        $this->assertStringNotContainsString('HIDDEN-VALUE-SENT-BY-MODEL', (string) json_encode($seen));
        $this->assertStringNotContainsString('SQLSTATE', (string) json_encode($seen));
        $this->assertArrayHasKey('error', $seen);
    }

    public function test_tenant_scoped_rows_cannot_be_included_without_a_tenant(): void
    {
        $this->boot(tenant: true);
        $this->granted = ['view:customers'];
        Config::set('record.enable_tenant_id', true);
        $tables = Config::get('record.tables');
        $tables['customers']->relationships = ['invoices' => new RecordHasManyType(table: 'invoices', foreignKey: 'customer_id')];
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
        DB::table('customers')->insert(['id' => 1, 'name' => 'Pat']);
        DB::table('invoices')->insert([
            ['id' => 1, 'tenant_id' => 't1', 'ref_number' => 'MINE', 'customer_id' => 1],
            ['id' => 2, 'tenant_id' => 't2', 'ref_number' => 'THEIRS', 'customer_id' => 1],
        ]);
        $this->scriptedGateway([new ToolCall('call_1', 'list_customers', ['queryParams' => '{"select":"*,invoices(*)","tenant_id":"t2"}']), 'done']);

        $response = (new AiRecordAgent(RecordTools::for('customers')->withoutApproval()))->prompt('go');
        $seen = $this->decode($response);

        $this->assertSame(-32001, $seen['error']['code']);
        $this->assertStringNotContainsString('THEIRS', (string) json_encode($seen));
    }

    public function test_the_agent_gets_the_same_result_as_a_direct_executor_call(): void
    {
        $this->boot();
        $this->granted = ['view:invoices'];
        DB::table('invoices')->insert(['id' => 1, 'ref_number' => 'A-1', 'customer_id' => 1]);

        $seen = $this->modelSees('list_invoices');
        $direct = (new ToolExecutor(honourReadOnly: false))->call('list_invoices', [])->structuredContent;

        $this->assertEquals($direct, $seen);
    }
}
