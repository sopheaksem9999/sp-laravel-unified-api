---
title: "Configurable ID Type Implementation Plan"
description: "Six-task TDD implementation plan for the record.id_type setting and the client-model reference column fixes."
keywords:
  - id type
  - uuid
  - integer
  - primary key
  - record.id_type
  - implementation plan
  - migration
date: 2026-08-04
---

# Configurable ID Type Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a client choose UUID or integer primary keys for `sp_permissions` and `sp_roles` via one config value, and fix the four package columns that hardcode `unsignedBigInteger` while referencing client-owned models.

**Architecture:** A single `record.id_type` config value (`'uuid'` | `'integer'`, default `'integer'`) is read through `RecordConfigService::idType()`. Migrations call a `MigrationIdHelper` that renders the right column type for three distinct column roles. The `Role` and `Permission` Eloquent models gain a trait that resolves `$incrementing` / `$keyType` and generates UUIDs on insert. Columns referencing client models become plain `string`, which holds either an integer or a UUID.

**Tech Stack:** PHP 8.2+, Laravel 12/13, Orchestra Testbench, PHPUnit 10/11, SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-08-04-configurable-id-type-design.md`

## Global Constraints

- Default `record.id_type` is `'integer'`. With the key absent or defaulted, **every table keeps the exact type it has today.** Any change to default behavior is a bug.
- `sp_attachments`, `sp_document_folders`, and `sp_webhook_*` keep `uuid` primary keys under **both** settings. They are never governed by `id_type`.
- `sp_role_permissions.id`, `sp_model_has_roles.id`, `sp_model_permissions.id`, and `sp_audit_logs.id` stay `bigIncrements` under **both** settings. Their write paths insert no `id`.
- `id_type` is read via `config()`, never `env()`. No `SP_ID_TYPE` environment variable is introduced.
- `declare(strict_types=1);` at the top of every new PHP file, matching the codebase.
- Run `composer quality` (format-check, phpstan, phpunit) before the final commit.

### Test config timing — read before writing any test

Testbench calls `getEnvironmentSetUp($app)` **before** `RefreshDatabase` runs migrations. A test that needs `id_type = 'uuid'` schema **must** set it in `getEnvironmentSetUp()`. Setting it in `setUp()` after `parent::setUp()` runs after the tables are already built and will silently test the wrong thing.

```php
protected function getEnvironmentSetUp($app): void
{
    parent::getEnvironmentSetUp($app);          // keep the base TestCase setup
    $app['config']->set('record.id_type', 'uuid');
}
```

### What SQLite can and cannot prove — read before writing any test

**The bug does not reproduce on SQLite.** This was verified directly, not assumed:

```
sqlite> CREATE TABLE probe (model_id INTEGER NOT NULL);
sqlite> INSERT INTO probe VALUES ('3f2504e0-4f89-11d3-9a0c-0305e82c3301');
sqlite> SELECT model_id, typeof(model_id) FROM probe;
3f2504e0-4f89-11d3-9a0c-0305e82c3301|text          <- stored fine, as text
sqlite> SELECT count(*) FROM probe WHERE model_id = '3f2504e0-...';
1                                                   <- lookup works
```

SQLite's type affinity converts only when lossless, so a UUID string lands in an
`INTEGER` column as `text` and round-trips correctly. The failure is real on
MySQL and PostgreSQL, which enforce column types strictly.

**Consequence for this plan:** a purely behavioral test ("uuid user gets a
role") passes *before and after* the fix on this suite. It is still worth
writing — it is the guard that catches the bug on a real database and proves the
fix does not break the flow — but it is **not** the red test.

The red test is a **schema assertion**. Laravel's `Schema::getColumns()` reports
a genuine difference on SQLite:

| Migration call | Reported type |
|---|---|
| `unsignedBigInteger` | `integer` |
| `string` | `varchar` |
| `uuid` | `varchar` |
| `bigIncrements` | `integer` |

So `integer` vs `varchar` is assertable and does fail before the fix. Note that
`uuid` and `string` are indistinguishable on SQLite — that is fine here, since
every assertion this plan needs is `integer` vs `varchar`.

Any step below that says "Expected: FAIL" has been checked against this.

### Existing test fixtures

`tests/Unit/HasRolesTest.php` and `tests/Unit/PermissionRegistrarTest.php` contain a `createPermissionTables()` helper that builds the permission tables inline. Every block is wrapped in `if (!Schema::hasTable(...))`. Because `CoreSpLaravelApiProvider::boot()` calls `loadMigrationsFrom()`, the real migrations always run first, so these blocks are inert for the permission tables — only the `users` block actually executes. They still hardcode `unsignedBigInteger('model_id')` and will read as contradicting the fix, so Task 5 updates them.

---

## File Structure

**Create:**
- `src/Database/MigrationIdHelper.php` — renders column types for the three column roles
- `src/Authorization/Traits/HasConfigurableKey.php` — Eloquent key resolution + UUID generation
- `tests/Unit/RecordConfigServiceIdTypeTest.php` — `idType()` boundary behavior
- `tests/Unit/MigrationIdHelperTest.php` — helper renders correct column types
- `tests/Feature/IdTypeIntegerDefaultTest.php` — default behavior unchanged
- `tests/Feature/IdTypeUuidTest.php` — uuid schema + Role/Permission lifecycle
- `tests/Feature/ClientModelReferenceColumnsTest.php` — the bug fix, both key types

**Modify:**
- `config/record.php` — add `id_type`
- `config/permissions.php` — derive both `id` column types
- `config/audit.php` — `entity_id` / `user_id` to `string`
- `src/Services/RecordConfigService.php` — add `idType()`
- `src/Authorization/Models/Role.php` — apply trait
- `src/Authorization/Models/Permission.php` — apply trait
- `database/migrations/2026_05_13_000000_create_sp_permissions_tables.php`
- `database/migrations/2025_01_27_000000_create_audit_logs_table.php`
- `tests/Unit/HasRolesTest.php` — fixture alignment
- `tests/Unit/PermissionRegistrarTest.php` — fixture alignment
- `docs/guide/features/feature-record-data-types.md` — document `id_type`

---

## Task 1: `RecordConfigService::idType()`

The single read point for the setting. Everything else depends on it.

**Files:**
- Modify: `src/Services/RecordConfigService.php`
- Modify: `config/record.php`
- Test: `tests/Unit/RecordConfigServiceIdTypeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `RecordConfigService::idType(): string` returning exactly `'uuid'` or `'integer'`. Throws `InvalidArgumentException` on any other configured value. Used by Tasks 2, 3, 4.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/RecordConfigServiceIdTypeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use InvalidArgumentException;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;

