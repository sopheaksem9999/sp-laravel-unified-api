<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use RuntimeException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\Traits\HasRoles;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class OwnRecordsModeUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * The viewOwn decision only ever asked Laravel's Gate, while authorizeAction()
 * decides permissions three ways. An app authorizing through a custom
 * record.authorization handler granted viewOwn there and was never restricted;
 * a super admin that Gate also says yes to was restricted; and with the
 * built-in module, a viewOwn permission created after boot was unknown to Gate.
 *
 * @internal
 */
class OwnRecordsAuthorizationModesTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 42;

    private const OTHER = 99;

    private const ADMIN = 1;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('record.mcp.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
        });
        DB::table('users')->insert([['id' => self::ADMIN], ['id' => self::OWNER]]);

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'MINE', 'created_by_id' => self::OWNER, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'THEIRS', 'created_by_id' => self::OTHER, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Config::set('record.tables', ['widgets' => new RecordTableType(
            table: 'widgets',
            pmsName: 'widget',
            hasTenantId: false,
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'name' => ['type' => 'string', 'nullable' => false],
                'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                'created_at' => ['type' => 'datetime', 'nullable' => true],
                'updated_at' => ['type' => 'datetime', 'nullable' => true],
            ],
        )]);
        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
    }

    private function actAs(int $id): static
    {
        return $this->actingAs(OwnRecordsModeUser::query()->findOrFail($id), 'api');
    }

    /**
     * @return array<int, string>
     */
    private function listedNames(int $userId): array
    {
        return array_column((array) $this->actAs($userId)->getJson('/api/widgets')->json('data'), 'name');
    }

    /** @test */
    public function a_custom_authorization_handler_granting_view_own_restricts_the_user(): void
    {
        Config::set('record.authorization', fn($user, string $perm): bool => str_starts_with($perm, 'viewOwn:')
            ? (int) $user->id === self::OWNER
            : true);

        $this->assertSame(['MINE'], $this->listedNames(self::OWNER));

        $foreign = $this->actAs(self::OWNER)->getJson('/api/widgets/2');
        $this->assertStringNotContainsString('THEIRS', (string) $foreign->getContent());
    }

    /** @test */
    public function a_custom_authorization_handler_not_granting_view_own_leaves_full_access(): void
    {
        Config::set('record.authorization', fn($user, string $perm): bool => !str_starts_with($perm, 'viewOwn:'));

        $this->assertSame(['MINE', 'THEIRS'], $this->listedNames(self::OWNER));
    }

    /** @test */
    public function a_super_admin_is_never_restricted_even_when_gate_says_yes_to_view_own(): void
    {
        // A blanket Gate::before is a common super-admin pattern, and it
        // grants viewOwn:* too. super_admin_callback must win.
        Gate::before(fn(): bool => true);
        Config::set('permissions.super_admin_callback', fn($user): bool => (int) $user->id === self::ADMIN);

        $this->assertSame(['MINE', 'THEIRS'], $this->listedNames(self::ADMIN));
        $this->assertSame(['MINE'], $this->listedNames(self::OWNER));
    }

    /** @test */
    public function a_view_own_permission_created_after_boot_restricts_immediately(): void
    {
        // Built-in module. The app has already booted, so PermissionRegistrar
        // registered no Gate ability for a permission created now.
        $this->createPermissionTables();
        Config::set('permissions.enabled', true);

        $owner = OwnRecordsModeUser::query()->findOrFail(self::OWNER);
        $needed = array_merge(PermissionUtils::mapPermissions('widgets', 'read'), ['viewOwn:widget']);
        foreach (array_unique($needed) as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $owner->givePermissionTo(array_values(array_unique($needed)));

        $this->assertSame(['MINE'], $this->listedNames(self::OWNER));
    }

    /** @test */
    public function a_super_admin_passes_mcp_action_authorization_like_http(): void
    {
        // No Gate definitions: only super_admin_callback grants access. The MCP
        // copy of authorizeAction() had no super-admin check, so this was
        // Forbidden over MCP while allowed over HTTP.
        Config::set('permissions.super_admin_callback', fn($user): bool => (int) $user->id === self::ADMIN);

        $response = $this->actAs(self::ADMIN)->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'list_widgets', 'arguments' => []],
        ]);

        $this->assertStringContainsString('THEIRS', (string) $response->getContent());
    }

    /**
     * Grant the owner the read permissions (but NOT viewOwn) through the
     * built-in module, so action authorization passes in module mode.
     */
    private function grantReadPermissionsViaModule(): void
    {
        $this->createPermissionTables();
        Config::set('permissions.enabled', true);

        $read = array_values(array_unique(PermissionUtils::mapPermissions('widgets', 'read')));
        foreach ($read as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        OwnRecordsModeUser::query()->findOrFail(self::OWNER)->givePermissionTo($read);
    }

    /** @test */
    public function a_view_own_gate_grant_still_restricts_in_built_in_module_mode(): void
    {
        // Before the shared decision, Gate was the only way viewOwn could be
        // granted in every mode. An app on the built-in module that granted it
        // via Gate::define must stay restricted.
        $this->grantReadPermissionsViaModule();
        Gate::define('viewOwn:widget', fn($user): bool => (int) $user->id === self::OWNER);

        $this->assertSame(['MINE'], $this->listedNames(self::OWNER));
    }

    /** @test */
    public function a_view_own_gate_grant_still_restricts_in_custom_handler_mode(): void
    {
        Config::set('record.authorization', fn($user, string $perm): bool => !str_starts_with($perm, 'viewOwn:'));
        Gate::define('viewOwn:widget', fn($user): bool => (int) $user->id === self::OWNER);

        $this->assertSame(['MINE'], $this->listedNames(self::OWNER));
    }

    /** @test */
    public function a_handler_deciding_on_the_action_does_not_confine_every_reader(): void
    {
        // The handler ignores the permission name and allows any 'read'. The
        // viewOwn question must not arrive disguised as that read check.
        Config::set('record.authorization', fn($user, string $perm, string $table, string $action): bool => 'read' === $action);

        $this->assertSame(['MINE', 'THEIRS'], $this->listedNames(self::OWNER));
    }

    /** @test */
    public function a_handler_that_throws_for_view_own_does_not_break_every_read(): void
    {
        // e.g. Spatie's hasPermissionTo() throws PermissionDoesNotExist for an
        // unseeded viewOwn:* permission.
        Config::set('record.authorization', function ($user, string $perm): bool {
            if (str_starts_with($perm, 'viewOwn:')) {
                throw new RuntimeException('There is no permission named `' . $perm . '`.');
            }

            return true;
        });

        $response = $this->actAs(self::OWNER)->getJson('/api/widgets');

        $response->assertStatus(200);
        $this->assertSame(['MINE', 'THEIRS'], array_column((array) $response->json('data'), 'name'));
    }

    /** @test */
    public function a_throwing_super_admin_callback_does_not_break_signed_in_reads_of_a_public_table(): void
    {
        // authorizeAction() never runs the callback for a public action, so the
        // own-records check can be the first place it runs.
        $tables = Config::get('record.tables');
        $widgets = $tables['widgets'];
        $tables['widgets'] = new RecordTableType(
            table: 'widgets',
            pmsName: 'widget',
            hasTenantId: false,
            isAuthRead: false,
            columns: $widgets->columns,
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();

        Config::set('permissions.super_admin_callback', function ($user): bool {
            throw new RuntimeException('callback bug');
        });

        $this->actAs(self::OWNER)->getJson('/api/widgets')->assertStatus(200);
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
