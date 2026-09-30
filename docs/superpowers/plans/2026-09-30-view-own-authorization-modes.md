---
title: "viewOwn Authorization Modes Plan"
description: "Implementation plan making the viewOwn decision follow the same authorization path as authorizeAction — super admin, custom record.authorization handler, built-in permission module, then Gate — via one shared helper."
keywords:
  - viewOwn
  - authorization
  - super admin
  - permissions
  - plan
---

# viewOwn Authorization Modes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The `viewOwn` decision agrees with how the application actually authorizes, in all three authorization modes, and a super admin is never own-restricted.

**Architecture:** `authorizeAction()` exists twice — `HasControllerHelpers` and `McpServerService` — each with the same decision: `super_admin_callback` first, then a custom `record.authorization` handler, else the built-in permission module when `permissions.enabled`, else Laravel Gate. `OwnRecordsScope` became a third copy that implemented only the Gate branch. The decision moves into two `PermissionUtils` helpers that all three callers use, so they cannot drift again.

**Tech Stack:** PHP 8.2+, Laravel 12/13, PHPUnit 11, PHPStan, Rector.

**Spec:** `docs/bug-reports/2026-09-30-view-own-authorization-modes.md` (Task 0 writes it).

## Global Constraints

- Task 1 is behaviour-preserving for `authorizeAction()`: the existing authorization suites (`SuperAdminBypassTest`, `PermissionRegistrarTest`, `HasRolesTest`, `RelationshipPermissionsTest`, `RelationshipWritePermissionEnforcementTest`, `McpSchemaEndpointCoverageTest`, `McpTenantIsolationTest`) must stay green unmodified.
- The helpers take the user as a parameter; `OwnRecordsScope` keeps using `Auth::user()` for identity, as today. Changing the guard is out of scope.
- For a custom handler, the `viewOwn` check passes action `'read'` — `viewOwn` is a visibility permission.
- Run `vendor/bin/phpunit`, `vendor/bin/phpstan analyse src tests`, `vendor/bin/rector process --dry-run --no-progress-bar` before each commit; no new Rector findings for touched files. Run `graft build` after code changes.

## Review Focus

1. **A custom `record.authorization` handler granting `viewOwn`** must restrict the user — today it is ignored and the user sees everything. Pinned in Task 2.
2. **A super admin whose Gate/handler also says yes to `viewOwn:*`** (e.g. a blanket `Gate::before(fn () => true)`) must not be restricted when `super_admin_callback` identifies them. Pinned in Task 2.
3. **A `viewOwn` permission created after the app booted**, with the built-in module on, must restrict immediately — Gate abilities are registered once at boot, so a long-running worker (Octane) never learns about it. Pinned in Task 2.
4. **Plain-Gate apps see no change** — existing `OwnRecords*` suites unmodified. Pinned in Task 2.
5. **A super admin keeps passing `authorizeAction()`** after the refactor. Pinned in Task 1 by `SuperAdminBypassTest` staying green.

---

### Task 0: Record the finding

**Files:** Create `docs/bug-reports/2026-09-30-view-own-authorization-modes.md` with frontmatter, the reproduction (custom handler granting `viewOwn:widget` to user 42 → user 42 lists `MINE,THEIRS` and reads row 2 with HTTP 200), the root cause (three copies of the decision; `OwnRecordsScope` only implemented Gate), and the two further consequences (Octane staleness; `Gate::before` super admins restricted).

- [ ] **Step 1:** Write it.
- [ ] **Step 2:** `php bin/validate-docs.php 2>&1 | grep -c "2026-09-30-view-own-authorization"` → Expected: `0`.

---

### Task 1: One permission decision, two helpers

**Files:**
- Modify: `src/Utilities/PermissionUtils.php` (add two methods)
- Modify: `src/Http/Controllers/Concerns/HasControllerHelpers.php` — `authorizeAction()`
- Modify: `src/Services/McpServerService.php` — `authorizeAction()`