class RecordConfigServiceIdTypeTest extends TestCase
{
    /** @test */
    public function it_defaults_to_integer_when_config_is_absent(): void
    {
        $this->app['config']->offsetUnset('record.id_type');

        $this->assertSame('integer', RecordConfigService::idType());
    }

    /** @test */
    public function it_returns_uuid_when_configured(): void
    {
        $this->app['config']->set('record.id_type', 'uuid');

        $this->assertSame('uuid', RecordConfigService::idType());
    }

    /** @test */
    public function it_normalizes_case_and_surrounding_whitespace(): void
    {
        $this->app['config']->set('record.id_type', '  UUID ');

        $this->assertSame('uuid', RecordConfigService::idType());
    }

    /** @test */
    public function it_throws_on_an_unrecognized_value(): void
    {
        $this->app['config']->set('record.id_type', 'uuidv4');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('record.id_type must be "uuid" or "integer", got "uuidv4"');

        RecordConfigService::idType();
    }

    /** @test */
    public function it_throws_on_a_non_string_value(): void
    {
        $this->app['config']->set('record.id_type', 123);

        $this->expectException(InvalidArgumentException::class);

        RecordConfigService::idType();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/RecordConfigServiceIdTypeTest.php`

Expected: FAIL with `Call to undefined method Sopheak\Core\Services\RecordConfigService::idType()`.

- [ ] **Step 3: Add the config key**

In `config/record.php`, insert after the `'tenant_column_type'` line (near line 49), keeping the existing comment-block style:

```php
    /*
    |--------------------------------------------------------------------------
    | Bundled Module ID Type
    |--------------------------------------------------------------------------
    |
    | Controls the primary key type for the package's own sp_permissions and
    | sp_roles tables, and for the foreign key columns that reference them.
    |
    | Supported values: 'integer' (default) or 'uuid'.
    |
    | Set this to 'uuid' if your project uses UUID primary keys, so the roles
    | and permissions API surface matches the rest of your tables.
    |
    | This is read only when the package migrations first run. Changing it on a
    | project that has already migrated will NOT alter existing tables.
    |
    | Not governed by this setting:
    | - sp_attachments, sp_document_folders and sp_webhook_* always use uuid.
    | - The pivot ids (sp_role_permissions, sp_model_has_roles,
    |   sp_model_permissions) and sp_audit_logs.id are always auto-incrementing
    |   integers. Nothing references them and their insert paths supply no id.
    |
    */
    'id_type' => 'integer', // uuid|integer
```

- [ ] **Step 4: Implement `idType()`**

In `src/Services/RecordConfigService.php`, add the method directly after `tenantColumnType()` (around line 28). Add `use InvalidArgumentException;` to the file's import block if it is not already present.

```php
    /**
     * Primary key type for the bundled sp_permissions and sp_roles tables.
     *
     * Config is a system boundary, so an unrecognized value fails loudly here
     * rather than silently falling back to integer, which would be expensive
     * to discover once tables are already migrated.
     *
     * @throws InvalidArgumentException when the configured value is not
     *                                  'uuid' or 'integer'
     */
    public static function idType(): string
    {
        $configured = config('record.id_type', 'integer');

        if (!is_string($configured)) {
            throw new InvalidArgumentException(sprintf(
                'record.id_type must be "uuid" or "integer", got %s',
                get_debug_type($configured)
            ));
        }

        $normalized = strtolower(trim($configured));

        if (!in_array($normalized, ['uuid', 'integer'], true)) {
            throw new InvalidArgumentException(sprintf(
                'record.id_type must be "uuid" or "integer", got "%s"',
                $configured
            ));
        }

        return $normalized;
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/RecordConfigServiceIdTypeTest.php`

Expected: PASS, 5 tests.

- [ ] **Step 6: Confirm nothing else regressed**

Run: `vendor/bin/phpunit`

Expected: PASS. Adding an unused config key and an uncalled method must not change any existing behavior. If anything fails here, stop and investigate before continuing.

- [ ] **Step 7: Commit**

```bash
git add config/record.php src/Services/RecordConfigService.php tests/Unit/RecordConfigServiceIdTypeTest.php
git commit -m "feat: add record.id_type config and RecordConfigService::idType()"
```

---

## Task 2: `MigrationIdHelper`

Renders the three column roles. Keeping them as three named methods means every migration call site declares which role it is, which is the distinction the whole feature turns on.

**Files:**
- Create: `src/Database/MigrationIdHelper.php`
- Test: `tests/Unit/MigrationIdHelperTest.php`

**Interfaces:**
- Consumes: `RecordConfigService::idType(): string` from Task 1.
- Produces:
  - `MigrationIdHelper::primary(Blueprint $table, string $column = 'id'): void`
  - `MigrationIdHelper::foreign(Blueprint $table, string $column): ColumnDefinition`
  - `MigrationIdHelper::morph(Blueprint $table, string $column): ColumnDefinition`

  `foreign()` and `morph()` return the `ColumnDefinition` so callers can chain `->index()` / `->nullable()`. `primary()` returns void because `->primary()` is already applied in the uuid branch. Used by Tasks 4 and 5.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/MigrationIdHelperTest.php`. It asserts against the real SQLite schema rather than mocking Blueprint, so it verifies the columns are genuinely usable.

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Database\MigrationIdHelper;
use Sopheak\Core\Tests\TestCase;

class MigrationIdHelperTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function primary_creates_an_autoincrement_integer_by_default(): void
    {
        $this->app['config']->set('record.id_type', 'integer');

        Schema::create('helper_probe', function (Blueprint $table): void {
            MigrationIdHelper::primary($table);
        });

        DB::table('helper_probe')->insert([]);

        $this->assertSame(1, (int) DB::table('helper_probe')->value('id'));
    }

    /** @test */
    public function primary_creates_a_uuid_key_when_configured(): void
    {
        $this->app['config']->set('record.id_type', 'uuid');

        Schema::create('helper_probe', function (Blueprint $table): void {
            MigrationIdHelper::primary($table);
        });

        $uuid = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';
        DB::table('helper_probe')->insert(['id' => $uuid]);

        $this->assertSame($uuid, DB::table('helper_probe')->value('id'));
    }

    /** @test */
    public function foreign_accepts_an_integer_by_default(): void
    {
        $this->app['config']->set('record.id_type', 'integer');

        Schema::create('helper_probe', function (Blueprint $table): void {
            MigrationIdHelper::foreign($table, 'role_id')->index();
        });

        DB::table('helper_probe')->insert(['role_id' => 42]);

        $this->assertSame(42, (int) DB::table('helper_probe')->value('role_id'));
    }

    /** @test */
    public function foreign_accepts_a_uuid_when_configured(): void
    {
        $this->app['config']->set('record.id_type', 'uuid');

        Schema::create('helper_probe', function (Blueprint $table): void {
            MigrationIdHelper::foreign($table, 'role_id')->index();
        });

        $uuid = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';
        DB::table('helper_probe')->insert(['role_id' => $uuid]);

        $this->assertSame($uuid, DB::table('helper_probe')->value('role_id'));
    }

    /** @test */
    public function morph_holds_both_key_shapes_regardless_of_setting(): void
    {
        foreach (['integer', 'uuid'] as $idType) {
            $this->app['config']->set('record.id_type', $idType);

            Schema::dropIfExists('helper_probe');
            Schema::create('helper_probe', function (Blueprint $table): void {
                MigrationIdHelper::morph($table, 'model_id')->index();
            });

            DB::table('helper_probe')->insert(['model_id' => '42']);
            DB::table('helper_probe')->insert([
                'model_id' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            ]);

            $this->assertSame(
                2,
                DB::table('helper_probe')->count(),
                "morph() should accept both key shapes under id_type={$idType}"
            );
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('helper_probe');

        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/MigrationIdHelperTest.php`

Expected: FAIL with `Class "Sopheak\Core\Database\MigrationIdHelper" not found`.

- [ ] **Step 3: Implement the helper**

Create `src/Database/MigrationIdHelper.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Sopheak\Core\Services\RecordConfigService;

/**
 * Renders id columns for the package's own migrations.
 *
 * Three distinct column roles exist, and conflating them is the bug this
 * class prevents:
 *
 * - primary()  the pk of sp_permissions / sp_roles, governed by record.id_type
 * - foreign()  a reference to one of those pks, must match their type
 * - morph()    a reference to an arbitrary CLIENT model, whose key type is not
 *              knowable from package config, so it is always a string
 *
 * Surrogate pivot ids and sp_audit_logs.id use none of these. They are always
 * bigIncrements because nothing references them and their insert paths supply
 * no id value.
 */
class MigrationIdHelper
{
    /**
     * Primary key for a table governed by record.id_type.
     */
    public static function primary(Blueprint $table, string $column = 'id'): void
    {
        if (self::isUuid()) {
            $table->uuid($column)->primary();

            return;
        }

        $table->bigIncrements($column);
    }

    /**
     * Foreign key referencing a governed primary key.
     *
     * Returned so callers can chain ->index() / ->nullable().
     */
    public static function foreign(Blueprint $table, string $column): ColumnDefinition
    {
        return self::isUuid()
            ? $table->uuid($column)
            : $table->unsignedBigInteger($column);
    }

    /**
     * Reference to an arbitrary client-owned model.
     *
     * Always a string: it must hold a uuid or an integer key with equal ease,
     * and the package cannot know which the client uses. This mirrors the
     * existing sp_attachment_links.record_id column.
     */
    public static function morph(Blueprint $table, string $column): ColumnDefinition
    {
        return $table->string($column);
    }

    private static function isUuid(): bool
    {
        return RecordConfigService::idType() === 'uuid';
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/MigrationIdHelperTest.php`

Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Database/MigrationIdHelper.php tests/Unit/MigrationIdHelperTest.php
git commit -m "feat: add MigrationIdHelper for the three package id column roles"
```

---

## Task 3: `HasConfigurableKey` trait on `Role` and `Permission`

Under `id_type = 'uuid'` the `sp_roles.id` column has no database default, so Eloquent must supply one. Laravel's defaults (`$incrementing = true`, `$keyType = 'int'`) are also wrong for a uuid key and break `find()` and relation loading.

**Files:**
- Create: `src/Authorization/Traits/HasConfigurableKey.php`
- Modify: `src/Authorization/Models/Role.php`
- Modify: `src/Authorization/Models/Permission.php`
- Test: covered by Task 4's `tests/Feature/IdTypeUuidTest.php`

**Interfaces:**
- Consumes: `RecordConfigService::idType(): string` from Task 1.
- Produces: trait `Sopheak\Core\Authorization\Traits\HasConfigurableKey`, overriding `getIncrementing(): bool` and `getKeyType(): string`, and booting a `creating` hook that fills the key with `(string) Str::uuid()`.

Because this task's observable behavior needs the uuid schema from Task 4, its verification lives in Task 4. Under the default `integer` setting the trait is a no-op, which Step 3 below confirms.

- [ ] **Step 1: Create the trait**

Create `src/Authorization/Traits/HasConfigurableKey.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Sopheak\Core\Services\RecordConfigService;

/**
 * Resolves a model's key behavior from record.id_type at runtime.
 *
 * Eloquent's defaults assume an auto-incrementing integer key. Under
 * record.id_type = 'uuid' all three assumptions are wrong: the column has no
 * database default, so an insert without an id fails; and $keyType = 'int'
 * breaks find() and relation loading for string keys.
 *
 * Eloquent boots trait hooks automatically through bootTraits(), so this
 * composes with a model's own booted() method rather than conflicting with it.
 */
trait HasConfigurableKey
{
    public static function bootHasConfigurableKey(): void
    {
        static::creating(function (Model $model): void {
            if (RecordConfigService::idType() !== 'uuid') {
                return;
            }

            $keyName = $model->getKeyName();

            if ($model->getAttribute($keyName) === null) {
                $model->setAttribute($keyName, (string) Str::uuid());
            }
        });
    }

    public function getIncrementing(): bool
    {
        return RecordConfigService::idType() !== 'uuid';
    }

    public function getKeyType(): string
    {
        return RecordConfigService::idType() === 'uuid' ? 'string' : 'int';
    }
}
```

- [ ] **Step 2: Apply the trait to both models**

In `src/Authorization/Models/Role.php`, add the import alongside the existing imports:

```php
use Sopheak\Core\Authorization\Traits\HasConfigurableKey;
```

and add the `use` statement as the first line of the class body, immediately before `protected $table = 'sp_roles';`:

```php
class Role extends Model
{
    use HasConfigurableKey;

    protected $table = 'sp_roles';
```

In `src/Authorization/Models/Permission.php`, add the same import and the same `use HasConfigurableKey;` line immediately before `protected $table = 'sp_permissions';`.

- [ ] **Step 3: Verify the default path is unaffected**

Run: `vendor/bin/phpunit tests/Unit/HasRolesTest.php tests/Unit/PermissionRegistrarTest.php`

Expected: PASS. These exercise `Role` and `Permission` under the default `integer` setting, where `getIncrementing()` returns `true`, `getKeyType()` returns `'int'`, and the `creating` hook returns early. Behavior must be byte-for-byte what it was.

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Authorization/Traits/HasConfigurableKey.php src/Authorization/Models/Role.php src/Authorization/Models/Permission.php
git commit -m "feat: resolve Role and Permission key type from record.id_type"
```

---

## Task 4: Permissions migration — governed keys and the `model_id` fix

The core of the feature and of the bug fix. Write the failing bug test first.

**Files:**
- Modify: `database/migrations/2026_05_13_000000_create_sp_permissions_tables.php`
- Modify: `config/permissions.php`
- Test: `tests/Feature/IdTypeUuidTest.php`
- Test: `tests/Feature/IdTypeIntegerDefaultTest.php`
- Test: `tests/Feature/ClientModelReferenceColumnsTest.php`

**Interfaces:**
- Consumes: `MigrationIdHelper::primary/foreign/morph` from Task 2; `HasConfigurableKey` from Task 3; `RecordConfigService::idType()` from Task 1.
- Produces: no new PHP interfaces. Establishes the schema contract the remaining tasks rely on.

- [ ] **Step 1: Write the failing bug test**

Create `tests/Feature/ClientModelReferenceColumnsTest.php`.

The first three tests are the **red tests**: they assert the column type, which
is what actually changes on SQLite. The `UuidKeyedUser` behavioral tests that
follow pass before *and* after the fix on this suite (see the SQLite note in
Global Constraints) — they are the guard for real databases and for the flow
not breaking, not the failure signal.

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Authorization\Traits\HasRoles;
use Sopheak\Core\Tests\TestCase;

class UuidKeyedUser extends Model
{
    use HasRoles;

    protected $table = 'uuid_users';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}

class IntegerKeyedUser extends Model
{
    use HasRoles;

    protected $table = 'int_users';

    protected $guarded = [];

    public $timestamps = false;
}

class ClientModelReferenceColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('uuid_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
        });

        Schema::create('int_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
    }

    /** @test */
    public function model_has_roles_model_id_is_a_string_column(): void
    {
        $this->assertColumnType('sp_model_has_roles', 'model_id', 'varchar');
    }

    /** @test */
    public function model_permissions_model_id_is_a_string_column(): void
    {
        $this->assertColumnType('sp_model_permissions', 'model_id', 'varchar');
    }

    /** @test */
    public function role_id_columns_remain_integers_under_the_default_setting(): void
    {
        $this->assertColumnType('sp_model_has_roles', 'role_id', 'integer');
        $this->assertColumnType('sp_role_permissions', 'permission_id', 'integer');
    }

    /** @test */
    public function a_uuid_keyed_user_can_be_assigned_a_role(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $user = UuidKeyedUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Uuid User',
        ]);

        $user->assignRole('admin');

        $this->assertTrue($user->hasRole('admin'));
    }

    /** @test */
    public function a_uuid_keyed_user_resolves_permissions_through_its_role(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        $role->givePermissionTo($this->createPermission('posts.edit'));

        $user = UuidKeyedUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Uuid User',
        ]);
        $user->assignRole('editor');

        $this->assertTrue($user->hasPermissionTo('posts.edit'));
    }

    /** @test */
    public function a_uuid_keyed_user_can_hold_a_direct_permission(): void
    {
        $this->createPermission('posts.publish');

        $user = UuidKeyedUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Uuid User',
        ]);
        $user->givePermissionTo('posts.publish');

        $this->assertTrue($user->hasPermissionTo('posts.publish'));
    }

    /** @test */
    public function two_uuid_keyed_users_do_not_share_roles(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $first = UuidKeyedUser::query()->create(['id' => (string) Str::uuid()]);
        $second = UuidKeyedUser::query()->create(['id' => (string) Str::uuid()]);

        $first->assignRole('admin');

        $this->assertTrue($first->hasRole('admin'));
        $this->assertFalse($second->hasRole('admin'));
    }

    /** @test */
    public function an_integer_keyed_user_still_works(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $user = IntegerKeyedUser::query()->create(['name' => 'Int User']);

        $user->assignRole('admin');

        $this->assertTrue($user->hasRole('admin'));
    }

    /** @test */
    public function integer_and_uuid_keyed_users_do_not_collide(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);
        Role::query()->create(['name' => 'viewer', 'guard_name' => 'api']);

        $intUser = IntegerKeyedUser::query()->create(['name' => 'Int User']);
        $uuidUser = UuidKeyedUser::query()->create(['id' => (string) Str::uuid()]);

        $intUser->assignRole('admin');
        $uuidUser->assignRole('viewer');

        $this->assertTrue($intUser->hasRole('admin'));
        $this->assertFalse($intUser->hasRole('viewer'));
        $this->assertTrue($uuidUser->hasRole('viewer'));
        $this->assertFalse($uuidUser->hasRole('admin'));
    }

    private function createPermission(string $name): string
    {
        \Sopheak\Core\Authorization\Models\Permission::query()->create([
            'name' => $name,
            'guard_name' => 'api',
        ]);

        return $name;
    }

    private function assertColumnType(string $table, string $column, string $expected): void
    {
        $columns = collect(Schema::getColumns($table))->keyBy('name');

        $this->assertTrue(
            $columns->has($column),
            "{$table} should have a {$column} column"
        );

        $this->assertSame(
            $expected,
            $columns[$column]['type'],
            "{$table}.{$column} should be {$expected}"
        );
    }
}
```

- [ ] **Step 2: Run the test to verify the schema assertions fail**

Run: `vendor/bin/phpunit tests/Feature/ClientModelReferenceColumnsTest.php`

Expected, precisely:

- `model_has_roles_model_id_is_a_string_column` — **FAIL**: `sp_model_has_roles.model_id should be varchar` / `Failed asserting that 'integer' is identical to 'varchar'`
- `model_permissions_model_id_is_a_string_column` — **FAIL**, same message
- `role_id_columns_remain_integers_under_the_default_setting` — **PASS** (guards against over-correcting these to strings)
- all four `uuid_keyed` / `integer_keyed` behavioral tests — **PASS**

The behavioral tests passing here is expected and correct, not a sign the test is wrong. SQLite's type affinity stores a UUID in an INTEGER column as text, so the bug is invisible on this driver. The two schema failures are the red signal.

Record the exact output before continuing.

- [ ] **Step 3: Update the permissions migration**

Rewrite `database/migrations/2026_05_13_000000_create_sp_permissions_tables.php` to:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Database\MigrationIdHelper;
use Sopheak\Core\Services\RecordConfigService;

return new class extends Migration {
    public function up(): void
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $enableTenantId = RecordConfigService::enableTenantId();

        Schema::create('sp_permissions', function (Blueprint $table) {
            MigrationIdHelper::primary($table);
            $table->string('name');
            $table->string('group')->nullable();
            $table->string('guard_name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique('name');
            $table->index('group');
            $table->index(['guard_name', 'name']);
        });

        Schema::create('sp_roles', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            MigrationIdHelper::primary($table);
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable();
                $table->unique(['key', $tenantColumn], 'sp_roles_key_tenant_unique');
            } else {
                $table->unique('key', 'sp_roles_key_unique');
            }
            $table->string('name');
            $table->string('key')->nullable();
            $table->string('guard_name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->nullable()->default(false);
            $table->boolean('is_master')->nullable()->default(false);
            $table->boolean('is_default')->nullable()->default(false);

            $table->timestamps();

            $table->index(['guard_name', 'name']);
            $table->index('name');
            $table->index('is_default');
        });

        Schema::create('sp_role_permissions', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            // Surrogate key: nothing references it, and Eloquent's sync()
            // inserts pivot rows without an id, so it must stay auto-incrementing.
            $table->bigIncrements('id');
            MigrationIdHelper::foreign($table, 'role_id');
            MigrationIdHelper::foreign($table, 'permission_id');
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            $table->timestamps();

            $cols = array_filter(['role_id', 'permission_id', $enableTenantId ? $tenantColumn : null]);
            $table->unique($cols, 'sp_role_permissions_unique');
            $table->foreign('role_id')->references('id')->on('sp_roles')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('sp_permissions')->onDelete('cascade');
        });

        Schema::create('sp_model_has_roles', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            $table->bigIncrements('id');
            $table->string('model_type');
            // Points at an arbitrary client model, whose key may be a uuid or
            // an integer. A string holds either.
            MigrationIdHelper::morph($table, 'model_id');
            MigrationIdHelper::foreign($table, 'role_id');
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index('role_id');
            $cols = array_filter(['model_type', 'model_id', 'role_id', $enableTenantId ? $tenantColumn : null]);
            $table->unique($cols, 'sp_model_has_roles_unique');
            $table->foreign('role_id')->references('id')->on('sp_roles')->onDelete('cascade');
        });

        Schema::create('sp_model_permissions', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            $table->bigIncrements('id');
            $table->string('model_type');
            MigrationIdHelper::morph($table, 'model_id');
            MigrationIdHelper::foreign($table, 'permission_id');
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $cols = array_filter(['model_type', 'model_id', 'permission_id', $enableTenantId ? $tenantColumn : null]);
            $table->unique($cols, 'sp_model_permissions_unique');
            $table->foreign('permission_id')->references('id')->on('sp_permissions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_model_permissions');
        Schema::dropIfExists('sp_model_has_roles');
        Schema::dropIfExists('sp_role_permissions');
        Schema::dropIfExists('sp_roles');
        Schema::dropIfExists('sp_permissions');
    }
};
```

- [ ] **Step 4: Run the bug test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ClientModelReferenceColumnsTest.php`

Expected: PASS, 9 tests. The two schema assertions that failed in Step 2 now report `varchar`.

- [ ] **Step 5: Derive the config column types**

In `config/permissions.php`, add the import at the top of the file alongside the existing `use` statements:

```php
use Sopheak\Core\Services\RecordConfigService;
```

Replace line 147's `'id' => ['type' => 'bigIncrements', 'nullable' => false],` (inside `sp_permissions`) and line 180's identical entry (inside `sp_roles`) with:

```php
                'id' => ['type' => RecordConfigService::idType() === 'uuid' ? 'uuid' : 'bigIncrements', 'nullable' => false],
```

This is what lets the Record API's runtime uuid detection — cursor pagination, the `uuid` validation rule — track the actual schema.

- [ ] **Step 6: Write the default-behavior guard**

Create `tests/Feature/IdTypeIntegerDefaultTest.php`. This is the backward-compatibility proof: with no config set, nothing may have changed.

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Tests\TestCase;

class IdTypeIntegerDefaultTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function governed_tables_use_integer_keys_by_default(): void
    {
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $this->assertIsInt($role->getKey());
        $this->assertSame(1, $role->getKey());
    }

    /** @test */
    public function role_model_reports_incrementing_integer_keys_by_default(): void
    {
        $role = new Role();

        $this->assertTrue($role->getIncrementing());
        $this->assertSame('int', $role->getKeyType());
    }

    /** @test */
    public function surrogate_ids_are_integers_by_default(): void
    {
        // Create the role first: sp_model_has_roles.role_id has a foreign key
        // constraint, and Laravel enables SQLite FK enforcement by default.
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        DB::table('sp_model_has_roles')->insert([
            'model_type' => 'App\\Models\\User',
            'model_id' => '1',
            'role_id' => $role->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, (int) DB::table('sp_model_has_roles')->value('id'));
    }

    /** @test */
    public function governed_columns_are_integers_by_default(): void
    {
        $columns = collect(Schema::getColumns('sp_roles'))->keyBy('name');

        $this->assertSame('integer', $columns['id']['type']);
    }

    /** @test */
    public function attachment_and_webhook_tables_keep_uuid_keys(): void
    {
        $uuid = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

        DB::table('sp_attachments')->insert([
            'id' => $uuid,
            'disk' => 'local',
            'path' => 'a/b.txt',
            'filename' => 'b.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'visibility' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($uuid, DB::table('sp_attachments')->value('id'));
    }
}
```

- [ ] **Step 7: Write the uuid-schema test**

Create `tests/Feature/IdTypeUuidTest.php`. Note `getEnvironmentSetUp` — see the timing note in Global Constraints.

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Tests\TestCase;

class IdTypeUuidTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Must be set here, not in setUp(): Testbench runs this before
        // RefreshDatabase migrates, so it is what the schema is built from.
        $app['config']->set('record.id_type', 'uuid');
    }

    /** @test */
    public function a_role_receives_a_generated_uuid_key(): void
    {
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $this->assertIsString($role->getKey());
        $this->assertTrue(Str::isUuid($role->getKey()));
    }

    /** @test */
    public function a_permission_receives_a_generated_uuid_key(): void
    {
        $permission = Permission::query()->create([
            'name' => 'posts.edit',
            'guard_name' => 'api',
        ]);

        $this->assertTrue(Str::isUuid($permission->getKey()));
    }

    /** @test */
    public function models_report_non_incrementing_string_keys(): void
    {
        $role = new Role();

        $this->assertFalse($role->getIncrementing());
        $this->assertSame('string', $role->getKeyType());
    }

    /** @test */
    public function a_role_can_be_retrieved_by_its_uuid(): void
    {
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'api']);

        $found = Role::query()->find($role->getKey());

        $this->assertNotNull($found);
        $this->assertSame('admin', $found->name);
    }

    /** @test */
    public function role_permission_pivot_writes_succeed_with_uuid_keys(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'posts.edit', 'guard_name' => 'api']);

        $role->givePermissionTo('posts.edit');

        $this->assertTrue($role->hasPermissionTo('posts.edit'));
    }

    /** @test */
    public function surrogate_pivot_ids_stay_auto_incrementing_integers(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'posts.edit', 'guard_name' => 'api']);

        $role->givePermissionTo('posts.edit');

        $pivotId = DB::table('sp_role_permissions')->value('id');

        $this->assertSame(1, (int) $pivotId);
    }

    /** @test */
    public function audit_log_id_stays_an_auto_incrementing_integer(): void
    {
        DB::table('sp_audit_logs')->insert([
            'entity_type' => 'users',
            'entity_id' => '1',
            'event' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, (int) DB::table('sp_audit_logs')->value('id'));
    }

    /** @test */
    public function attachment_tables_still_use_uuid_keys(): void
    {
        $uuid = (string) Str::uuid();

        DB::table('sp_attachments')->insert([
            'id' => $uuid,
            'disk' => 'local',
            'path' => 'a/b.txt',
            'filename' => 'b.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'visibility' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($uuid, DB::table('sp_attachments')->value('id'));
    }
}
```

- [ ] **Step 8: Run both new tests**

Run: `vendor/bin/phpunit tests/Feature/IdTypeUuidTest.php tests/Feature/IdTypeIntegerDefaultTest.php`

Expected: PASS, 13 tests.

Two failures are plausible here and both have known fixes:

- `audit_log_id_stays_an_auto_incrementing_integer` erroring because `sp_audit_logs` does not exist — the audit migration is gated behind `audit.enabled`, which the base `TestCase` sets to `false`. Add `$app['config']->set('audit.enabled', true);` to that test's `getEnvironmentSetUp`.
- A foreign key violation on any direct `DB::table()` insert into a pivot — create the parent `Role` or `Permission` row first. Laravel enables SQLite foreign key enforcement by default.

- [ ] **Step 9: Run the full suite**

Run: `vendor/bin/phpunit`

Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_05_13_000000_create_sp_permissions_tables.php config/permissions.php tests/Feature/ClientModelReferenceColumnsTest.php tests/Feature/IdTypeUuidTest.php tests/Feature/IdTypeIntegerDefaultTest.php
git commit -m "fix: store client model references as strings and honor record.id_type

sp_model_has_roles.model_id and sp_model_permissions.model_id were
unsignedBigInteger, so a client whose User model uses a uuid key could not be
assigned a role at all. Both are now strings, which hold either key shape.

sp_permissions.id and sp_roles.id now follow record.id_type. Pivot surrogate
ids stay auto-incrementing because Eloquent's sync() inserts them without an id."
```

---

## Task 5: Audit migration and config

Same bug class, plus the tenant column ignoring an existing setting.

**Files:**
- Modify: `database/migrations/2025_01_27_000000_create_audit_logs_table.php`
- Modify: `config/audit.php`
- Modify: `tests/Unit/HasRolesTest.php`
- Modify: `tests/Unit/PermissionRegistrarTest.php`
- Test: `tests/Feature/ClientModelReferenceColumnsTest.php` (extended)

**Interfaces:**
- Consumes: `MigrationIdHelper::morph` from Task 2.
- Produces: nothing new.

- [ ] **Step 1: Write the failing audit test**

Append these three methods to `tests/Feature/ClientModelReferenceColumnsTest.php`, before the private `createPermission()` helper. `DB` and the `assertColumnType()` helper are already imported and defined from Task 4.

The schema assertion is the red test, for the same SQLite-affinity reason as Task 4.

```php
    /** @test */
    public function audit_entity_and_user_columns_are_strings(): void
    {
        $this->assertColumnType('sp_audit_logs', 'entity_id', 'varchar');
        $this->assertColumnType('sp_audit_logs', 'user_id', 'varchar');
    }

    /** @test */
    public function audit_logs_accept_a_uuid_entity_and_user(): void
    {
        $entityId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('sp_audit_logs')->insert([
            'entity_type' => 'invoices',
            'entity_id' => $entityId,
            'user_id' => $userId,
            'event' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('sp_audit_logs')->first();

        $this->assertSame($entityId, $row->entity_id);
        $this->assertSame($userId, $row->user_id);
    }

    /** @test */
    public function audit_logs_still_accept_integer_entities_and_users(): void
    {
        DB::table('sp_audit_logs')->insert([
            'entity_type' => 'invoices',
            'entity_id' => '42',
            'user_id' => '7',
            'event' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('sp_audit_logs')->first();

        $this->assertSame('42', (string) $row->entity_id);
        $this->assertSame('7', (string) $row->user_id);
    }
```

- [ ] **Step 2: Run to verify the schema assertion fails**

Run: `vendor/bin/phpunit tests/Feature/ClientModelReferenceColumnsTest.php --filter audit`

Expected:

- `audit_entity_and_user_columns_are_strings` — **FAIL**: `Failed asserting that 'integer' is identical to 'varchar'`
- both behavioral audit tests — **PASS** (SQLite affinity again; they are the real-database guard)

If `sp_audit_logs` does not exist, the audit migration is gated behind `audit.enabled`, which the base `TestCase` sets to `false`. Add a `getEnvironmentSetUp` override to this test class and re-run:

```php
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('audit.enabled', true);
    }
```

- [ ] **Step 3: Update the audit migration**

In `database/migrations/2025_01_27_000000_create_audit_logs_table.php`, add the import:

```php
use Sopheak\Core\Database\MigrationIdHelper;
```

Then replace the tenant block and the two id columns inside `Schema::create`. The tenant column changes from a hardcoded `unsignedBigInteger` to the type `record.tenant_column_type` already declares:

```php
                if (RecordConfigService::enableTenantId()) {
                    $tenantColumn = RecordConfigService::tenantColumn();
                    match (RecordConfigService::tenantColumnType()) {
                        'integer', 'bigint' => $blueprint->unsignedBigInteger($tenantColumn)->nullable(),
                        'uuid' => $blueprint->uuid($tenantColumn)->nullable(),
                        default => $blueprint->string($tenantColumn)->nullable(),
                    };
                    $blueprint->index([$tenantColumn]);
                }

                $blueprint->string('entity_name')->nullable();
                $blueprint->string('entity_type')->nullable();
                // Both reference client-owned records whose key may be a uuid
                // or an integer, so they must be strings.
                MigrationIdHelper::morph($blueprint, 'entity_id')->nullable();
                MigrationIdHelper::morph($blueprint, 'user_id')->nullable();
```

Leave `$blueprint->id();` at the top untouched — `sp_audit_logs.id` is not governed.

- [ ] **Step 4: Update the audit config column types**

In `config/audit.php`, change lines 247-248 from `'integer'` to `'string'`:

```php
                'entity_id' => ['type' => 'string', 'nullable' => true],
                'user_id' => ['type' => 'string', 'nullable' => true],
```

Without this, default validation applies an `integer` rule and rejects UUID values before they reach the database.

- [ ] **Step 5: Run the audit tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/ClientModelReferenceColumnsTest.php`

Expected: PASS, 12 tests.

- [ ] **Step 6: Align the stale test fixtures**

In both `tests/Unit/HasRolesTest.php` and `tests/Unit/PermissionRegistrarTest.php`, inside `createPermissionTables()`, change every `$table->unsignedBigInteger('model_id');` to:

```php
                $table->string('model_id');
```

These blocks are `Schema::hasTable`-guarded and inert, because the package migrations run first via `loadMigrationsFrom`. Updating them keeps the fixtures from contradicting the real schema for the next reader.

- [ ] **Step 7: Run the full suite**

Run: `vendor/bin/phpunit`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2025_01_27_000000_create_audit_logs_table.php config/audit.php tests/Feature/ClientModelReferenceColumnsTest.php tests/Unit/HasRolesTest.php tests/Unit/PermissionRegistrarTest.php
git commit -m "fix: store audit entity_id and user_id as strings

Both reference client-owned records, so a uuid-keyed project could not write an
audit entry. The config also declared them integer, which made default
validation reject uuids before the insert.

The tenant column now honors record.tenant_column_type instead of hardcoding
unsignedBigInteger, matching the other package migrations."
```

---

## Task 6: Documentation

**Files:**
- Modify: `docs/guide/features/feature-record-data-types.md`

**Interfaces:**
- Consumes: everything above. Produces nothing.

- [ ] **Step 1: Add the section**

Append to `docs/guide/features/feature-record-data-types.md`, after the "Relationship Enum Type Values" section. Match the existing compact table style (`|---|---|`).

````markdown
## 5) Bundled Module ID Type (`record.id_type`)

The package's own `sp_permissions` and `sp_roles` tables can use either
auto-incrementing integer or UUID primary keys, so their API surface matches
your project's convention.

```php
// config/record.php
'id_type' => 'integer', // uuid|integer
```

This is read only when the package migrations first run. Changing it on a
project that has already migrated does **not** alter existing tables.

### What it governs

| Table | Column |
|---|---|
| `sp_permissions` | `id` |
| `sp_roles` | `id` |
| `sp_role_permissions` | `role_id`, `permission_id` |
| `sp_model_has_roles` | `role_id` |
| `sp_model_permissions` | `permission_id` |

### What it does not govern

- `sp_attachments`, `sp_document_folders` and `sp_webhook_*` always use `uuid`
  primary keys.
- `sp_role_permissions.id`, `sp_model_has_roles.id`,
  `sp_model_permissions.id` and `sp_audit_logs.id` are always auto-incrementing
  integers. Nothing references them, and their insert paths supply no id.
- Columns pointing at **your** models are always strings, because the package
  cannot know your key type. A string holds a UUID or an integer equally well:
  `sp_model_has_roles.model_id`, `sp_model_permissions.model_id`,
  `sp_audit_logs.entity_id`, `sp_audit_logs.user_id`,
  `sp_attachment_links.record_id`.

This is why a UUID-keyed `User` works with roles and audit logging regardless
of what `id_type` is set to.
````

- [ ] **Step 2: Validate the docs**

Run: `composer docs:validate`

Expected: PASS. If this script enforces frontmatter keywords, add `id_type` and `primary key type` to the file's `keywords` list.

- [ ] **Step 3: Run the full quality gate**

Run: `composer quality`

Expected: PASS on format-check, phpstan, and phpunit. Fix any phpstan findings on the new files before committing — `MigrationIdHelper::foreign()` and `::morph()` return `ColumnDefinition`, so confirm the declared return types resolve under larastan.

- [ ] **Step 4: Commit**

```bash
git add docs/guide/features/feature-record-data-types.md
git commit -m "docs: document record.id_type and client reference columns"
```

---

## Verification Checklist

Run before declaring the work complete. Paste actual output — do not assert from memory.

- [ ] `vendor/bin/phpunit` — full suite passes
- [ ] `composer analyse` — no new phpstan findings
- [ ] `composer format-check` — clean
- [ ] `git log --oneline` shows six focused commits
- [ ] `grep -rn "unsignedBigInteger('model_id')\|unsignedBigInteger('entity_id')\|unsignedBigInteger('user_id')" database/ src/ tests/` returns nothing
- [ ] `grep -rn "SP_ID_TYPE" --include=*.php src config database tests` returns nothing — no env var was introduced
- [ ] With no `record.id_type` set, `sp_permissions.id` and `sp_roles.id` are still auto-incrementing integers
- [ ] `git stash && vendor/bin/phpunit && git stash pop` on a clean checkout of `develop` — confirms the suite was green before these changes, so any failure is attributable

## Known Limitations

**The bug is not reproducible on this test suite.** SQLite's type affinity
stores a UUID string in an `INTEGER` column as text and looks it up correctly,
so the pre-fix behavior is indistinguishable from post-fix here. This was
verified directly, not assumed. The regression protection this plan actually
delivers is the set of schema assertions in
`tests/Feature/ClientModelReferenceColumnsTest.php`, which pin the column types
that MySQL and PostgreSQL enforce. The behavioral tests document intent and
would catch the bug on a real database, but cannot fail on SQLite.

Adding a MySQL or PostgreSQL job to CI is the way to close this gap. It is out
of scope here — there is currently no CI test workflow at all, only
`release.yml` and `security.yml`.

**The PostgreSQL parameter-binding question is likewise unconfirmed.** The
spec's reasoning is that `pdo_pgsql` sends parameters in text format without
type OIDs, so PostgreSQL infers `varchar` from the column context and the
comparison resolves correctly.

The constraint that follows from it is enforceable by review, and must be:
**never join `model_id`, `entity_id`, or `user_id` directly against a client's
integer key column.** PostgreSQL has no `varchar = bigint` operator. The
existing query in `PermissionRegistrar::resolveUserPermissions` is safe — its
only join is `sp_role_permissions.role_id = sp_model_has_roles.role_id`, two
package columns of identical type.
