<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Mcp\ToolDefinition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Sopheak\Core\Mcp\ToolCatalog;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolExecutor;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * A table's canRead / canCreate / canUpdate / canDelete flags switch its HTTP
 * routes off (404). The MCP data tools must honour them too: a tool for an
 * action the table does not allow is not offered and is refused if called, or
 * `delete_<table>` would delete rows over MCP that the API refuses to delete.
 */
class McpTableFlagsTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['locked', 'open'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
                $t->string('name')->nullable();
                $t->timestamps();
            });
            DB::table($table)->insert(['id' => 1, 'name' => 'ONE']);
        }

        $public = new RecordTablePublic(read: true, write: true);
        Config::set('record.tables', [
            'locked' => new RecordTableType(table: 'locked', pmsName: 'locked', canRead: false, canCreate: false, canUpdate: false, canDelete: false, public: $public),
            'open' => new RecordTableType(table: 'open', pmsName: 'open', canDelete: false, public: $public),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /** @return array<int, string> */
    private function toolNames(): array
    {
        return array_map(static fn(ToolDefinition $tool): string => $tool->name, (new ToolCatalog())->data());
    }

    public function test_only_the_actions_a_table_allows_are_offered(): void
    {
        $names = $this->toolNames();

        foreach (['list_open', 'read_open', 'create_open', 'update_open'] as $name) {
            $this->assertContains($name, $names, $name);
        }

        $this->assertNotContains('delete_open', $names, 'canDelete is false');

        foreach (['list_locked', 'read_locked', 'create_locked', 'update_locked', 'delete_locked'] as $name) {
            $this->assertNotContains($name, $names, $name);
        }
    }

    /**
     * @param array<string, array<string, string>|int> $arguments
     */
    #[DataProvider('refusedCalls')]
    public function test_a_call_for_a_disallowed_action_is_refused_and_changes_nothing(string $tool, array $arguments): void
    {
        try {
            (new ToolExecutor())->call($tool, $arguments);
            $this->fail('expected the call to be refused');
        } catch (ToolError $toolError) {
            $this->assertSame(-32601, $toolError->getCode());
            $this->assertStringContainsString($tool, $toolError->getMessage());
        }

        $this->assertSame(1, DB::table('locked')->count());
        $this->assertSame(1, DB::table('open')->count());
        $this->assertSame('ONE', DB::table('locked')->value('name'));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function refusedCalls(): array
    {
        return [
            'delete a canDelete:false table' => ['delete_open', ['id' => 1]],
            'list a canRead:false table' => ['list_locked', []],
            'read a canRead:false table' => ['read_locked', ['id' => 1]],
            'create on a canCreate:false table' => ['create_locked', ['payload' => ['name' => 'x']]],
            'update on a canUpdate:false table' => ['update_locked', ['id' => 1, 'payload' => ['name' => 'x']]],
            'delete on a canDelete:false table' => ['delete_locked', ['id' => 1]],
        ];
    }

    public function test_allowed_actions_still_work(): void
    {
        $result = (new ToolExecutor())->call('update_open', ['id' => 1, 'payload' => ['name' => 'TWO']]);

        $this->assertFalse($result->isError);
        $this->assertSame('TWO', DB::table('open')->where('id', 1)->value('name'));
    }

    public function test_an_unknown_table_keeps_its_original_error(): void
    {
        try {
            (new ToolExecutor())->call('list_missing', []);
            $this->fail('expected an error');
        } catch (ToolError $toolError) {
            $this->assertSame(-32001, $toolError->getCode());
        }
    }
}
