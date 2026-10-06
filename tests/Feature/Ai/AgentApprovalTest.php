<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Error;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Responses\Data\ToolCall;
use Sopheak\Core\Ai\RecordTools;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\UsesLaravelAi;
use Sopheak\Core\Tests\Support\AiPlainRecordAgent;
use Sopheak\Core\Tests\Support\AiRecordAgent;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * A write waits for a person; a read never does; the person approves exactly
 * what will be written (spec §7.5, §12.5). The gateway is scripted but the
 * agent is not "faked", so a resumed approval really runs the tool.
 *
 * @internal
 */
class AgentApprovalTest extends TestCase
{
    use BuildsGuidanceFixture;
    use RefreshDatabase;
    use UsesLaravelAi;

    private const PAYLOAD = ['payload' => ['ref_number' => 'A-1', 'customer_id' => 1]];

    protected function setUp(): void
    {
        $this->requireLaravelAi();
        parent::setUp();
        $this->buildGuidanceFixture();
    }

    private function createCall(): ToolCall
    {
        return new ToolCall('call_1', 'create_invoices', self::PAYLOAD);
    }

    public function test_a_write_pauses_with_the_real_arguments_and_a_reason(): void
    {
        $this->scriptedGateway([$this->createCall(), 'done']);

        $paused = (new AiRecordAgent(RecordTools::for('invoices')))->prompt('make an invoice');

        $this->assertCount(1, $paused->pendingApprovals);
        $pending = $paused->pendingApprovals->first();
        $this->assertSame('create_invoices', $pending->tool);
        $this->assertSame(self::PAYLOAD, $pending->arguments, 'the approver sees exactly what will be written');
        $this->assertSame('create on invoices changes data', $pending->reason);
        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_approving_writes_exactly_once(): void
    {
        $this->scriptedGateway([$this->createCall(), 'done']);
        $agent = new AiRecordAgent(RecordTools::for('invoices'));

        $resumed = $agent->resume(Decision::approveAll(), $agent->prompt('make an invoice'));

        $this->assertSame('done', $resumed->text);
        $this->assertSame(1, DB::table('invoices')->where('ref_number', 'A-1')->count());
    }

    public function test_rejecting_writes_nothing(): void
    {
        $this->scriptedGateway([$this->createCall(), 'done']);
        $agent = new AiRecordAgent(RecordTools::for('invoices'));

        $agent->resume(Decision::rejectAll('No.'), $agent->prompt('make an invoice'));

        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_an_edited_decision_writes_the_edited_arguments(): void
    {
        $this->scriptedGateway([$this->createCall(), 'done']);
        $agent = new AiRecordAgent(RecordTools::for('invoices'));
        $paused = $agent->prompt('make an invoice');

        $agent->resume(Decisions::from(['call_1' => Decision::edit(['payload' => ['ref_number' => 'EDITED', 'customer_id' => 1]])]), $paused);

        $this->assertSame(0, DB::table('invoices')->where('ref_number', 'A-1')->count());
        $this->assertSame(1, DB::table('invoices')->where('ref_number', 'EDITED')->count());
    }

    public function test_without_approval_writes_on_the_first_prompt(): void
    {
        $this->scriptedGateway([$this->createCall(), 'done']);

        $response = (new AiRecordAgent(RecordTools::for('invoices')->withoutApproval()))->prompt('make an invoice');

        $this->assertCount(0, $response->pendingApprovals);
        $this->assertSame('done', $response->text);
        $this->assertSame(1, DB::table('invoices')->count());
    }

    public function test_a_read_never_pauses(): void
    {
        DB::table('invoices')->insert(['id' => 1, 'ref_number' => 'A-1', 'customer_id' => 1]);
        $this->scriptedGateway([new ToolCall('call_1', 'list_invoices', []), 'done']);

        $response = (new AiRecordAgent(RecordTools::for('invoices')))->prompt('list invoices');

        $this->assertCount(0, $response->pendingApprovals);
        $this->assertSame('done', $response->text);
        $this->assertStringContainsString('A-1', (string) $response->toolResults->first()->result);
    }

    public function test_a_failure_on_the_resume_path_does_not_hand_its_text_to_the_model(): void
    {
        // laravel/ai catches a Throwable from an approved tool and gives the model
        // "The tool call failed: <message>"; the message must not carry internals.
        // The permission check only runs on a table that needs authentication.
        $tables = Config::get('record.tables');
        $tables['invoices']->isAuthRead = true;
        $tables['invoices']->isAuthWrite = true;
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
        $this->actingAs(new GenericUser(['id' => 5, 'name' => 'u']), 'api');
        Gate::before(static fn($user, string $ability): ?bool => 'create:invoices' === $ability ? true : null);

        $this->scriptedGateway([$this->createCall(), 'done']);
        $agent = new AiRecordAgent(RecordTools::for('invoices'));
        $paused = $agent->prompt('make an invoice');

        Gate::before(static function (): never {
            throw new Error('permission hook blew up: DSN=mysql://root:hunter2@db');
        });
        $resumed = $agent->resume(Decision::approveAll(), $paused);

        $seen = (string) $resumed->toolResults->first()->result;
        $this->assertStringContainsString("'create_invoices'", $seen);
        $this->assertStringNotContainsString('hunter2', $seen);
        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_an_approved_write_runs_under_the_shipped_mcp_read_only_default(): void
    {
        // record.mcp.read_only ships as true and is an MCP transport setting; the AI tools
        // must not inherit it (spec Q3), or every write would fail in a default app.
        Config::set('record.mcp.read_only', true);
        $this->scriptedGateway([$this->createCall(), 'done']);
        $agent = new AiRecordAgent(RecordTools::for('invoices'));

        $agent->resume(Decision::approveAll(), $agent->prompt('make an invoice'));

        $this->assertSame(1, DB::table('invoices')->where('ref_number', 'A-1')->count());
    }

    public function test_a_custom_reason_reaches_the_approver(): void
    {
        $this->scriptedGateway([$this->createCall(), 'done']);

        $paused = (new AiRecordAgent(RecordTools::for('invoices')->requireApproval('Finance must approve.')))->prompt('make an invoice');

        $this->assertSame('Finance must approve.', $paused->pendingApprovals->first()->reason);
    }

    public function test_a_gated_write_on_a_non_conversational_agent_is_laravel_ais_own_error(): void
    {
        $this->scriptedGateway([$this->createCall(), 'done']);

        $this->expectException(ApprovalNotResumableException::class);

        (new AiPlainRecordAgent(RecordTools::for('invoices')))->prompt('make an invoice');
    }

    public function test_a_non_conversational_agent_can_opt_out_of_approval(): void
    {
        $this->scriptedGateway([$this->createCall(), 'done']);

        $response = (new AiPlainRecordAgent(RecordTools::for('invoices')->withoutApproval()))->prompt('make an invoice');

        $this->assertSame('done', $response->text);
        $this->assertSame(1, DB::table('invoices')->count());
    }
}
