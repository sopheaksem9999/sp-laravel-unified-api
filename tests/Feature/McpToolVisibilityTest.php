<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\Traits\HasRoles;
use Sopheak\Core\Mcp\Servers\DataServer;
use Sopheak\Core\Tests\Support\ArrayMcpTransport;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class McpVisibilityUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * On the `laravel` driver `tools/list` shows each user only the tools they may
 * call (spec §6.3). Hiding is a courtesy, not authorization: the executor still
 * refuses a hidden tool. Uses the built-in permission module so the query-count
 * check exercises the real per-user permission cache.
 *
 * @internal
 */
class McpToolVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('permissions.enabled', true);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('record.mcp.driver', 'laravel');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
        });
        DB::table('users')->insert([['id' => 1], ['id' => 2]]);
        $this->createPermissionTables();

        $this->registerTables(['widgets', 'gadgets']);
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            foreach (['widget', 'gadget'] as $name) {
                Permission::query()->firstOrCreate(['name' => $action . ':' . $name, 'guard_name' => 'api']);
            }
        }
    }

    /**
     * @param array<int, string> $tables
     */
    private function registerTables(array $tables): void
    {
        $columns = ['id' => ['type' => 'integer', 'nullable' => false], 'name' => ['type' => 'string', 'nullable' => true]];
        $registry = [];
        foreach ($tables as $table) {
            $registry[$table] = new RecordTableType(table: $table, pmsName: rtrim($table, 's'), hasTenantId: false, softDeletes: false, columns: $columns);
        }

        Config::set('record.tables', $registry);
        SchemaRegistryUtils::refresh();
    }

    private function user(int $id): McpVisibilityUser
    {
        return McpVisibilityUser::query()->findOrFail($id);
    }

    /**
     * @param array<int, string> $permissions
     */
    private function actAsWith(array $permissions, int $id = 1): void
    {
        $user = $this->user($id);
        $user->givePermissionTo($permissions);
        $this->actingAs($user, 'api');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listTools(): array
    {
        $transport = new ArrayMcpTransport();
        (new DataServer($transport))->start();

        return $transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $tool, array $arguments = []): array
    {
        $transport = new ArrayMcpTransport();
        (new DataServer($transport))->start();

        return $transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);
    }

    /** @test */
    public function a_user_only_sees_the_tools_they_may_call(): void
    {
        $this->actAsWith(['view:widget', 'update:widget']);

        $names = array_column($this->listTools(), 'name');

        $this->assertContains('list_widgets', $names);
        $this->assertContains('read_widgets', $names);
        $this->assertContains('update_widgets', $names);
        $this->assertNotContains('delete_widgets', $names);
        $this->assertNotContains('create_widgets', $names);
        $this->assertNotContains('list_gadgets', $names);
        $this->assertContains('sp_api_get_endpoint', $names, 'the schema tools are always listed');
    }

    /** @test */
    public function a_hidden_tool_called_anyway_is_still_forbidden(): void
    {
        $this->actAsWith(['view:widget']);

        $this->assertNotContains('delete_widgets', array_column($this->listTools(), 'name'));
        $this->assertSame(-32002, $this->callTool('delete_widgets', ['id' => 1])['error']['code']);
    }

    /** @test */
    public function a_super_admin_sees_every_tool(): void
    {
        Config::set('permissions.super_admin_callback', static fn($user): bool => 1 === (int) $user->id);
        $this->actingAs($this->user(1), 'api');

        $names = array_column($this->listTools(), 'name');

        $this->assertContains('delete_gadgets', $names);
        $this->assertContains('create_widgets', $names);
    }

    /** @test */
    public function users_with_different_permissions_see_different_tools(): void
    {
        $this->actAsWith(['view:widget'], 1);
        $first = array_column($this->listTools(), 'name');

        $this->actAsWith(['view:gadget'], 2);
        $second = array_column($this->listTools(), 'name');

        $this->assertContains('list_widgets', $first);
        $this->assertNotContains('list_gadgets', $first);
        $this->assertContains('list_gadgets', $second);
        $this->assertNotContains('list_widgets', $second);
    }

    /** @test */
    public function listing_tools_does_not_query_per_tool(): void
    {
        $this->actAsWith(['view:widget']);
        $this->listTools(); // warm the per-user permission cache

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->listTools();
        $few = count(DB::getQueryLog());

        $extra = array_map(static fn(int $i): string => 'extra_' . $i, range(1, 30));
        $this->registerTables(array_merge(['widgets', 'gadgets'], $extra));
        $this->listTools(); // warm again for the new tables

        DB::flushQueryLog();
        $this->listTools();
        $many = count(DB::getQueryLog());

        // 30 more tables (150 more tools) must not mean more permission queries.
        $this->assertLessThanOrEqual($few + 2, $many, 'tools/list issued ' . $many . ' queries vs ' . $few . ' for far fewer tables');
    }

    private function createPermissionTables(): void
    {
        if (!Schema::hasTable('sp_permissions')) {
            Schema::create('sp_permissions', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->string('name')->unique();
                $t->string('group')->nullable();
                $t->string('guard_name');
                $t->text('description')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('sp_roles')) {
            Schema::create('sp_roles', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->string('name');
                $t->string('key')->nullable();
                $t->string('guard_name');
                $t->text('description')->nullable();
                $t->boolean('is_system')->default(false);
                $t->boolean('is_master')->default(false);
                $t->boolean('is_default')->default(false);
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('sp_role_permissions')) {
            Schema::create('sp_role_permissions', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('role_id');
                $t->unsignedBigInteger('permission_id');
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('sp_model_has_roles')) {
            Schema::create('sp_model_has_roles', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->string('model_type');
                $t->string('model_id');
                $t->unsignedBigInteger('role_id');
                $t->string('tenant_id')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('sp_model_permissions')) {
            Schema::create('sp_model_permissions', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->string('model_type');
                $t->string('model_id');
                $t->unsignedBigInteger('permission_id');
                $t->string('tenant_id')->nullable();
                $t->timestamps();
            });
        }
    }
}
