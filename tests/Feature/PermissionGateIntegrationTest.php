<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
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
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class PermissionGateUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * With the built-in permission module the package decided API permissions by
 * asking the module directly, never through Laravel's Gate. Nothing that
 * observes Gate — Telescope's Gate watcher, the app's Gate::before/after
 * callbacks — saw an API decision, and `$user->can()` / `@can` disagreed with
 * the API: abilities were registered once at boot, so a permission created
 * later was unknown to Gate, and `super_admin_callback` never reached it.
 *
 * @internal
 */
class PermissionGateIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const MEMBER = 1;

    private const STRANGER = 2;

    private const ADMIN = 3;

    /** @var array<int, array{0: string, 1: mixed}> Gate checks, as Telescope's GateWatcher records them. */
    private array $gateChecks = [];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Enabled before boot, as in a real app.
        $app['config']->set('permissions.enabled', true);

        // The app's own Gate::before, registered during boot as an
        // AuthServiceProvider does — ahead of the package's hook, which is
        // registered once the app has booted.
        $app->booting(static function () use ($app): void {
            $app->make(GateContract::class)->before(static fn($user, string $ability): ?bool => 'delete:widget' === $ability ? false : null);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
        });
        DB::table('users')->insert([['id' => self::MEMBER], ['id' => self::STRANGER], ['id' => self::ADMIN]]);

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        DB::table('widgets')->insert(['name' => 'W', 'created_by_id' => self::MEMBER]);

        Config::set('record.tables', [
            'widgets' => new RecordTableType(
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
            ),
        ]);
        SchemaRegistryUtils::refresh();

        // The permission tables are created after boot, so every permission
        // below is one the boot-time registration could not have seen.
        $this->createPermissionTables();
        Permission::query()->create(['name' => 'view:widget', 'guard_name' => 'api']);
        $this->user(self::MEMBER)->givePermissionTo(['view:widget']);

        Gate::after(function ($user, string $ability, $result): void {
            $this->gateChecks[] = [$ability, $result];
        });
    }

    private function user(int $id): PermissionGateUser
    {
        return PermissionGateUser::query()->findOrFail($id);
    }

    /**
     * @return mixed[]
     */
    private function checksFor(string $ability): array
    {
        return array_map(
            static fn(array $check): mixed => $check[1],
            array_values(array_filter($this->gateChecks, static fn(array $check): bool => $check[0] === $ability))
        );
    }

    /** @test */
    public function api_permission_checks_go_through_the_gate(): void
    {
        $this->actingAs($this->user(self::MEMBER), 'api')->getJson('/api/widgets')->assertStatus(200);
        $this->assertSame([true], $this->checksFor('view:widget'));

        $this->gateChecks = [];
        $this->actingAs($this->user(self::STRANGER), 'api')->getJson('/api/widgets')->assertStatus(403);
        $this->assertCount(1, $this->checksFor('view:widget'));
        $this->assertNotTrue($this->checksFor('view:widget')[0]);
    }

    /** @test */
    public function can_agrees_with_the_api_for_a_permission_created_after_boot(): void
    {
        $member = $this->user(self::MEMBER);

        $this->assertTrue($member->can('view:widget'));
        $this->assertFalse($this->user(self::STRANGER)->can('view:widget'));
    }

    /** @test */
    public function can_honours_the_super_admin_callback_for_package_permissions_only(): void
    {
        Config::set('permissions.super_admin_callback', fn($user): bool => (int) $user->id === self::ADMIN);
        $admin = $this->user(self::ADMIN);

        $this->assertTrue($admin->can('view:widget'));
        $this->assertFalse($admin->can('publish-newsletter'), 'an app ability the package does not own is left to the app');
    }

    /** @test */
    public function the_apps_gate_callbacks_now_apply_to_api_decisions(): void
    {
        Permission::query()->create(['name' => 'delete:widget', 'guard_name' => 'api']);
        $member = $this->user(self::MEMBER);
        $member->givePermissionTo(['delete:widget']);
        $this->assertTrue($member->hasPermissionTo('delete:widget'));

        // The app's Gate::before denies delete:widget although the module grants it.
        $this->actingAs($member, 'api')->deleteJson('/api/widgets/1')->assertStatus(403);
        $this->assertDatabaseHas('widgets', ['id' => 1]);
    }

    /** @test */
    public function the_apps_own_ability_with_the_same_name_is_not_overwritten(): void
    {
        Gate::define('view:widget', fn($user): bool => (int) $user->id === self::STRANGER);

        $this->assertTrue($this->user(self::STRANGER)->can('view:widget'));
        $this->assertTrue($this->user(self::MEMBER)->can('view:widget'));
    }

    /** @test */
    public function a_user_model_without_has_roles_is_left_to_the_app(): void
    {
        Gate::define('custom-ability', fn(): bool => true);
        $plain = new GenericUser(['id' => self::MEMBER]);

        $this->assertFalse(Gate::forUser($plain)->allows('view:widget'));
        $this->assertTrue(Gate::forUser($plain)->allows('custom-ability'));
    }

    /** @test */
    public function view_own_is_asked_of_the_gate_once_per_check(): void
    {
        $this->actingAs($this->user(self::MEMBER), 'api')->getJson('/api/widgets')->assertStatus(200);

        $this->assertCount(1, $this->checksFor('viewOwn:widget'));
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