**Interfaces — Produces:**
- `PermissionUtils::isSuperAdmin(mixed $user): bool`
- `PermissionUtils::userHasAnyPermission(mixed $user, array $permissions, string $table, string $action): bool`

- [ ] **Step 1: Add the helpers** to `PermissionUtils` (imports: `Illuminate\Database\Eloquent\Model`, `Illuminate\Support\Facades\Gate`, `Sopheak\Core\Authorization\PermissionService`):

```php
    /**
     * Whether the configured `permissions.super_admin_callback` identifies
     * $user as a super admin. A super admin bypasses action authorization and
     * is never restricted to their own records.
     */
    public static function isSuperAdmin(mixed $user): bool
    {
        $callback = config('permissions.super_admin_callback');

        return null !== $user && null !== $callback && (bool) $callback($user);
    }

    /**
     * Whether $user holds any of $permissions, decided the one way the package
     * decides every permission: a custom `record.authorization` handler when
     * configured, else the built-in permission module when `permissions.enabled`,
     * else Laravel's Gate.
     *
     * The built-in module is asked directly rather than through the Gate
     * abilities PermissionRegistrar registers at boot, so a permission created
     * after boot is honoured immediately, including on long-running workers.
     *
     * @param array<int, string> $permissions
     */
    public static function userHasAnyPermission(mixed $user, array $permissions, string $table, string $action): bool
    {
        if (null === $user || [] === $permissions) {
            return false;
        }

        $authHandler = config('record.authorization');
        $gate = null === $authHandler ? Gate::forUser($user) : null;
        $permissionService = null;
        $permissionUser = $user instanceof Model ? $user : null;

        foreach ($permissions as $permission) {
            if (null !== $authHandler) {
                $granted = is_string($authHandler)
                    ? (bool) app($authHandler)->handle($user, $permission, $table, $action)
                    : (bool) $authHandler($user, $permission, $table, $action);
            } elseif (config('permissions.enabled', false)) {
                $permissionService ??= app(PermissionService::class);
                $granted = $permissionUser instanceof Model && $permissionService->userHasPermission($permissionUser, $permission);
            } else {
                $granted = $gate->allows($permission);
            }

            if ($granted) {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 2: Use them in both `authorizeAction()` copies.** In each, replace everything from `$superAdminCallback = config('permissions.super_admin_callback');` through the end of the `foreach ($perms as $perm) { … }` loop with:

```php
        if (PermissionUtils::isSuperAdmin($user)) {
            return;
        }

        $allowed = PermissionUtils::userHasAnyPermission($user, $perms, $table, $action);
```

Keep each copy's own `if (!$allowed) { throw … }` unchanged — HTTP throws `HttpResponseException` Forbidden, MCP throws `Exception('Forbidden', -32002)`. Remove imports that become unused (`Gate`, `Model`, `PermissionService`) only if nothing else in the file uses them.

- [ ] **Step 3: Verify behaviour is unchanged.**
Run: `vendor/bin/phpunit --filter "SuperAdminBypassTest|PermissionRegistrarTest|HasRolesTest|RelationshipPermissionsTest|RelationshipWritePermissionEnforcementTest|McpSchemaEndpointCoverageTest|McpTenantIsolationTest"`
Expected: PASS, unmodified.

- [ ] **Step 4: Full gate** — `vendor/bin/phpunit && vendor/bin/phpstan analyse src tests` → all pass, `[OK] No errors`.

---

### Task 2: Route `viewOwn` through the shared decision

**Files:**
- Modify: `src/Utilities/OwnRecordsScope.php` — `ownerColumn()`
- Test: `tests/Feature/OwnRecordsAuthorizationModesTest.php`

**Interfaces — Consumes:** Task 1's two helpers.

- [ ] **Step 1: Write the failing test.** A standalone class (no inherited `Gate::before`), each test choosing its authorization mode:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

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

    private function createPermissionTables(): void
    {
        Schema::create('sp_permissions', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('name')->unique();
            $t->string('group')->nullable();
            $t->string('guard_name');
            $t->text('description')->nullable();
            $t->timestamps();
        });
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
        Schema::create('sp_role_permissions', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('permission_id');
            $t->timestamps();
        });
        Schema::create('sp_model_has_roles', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('model_type');
            $t->string('model_id');
            $t->unsignedBigInteger('role_id');
            $t->string('tenant_id')->nullable();
            $t->timestamps();
        });
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
```

