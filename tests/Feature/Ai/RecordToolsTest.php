<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature\Ai;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Sopheak\Core\Ai\RecordTool;
use Sopheak\Core\Ai\RecordTools;
use Sopheak\Core\Ai\RecordToolSet;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\UsesLaravelAi;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The developer-facing API: which tools exist, how a set is narrowed, what
 * approval its writes carry and who its tools run as.
 *
 * @internal
 */
class RecordToolsTest extends TestCase
{
    use BuildsGuidanceFixture;
    use RefreshDatabase;
    use UsesLaravelAi;

    private const ALL = ['list_invoices', 'read_invoices', 'create_invoices', 'update_invoices', 'delete_invoices'];

    protected function setUp(): void
    {
        $this->requireLaravelAi();
        parent::setUp();
        $this->buildGuidanceFixture();
    }

    /**
     * @param array<string, bool> $flags
     */
    private function registerTable(string $name, array $flags = []): void
    {
        $tables = Config::get('record.tables');
        $tables[$name] = new RecordTableType(...[
            'table' => $name,
            'pmsName' => $name,
            'public' => new RecordTablePublic(read: true, write: true),
            'columns' => ['id' => ['type' => 'integer', 'nullable' => false], 'name' => ['type' => 'string', 'nullable' => true]],
            ...$flags,
        ]);
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    public function test_for_a_table_offers_the_five_data_tools(): void
    {
        $set = RecordTools::for('invoices');

        $this->assertInstanceOf(RecordToolSet::class, $set);
        $this->assertSame(self::ALL, $set->names());
        $this->assertCount(5, $set);
        $this->assertContainsOnlyInstancesOf(RecordTool::class, $set->toArray());
    }

    public function test_several_tables_and_spreading_into_tools(): void
    {
        $tools = [...RecordTools::for(['invoices', 'customers'])];

        $this->assertCount(10, $tools);
        $this->assertSame('list_customers', $tools[5]->name());
    }

    public function test_read_only_and_schema_sets(): void
    {
        $this->assertSame(['list_invoices', 'read_invoices'], RecordTools::readOnly('invoices')->names());
        $this->assertSame(
            ['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions', 'sp_api_get_api_guidance'],
            RecordTools::schema()->names(),
        );
    }

    public function test_only_and_except_return_new_sets(): void
    {
        $all = RecordTools::for('invoices');

        $this->assertSame(['list_invoices', 'create_invoices'], $all->only(['list', 'create'])->names());
        $this->assertSame(['list_invoices', 'read_invoices', 'create_invoices', 'update_invoices'], $all->except(['delete'])->names());
        $this->assertSame(self::ALL, $all->names(), 'the original set is untouched');
    }

    public function test_mcp_read_only_mode_does_not_remove_write_tools(): void
    {
        Config::set('record.mcp.read_only', true);

        $this->assertSame(self::ALL, RecordTools::for('invoices')->names());
    }

    public function test_an_unknown_table_names_it_and_lists_the_known_ones(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown table 'nope'");

        RecordTools::for('nope');
    }

    public function test_an_unknown_action_lists_the_valid_ones(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown action 'upsert'");

        RecordTools::for('invoices')->only(['upsert']);
    }

    public function test_only_naming_an_action_the_table_does_not_allow_is_an_error(): void
    {
        $this->registerTable('ledger', ['canCreate' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'ledger'");

        RecordTools::for('ledger')->only(['create']);
    }

    public function test_actions_a_table_does_not_allow_are_left_out(): void
    {
        $this->registerTable('ledger', ['canDelete' => false, 'canUpdate' => false]);

        $this->assertSame(['list_ledger', 'read_ledger', 'create_ledger'], RecordTools::for('ledger')->names());
    }

    public function test_a_tool_name_longer_than_sixty_four_characters_is_an_error_naming_the_table(): void
    {
        $table = 'a_very_long_table_name_that_pushes_the_generated_tool_name_over_the_limit';
        $this->registerTable($table);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($table);

        RecordTools::for($table);
    }

    public function test_a_tool_name_with_characters_providers_reject_is_an_error(): void
    {
        $this->registerTable('odd.table');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('odd.table');

        RecordTools::for('odd.table');
    }

    public function test_writes_require_approval_by_default_and_reads_never_do(): void
    {
        foreach (RecordTools::for('invoices') as $tool) {
            $approval = $tool->shouldRequestApproval(new Request([]));

            if (in_array($tool->action(), ['create', 'update', 'delete'], true)) {
                $this->assertInstanceOf(Approval::class, $approval, $tool->name());
            } else {
                $this->assertNull($approval, $tool->name());
            }
        }
    }

    public function test_without_approval_lets_writes_run(): void
    {
        foreach (RecordTools::for('invoices')->withoutApproval() as $tool) {
            $this->assertNull($tool->shouldRequestApproval(new Request([])), $tool->name());
        }
    }

    public function test_require_approval_carries_a_reason_on_writes_only(): void
    {
        $set = RecordTools::for('invoices')->requireApproval('Finance must approve.');

        foreach ($set as $tool) {
            $approval = $tool->shouldRequestApproval(new Request([]));

            if (in_array($tool->action(), ['create', 'update', 'delete'], true)) {
                $this->assertSame('Finance must approve.', $approval->reason, $tool->name());
            } else {
                $this->assertNull($approval, $tool->name());
            }
        }

        $default = RecordTools::for('invoices')->requireApproval();
        $this->assertSame('create on invoices changes data', iterator_to_array($default)[2]->shouldRequestApproval(new Request([]))->reason);
    }

    public function test_acting_as_and_for_tenant_give_every_tool_one_context(): void
    {
        $user = new GenericUser(['id' => 5, 'name' => 'u']);
        $set = RecordTools::for('invoices')->actingAs($user)->forTenant('t1');

        foreach ($set->only(['list', 'create']) as $tool) {
            $this->assertSame(5, $tool->context()->user()->getAuthIdentifier(), $tool->name());
            $this->assertSame('t1', $tool->context()->tenantId, $tool->name());
        }

        $this->assertNull(iterator_to_array(RecordTools::for('invoices'))[0]->context());
    }

    public function test_without_laravel_ai_the_factory_explains_what_to_install(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('laravel/ai');

        RecordTools::assertInstalled(static fn(): bool => false);
    }
}