- [ ] **Step 2: Run it** — `vendor/bin/phpunit --filter OwnRecordsAuthorizationModesTest` → Expected: FAIL on the custom-handler-restricts, super-admin, and created-after-boot tests; the not-granting positive control passes.

- [ ] **Step 3: Replace the Gate loop in `OwnRecordsScope::ownerColumn()`.** Replace everything from `$pmsNames = is_array($pmsName) ? $pmsName : [$pmsName];` through the closing `}` of `if (!$shouldRestrictToOwn) { return null; }` with:

```php
        $user = Auth::user();

        // A super admin is never restricted to their own rows, however the
        // app's Gate or authorization handler answers viewOwn:*.
        if (PermissionUtils::isSuperAdmin($user)) {
            return null;
        }

        $prefix = RecordConfigService::ownRecordsPermissionPrefix();
        $separator = RecordConfigService::permissionSeparator();

        $permissions = [];
        foreach (is_array($pmsName) ? $pmsName : [$pmsName] as $candidate) {
            if (!is_string($candidate) || '' === trim($candidate)) {
                continue;
            }

            $permissions[] = $prefix . $separator . trim($candidate);
        }

        // Decided exactly as authorizeAction() decides every permission:
        // custom handler, else the built-in module, else Gate.
        if (!PermissionUtils::userHasAnyPermission($user, $permissions, $table, 'read')) {
            return null;
        }
```

Remove the now-unused `Gate` import from `OwnRecordsScope`.

- [ ] **Step 4: Run** — `vendor/bin/phpunit --filter "OwnRecordsAuthorizationModesTest|OwnRecordsScopeTest|OwnRecordsScopingTest|OwnRecordsWriteScopingTest|OwnRecordsCacheIsolationTest"` → Expected: PASS.

- [ ] **Step 5: Full gate** — suite, PHPStan, Rector as in Global Constraints.

---

### Task 3: Documentation, changelog, local settings

**Files:** `docs/guide/features/feature-permission-own-records.md`, `CHANGELOG.md`, `docs/changelog.md`, the Task 0 report, `.claude/settings.json`.

- [ ] **Step 1: Guide.** Add a "How the `viewOwn` check is decided" section: same path as action authorization (super admin, custom handler with action `'read'`, built-in module, Gate); a super admin identified by `super_admin_callback` is never restricted; a blanket `Gate::before` without `super_admin_callback` still grants `viewOwn:*` — so use `super_admin_callback`, or have `Gate::before` skip `viewOwn:*` abilities.
- [ ] **Step 2: Changelog** under `[Unreleased]` → `### Fixed` in both files.
- [ ] **Step 3: Close the report** — status Fixed, map each consequence to its test.
- [ ] **Step 4: `.claude/settings.json`** — hook `timeout` is in **seconds**; change `10000`→`10`, `8000`→`8`, `15000`→`15`. Remove the two `PreToolUse` `graphify hook-guard` entries (the repo moved to graft; they contradict `CLAUDE.md`). Validate with `jq -e .`.
- [ ] **Step 5: Final gate** — `graft build && vendor/bin/phpunit && vendor/bin/phpstan analyse src tests && php bin/validate-docs.php` → no new failures.

## Out of Scope

- The guard used for identity inside `OwnRecordsScope` (`Auth::user()`, default guard).
- Relationship includes (`2026-09-30-view-own-relationship-includes.md`) and nested relationship writes (`2026-09-27-nested-relationship-write-tenant-scope.md`) — each needs its own plan.
