# Expand record.id_type Governance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `record.id_type` govern the primary keys of attachments, webhooks, the three pivot surrogate tables, and `sp_audit_logs`, instead of stopping at `sp_permissions`/`sp_roles`.

**Architecture:** Reuse the mechanism already built for `sp_permissions`/`sp_roles` (`MigrationIdHelper::primary()`/`::foreign()` in migrations, `RecordConfigService::idType() === 'uuid' ? 'uuid' : 'bigIncrements'` in config) for attachments/webhooks/audit. For the pivot tables, whose write path (`sync()`/`attach()`) never supplies an id, register a custom `Pivot`/`MorphPivot` subclass via `->using()` on each relationship so Eloquent's `creating` event fires, and reuse the existing `HasConfigurableKey` trait to generate the uuid there.

**Tech Stack:** PHP 8.3, Laravel/Illuminate (Eloquent, migrations), PHPUnit 10/11, Orchestra Testbench.

## Global Constraints

- `record.id_type` default remains `'integer'`. Only two modules change their *default* behavior as a result of this plan: attachments and webhooks (an accepted breaking change per the design doc). Permissions, roles, pivots, and audit were already integer by default, so governing them changes nothing for an install that never sets `id_type`.
- `sp_attachment_links.id` and the four client-reference columns (`sp_model_has_roles.model_id`, `sp_model_permissions.model_id`, `sp_audit_logs.entity_id`, `sp_audit_logs.user_id`) are explicitly **not** touched by this plan — they stay exactly as they are today.
- Every new/changed migration must keep using `MigrationIdHelper::primary()`/`::foreign()` (`src/Database/MigrationIdHelper.php`), never re-inline the `uuid()`/`bigIncrements()` branching.
- Test commands in this repo: `vendor/bin/phpunit --filter=<TestClass>` for a single class, `vendor/bin/phpunit` for the full suite.
- Design doc: `docs/superpowers/specs/2026-08-05-expand-id-type-governance-design.md`. Original (still partially in force) spec: `docs/superpowers/specs/2026-08-04-configurable-id-type-design.md`.

---

### Task 1: Attachments and webhooks follow `record.id_type`

**Files:**
- Modify: `database/migrations/2024_01_01_000000_create_sp_attachments_tables.php`
- Modify: `database/migrations/2024_01_01_000001_create_sp_webhooks_tables.php`
- Modify: `config/sp-attachments.php:101` and `config/sp-attachments.php:301`
- Modify: `config/sp-webhooks.php:48`, `config/sp-webhooks.php:64`, `config/sp-webhooks.php:79`
- Modify: `src/Http/Controllers/AttachmentUploadController.php:467`, `:766`, `:869`
- Modify: `src/Triggers/WebhookTrigger.php:117`
- Delete: `tests/Feature/PackageTableUuidPrimaryKeyTest.php`
- Test: `tests/Feature/PackageTableGovernedIdTypeTest.php` (new, abstract base)
- Test: `tests/Feature/PackageTableIdTypeIntegerTest.php` (new)
- Test: `tests/Feature/PackageTableIdTypeUuidTest.php` (new)

**Interfaces:**
- Consumes: `MigrationIdHelper::primary(Blueprint $t, string $col = 'id'): void`, `MigrationIdHelper::foreign(Blueprint $t, string $col): ColumnDefinition` (both already exist, unchanged, `src/Database/MigrationIdHelper.php`). `RecordConfigService::idType(): string` (already exists, unchanged).
- Produces: nothing new consumed by later tasks — this task's pattern (migration + config pair) is mirrored independently in Task 3 for audit.

- [ ] **Step 1: Write the failing tests**

Delete the old test and replace it with an abstract base plus two concrete subclasses, so the same assertions run once under the default (`integer`) config and once under `uuid`.

Delete `tests/Feature/PackageTableUuidPrimaryKeyTest.php` entirely, then create:

`tests/Feature/PackageTableGovernedIdTypeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The package's own attachments/webhooks tables must follow record.id_type,
 * the same as sp_permissions/sp_roles: MigrationIdHelper::primary() in their
 * migrations produces uuid('id')->primary() only when id_type is 'uuid',
 * bigIncrements otherwise.
 *
 * Run via the two concrete subclasses (integer default, uuid), not directly.
 *
 * The declaration is the thing under test here, not round-trip behaviour:
 * SQLite's type affinity would let a wrong declaration pass a plain insert
 * test, so the assertions read the registered config directly as well.
 */
abstract class PackageTableGovernedIdTypeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Package tables whose migration uses MigrationIdHelper::primary().
     */
    private const GOVERNED_TABLES = [
        'sp_webhook_endpoints',
        'sp_webhook_subscriptions',
        'sp_webhook_deliveries',
        'sp_attachments',
        'sp_attachment_folders',
    ];

    abstract protected function idType(): string;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Must be set here, not in setUp(): Testbench runs this before
        // RefreshDatabase migrates, so it is what the schema is built from.
        $app['config']->set('record.id_type', $this->idType());
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The base TestCase blanks these so unrelated tests get a clean route
        // regex. Re-require the shipped files: this is the config a real app
        // boots with, and it is exactly what is being asserted.
        $attachments = require __DIR__ . '/../../config/sp-attachments.php';
        $webhooks = require __DIR__ . '/../../config/sp-webhooks.php';

        Config::set('attachments.enabled', true);
        Config::set('attachments.tables', $attachments['tables']);
        Config::set('webhooks.enabled', true);
        Config::set('webhooks.tables', $webhooks['tables']);

        SchemaRegistryUtils::refresh();
    }

    /**
     * @test
     *
     * @dataProvider governedTables
     */
    public function a_governed_package_table_declares_the_configured_id_type(string $table): void
    {
        $config = SchemaRegistryUtils::getTable($table);

        $this->assertInstanceOf(RecordTableType::class, $config, $table . ' should be registered');

        $pk = $config->primaryKey ?? 'id';
        $isUuid = SchemaRegistryUtils::isUuidColumnType($config->columns[$pk] ?? null);

        if ('uuid' === $this->idType()) {
            $this->assertTrue($isUuid, sprintf(
                '%s.%s should be uuid under id_type=uuid; got %s',
                $table,
                $pk,
                var_export($config->columns[$pk]['type'] ?? null, true)
            ));
        } else {
            $this->assertFalse($isUuid, sprintf(
                '%s.%s should not be uuid under id_type=integer; got %s',
                $table,
                $pk,
                var_export($config->columns[$pk]['type'] ?? null, true)
            ));
        }
    }

    /**
     * @test
     *
     * @dataProvider governedTables
     */
    public function a_governed_package_table_can_be_created_without_a_client_supplied_id(string $table): void
    {
        $result = app(RecordService::class)->createRecord($table, $this->payloadFor($table), null);

        if ('uuid' === $this->idType()) {
            $this->assertTrue(
                Str::isUuid((string) $result['id']),
                sprintf('createRecord must generate a uuid for %s under id_type=uuid, got: %s', $table, var_export($result['id'], true))
            );
        } else {
            $this->assertSame(1, (int) $result['id']);
        }

        $this->assertSame(1, DB::table($table)->where('id', $result['id'])->count());
    }

    /** @test */
    public function sp_attachment_links_keeps_its_auto_incrementing_key(): void
    {
        // Counterweight: its migration uses $table->id() unconditionally, so a
        // uuid must never be generated for it regardless of id_type.
        $config = SchemaRegistryUtils::getTable('sp_attachment_links');

        $this->assertFalse(SchemaRegistryUtils::isUuidColumnType($config->columns['id'] ?? null));

        $result = app(RecordService::class)->createRecord('sp_attachment_links', [
            'attachment_id' => (string) Str::uuid(),
            'record_id' => '1',
            'record_type' => 'invoices',
        ], null);

        $this->assertSame(1, (int) $result['id']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function governedTables(): array
    {
        $cases = [];
        foreach (self::GOVERNED_TABLES as $table) {
            $cases[$table] = [$table];
        }

        return $cases;
    }

    /**
     * The minimum not-null, no-default payload each table needs.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(string $table): array
    {
        return match ($table) {
            'sp_webhook_endpoints' => [
                'name' => 'Orders',
                'url' => 'https://example.test/hooks/orders',
                'secret' => 's3cret',
                'is_active' => true,
            ],
            'sp_webhook_subscriptions' => [
                'endpoint_id' => (string) Str::uuid(),
                'table_name' => 'invoices',
                'event' => 'created',
            ],
            'sp_webhook_deliveries' => [
                'endpoint_id' => (string) Str::uuid(),
                'event' => 'created',
                'payload' => json_encode(['ok' => true]),
                'status' => 'pending',
            ],
            'sp_attachments' => [
                'disk' => 'local',
                'path' => 'attachments/a.txt',
                'filename' => 'a.txt',
                'mime_type' => 'text/plain',
                'size' => 12,
                'visibility' => 'private',
            ],
            'sp_attachment_folders' => [
                'name' => 'Invoices',
                'scope' => 'internal',
                'visibility' => 'private',
            ],
            default => [],
        };
    }
}
```

`tests/Feature/PackageTableIdTypeIntegerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

class PackageTableIdTypeIntegerTest extends PackageTableGovernedIdTypeTest
{
    protected function idType(): string
    {
        return 'integer';
    }
}
```

`tests/Feature/PackageTableIdTypeUuidTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

class PackageTableIdTypeUuidTest extends PackageTableGovernedIdTypeTest
{
    protected function idType(): string
    {
        return 'uuid';
    }
}
```

- [ ] **Step 2: Run the tests to verify the integer variant fails**

Run: `vendor/bin/phpunit --filter=PackageTableIdTypeIntegerTest`
Expected: FAIL — `a_governed_package_table_declares_the_configured_id_type` and `a_governed_package_table_can_be_created_without_a_client_supplied_id` fail for all five tables, because the migrations/config still hardcode `uuid` regardless of `id_type`.

Run: `vendor/bin/phpunit --filter=PackageTableIdTypeUuidTest`
Expected: PASS — the uuid variant already matches current (hardcoded-uuid) behavior.

- [ ] **Step 3: Update the attachments migration**

In `database/migrations/2024_01_01_000000_create_sp_attachments_tables.php`, add `use Sopheak\Core\Database\MigrationIdHelper;` to the top-of-file `use` block, alongside the existing `use Sopheak\Core\Services\RecordConfigService;` (line 6), then replace the hardcoded id/FK declarations:

Replace:
```php
        Schema::create('sp_document_folders', function (Blueprint $table) use ($tenantColumn, $enableTenantId): void {
            $table->uuid('id')->primary();
```
with:
```php
        Schema::create('sp_document_folders', function (Blueprint $table) use ($tenantColumn, $enableTenantId): void {
            MigrationIdHelper::primary($table);
```

Replace:
```php
            $table->string('name');
            $table->uuid('parent_id')->nullable()->index();
```
with:
```php
            $table->string('name');
            MigrationIdHelper::foreign($table, 'parent_id')->nullable()->index();
```

Replace:
```php
        Schema::create('sp_attachments', function (Blueprint $table) use ($tenantColumn, $enableTenantId): void {
            $table->uuid('id')->primary();
```
with:
```php
        Schema::create('sp_attachments', function (Blueprint $table) use ($tenantColumn, $enableTenantId): void {
            MigrationIdHelper::primary($table);
```

Replace:
```php
            $table->uuid('folder_id')->nullable()->index();
```
with:
```php
            MigrationIdHelper::foreign($table, 'folder_id')->nullable()->index();
```

Leave `sp_attachment_links` (its `$table->id()` and `$table->uuid('attachment_id')->index()`) untouched — `id` stays ungoverned, but `attachment_id` is a foreign key into `sp_attachments.id`, which is now governed. Change **only** the type call, not the surrogate `id`:

Replace:
```php
            $table->uuid('attachment_id')->index();
```
with:
```php
            MigrationIdHelper::foreign($table, 'attachment_id')->index();
```

- [ ] **Step 4: Update the webhooks migration**

In `database/migrations/2024_01_01_000001_create_sp_webhooks_tables.php`, add `use Sopheak\Core\Database\MigrationIdHelper;` to the top-of-file `use` block (alongside `RecordConfigService`), then replace each of the three `$table->uuid('id')->primary();` lines with `MigrationIdHelper::primary($table);`, and each of the two `$table->uuid('endpoint_id')->index();` lines with `MigrationIdHelper::foreign($table, 'endpoint_id')->index();`.

- [ ] **Step 5: Update the attachments and webhooks config**

In `config/sp-attachments.php:101`, replace:
```php
                // uuid, matching uuid('id')->primary() in
                // 2024_01_01_000000_create_sp_attachments_tables.
                'id' => ['type' => 'uuid', 'nullable' => false],
```
with:
```php
                // Governed by record.id_type — see MigrationIdHelper::primary()
                // in 2024_01_01_000000_create_sp_attachments_tables.
                'id' => ['type' => RecordConfigService::idType() === 'uuid' ? 'uuid' : 'bigIncrements', 'nullable' => false],
```

In `config/sp-attachments.php:301` (the `sp_attachment_folders` entry), replace:
```php
                // uuid: the table is created as sp_document_folders with
                // uuid('id')->primary() and renamed by
                // 2026_08_02_000000_rename_sp_document_folders_table.
                'id' => ['type' => 'uuid', 'nullable' => false],
```
with:
```php
                // Governed by record.id_type — see MigrationIdHelper::primary()
                // in 2024_01_01_000000_create_sp_attachments_tables. The table
                // is created as sp_document_folders and renamed by
                // 2026_08_02_000000_rename_sp_document_folders_table.
                'id' => ['type' => RecordConfigService::idType() === 'uuid' ? 'uuid' : 'bigIncrements', 'nullable' => false],
```

Confirm `use Sopheak\Core\Services\RecordConfigService;` is already imported at the top of `config/sp-attachments.php` (it must be, since `RecordConfigService::tenantColumn()`-style calls already appear elsewhere in the file for the `$routePrefix`/tenant logic — add the import if it is missing).

In `config/sp-webhooks.php`, replace each of the three occurrences:
```php
                'id' => ['type' => 'uuid', 'nullable' => false],
```
with:
```php
                'id' => ['type' => RecordConfigService::idType() === 'uuid' ? 'uuid' : 'bigIncrements', 'nullable' => false],
```
and confirm/add `use Sopheak\Core\Services\RecordConfigService;` at the top of the file.

- [ ] **Step 6: Remove the now-redundant hardcoded uuid assignment**

`RecordService::createRecord()` (`src/Services/RecordService.php:69-71`) already generates a uuid whenever the target column's configured type is `uuid`, and now does the same generically for these tables. The four call sites that pre-assign the id become wrong under `id_type = 'integer'` (a uuid string into a `bigIncrements` column fails outright), so remove the line — not replace it.

In `src/Http/Controllers/AttachmentUploadController.php:467`, remove the line:
```php
            'id' => Str::uuid()->toString(),
```
from the `sp_attachment_folders` payload array (leave every other line in that array unchanged).

Do the same at `src/Http/Controllers/AttachmentUploadController.php:766` and `:869` (both `sp_attachments` payload arrays).

In `src/Triggers/WebhookTrigger.php:117`, remove the line:
```php
                'id' => Str::uuid()->toString(),
```
from the `sp_webhook_deliveries` `$deliveryPayload` array.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter=PackageTableIdTypeIntegerTest`
Expected: PASS

Run: `vendor/bin/phpunit --filter=PackageTableIdTypeUuidTest`
Expected: PASS

Run: `vendor/bin/phpunit --filter=AttachmentUploadControllerTest` (and any other existing attachments/webhooks feature tests found via `grep -rl "sp_attachments\|sp_webhook" tests/Feature/*.php`)
Expected: PASS. If any fail asserting a UUID-shaped id under default config, that test is part of the "known wider blast radius" the design doc calls out — fix its assertion to expect an auto-incrementing integer id under default `id_type`, matching the new intentional default.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2024_01_01_000000_create_sp_attachments_tables.php \
        database/migrations/2024_01_01_000001_create_sp_webhooks_tables.php \
        config/sp-attachments.php config/sp-webhooks.php \
        src/Http/Controllers/AttachmentUploadController.php \
        src/Triggers/WebhookTrigger.php \
        tests/Feature/PackageTableGovernedIdTypeTest.php \
        tests/Feature/PackageTableIdTypeIntegerTest.php \
        tests/Feature/PackageTableIdTypeUuidTest.php
git rm tests/Feature/PackageTableUuidPrimaryKeyTest.php
git commit -m "feat: attachments and webhooks primary keys follow record.id_type"
```

---

### Task 2: Pivot surrogate ids follow `record.id_type`

**Files:**
- Create: `src/Authorization/Models/Pivots/RolePermissionPivot.php`
- Create: `src/Authorization/Models/Pivots/ModelHasRolePivot.php`
- Create: `src/Authorization/Models/Pivots/ModelPermissionPivot.php`
- Modify: `database/migrations/2026_05_13_000000_create_sp_permissions_tables.php`
- Modify: `src/Authorization/Models/Role.php`
- Modify: `src/Authorization/Models/Permission.php`
- Modify: `src/Authorization/Traits/HasRoles.php`
- Modify: `tests/Feature/IdTypeUuidTest.php`
- Test: `tests/Feature/IdTypeIntegerDefaultTest.php` (new)

**Interfaces:**
- Consumes: `HasConfigurableKey` trait (`src/Authorization/Traits/HasConfigurableKey.php`, already exists, unchanged — provides `bootHasConfigurableKey()`, `getIncrementing()`, `getKeyType()`, all keyed off `getKeyName()`/`RecordConfigService::idType()`).
- Produces: `RolePermissionPivot`, `ModelHasRolePivot`, `ModelPermissionPivot` classes, each `use HasConfigurableKey;` and declaring `protected $table`. Not consumed by any later task in this plan, but this is the reusable pattern for any future pivot table that needs the same treatment.

- [ ] **Step 1: Write the failing test**

`tests/Feature/IdTypeUuidTest.php` currently has no `setUp()` override and no client-model fixture. Add both, following the exact pattern `tests/Feature/ClientModelReferenceColumnsTest.php` already uses for its `IntegerKeyedUser` fixture — a plain top-level model class in the test file, backed by a table created in `setUp()`. This lets the new tests exercise `sp_model_has_roles`/`sp_model_permissions` pivot writes without needing a real `users` table.

Add these two imports to the top of `tests/Feature/IdTypeUuidTest.php`, alongside the existing ones:
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Sopheak\Core\Authorization\Traits\HasRoles;
```

Add this class before `class IdTypeUuidTest extends TestCase`:
```php
class TestRoleAssignee extends Model
{
    use HasRoles;

    protected $table = 'test_role_assignees';

    protected $guarded = [];

    public $timestamps = false;
}
```

Add a `setUp()` override inside `IdTypeUuidTest`, right after its existing `getEnvironmentSetUp()`:
```php
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_role_assignees', function (Blueprint $table): void {
            $table->id();
        });
    }
```

Now replace the existing test:

```php
    /** @test */
    public function surrogate_pivot_ids_stay_auto_incrementing_integers(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'posts.edit', 'guard_name' => 'api']);

        $role->givePermissionTo('posts.edit');

        $pivotId = DB::table('sp_role_permissions')->value('id');

        $this->assertSame(1, (int) $pivotId);
    }
```

with:

```php
    /** @test */
    public function role_permission_pivot_id_is_a_generated_uuid(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'posts.edit', 'guard_name' => 'api']);

        $role->givePermissionTo('posts.edit');

        $pivotId = DB::table('sp_role_permissions')->value('id');

        $this->assertTrue(Str::isUuid((string) $pivotId));
    }

    /** @test */
    public function model_has_role_pivot_writes_succeed_with_uuid_keys(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        $assignee = TestRoleAssignee::query()->create();

        $assignee->assignRole('editor');

        $this->assertTrue($assignee->hasRole('editor'));
        $pivotId = DB::table('sp_model_has_roles')->value('id');
        $this->assertTrue(Str::isUuid((string) $pivotId));
    }

    /** @test */
    public function model_permission_pivot_writes_succeed_with_uuid_keys(): void
    {
        Permission::query()->create(['name' => 'posts.edit', 'guard_name' => 'api']);
        $assignee = TestRoleAssignee::query()->create();

        $assignee->givePermissionTo('posts.edit');

        $this->assertTrue($assignee->hasPermissionTo('posts.edit'));
        $pivotId = DB::table('sp_model_permissions')->value('id');
        $this->assertTrue(Str::isUuid((string) $pivotId));
    }
```

Create `tests/Feature/IdTypeIntegerDefaultTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Tests\TestCase;

/**
 * Counterweight to IdTypeUuidTest: proves that leaving record.id_type unset
 * (the default) leaves the newly-governed pivot/audit tables exactly as they
 * were before this plan — auto-incrementing integers. This is the
 * backward-compatibility guarantee the design doc requires for every module
 * except attachments/webhooks (see PackageTableIdTypeIntegerTest for those).
 */
class IdTypeIntegerDefaultTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('audit.enabled', true);
    }

    /** @test */
    public function surrogate_pivot_ids_stay_auto_incrementing_integers_by_default(): void
    {
        $role = Role::query()->create(['name' => 'editor', 'guard_name' => 'api']);
        Permission::query()->create(['name' => 'posts.edit', 'guard_name' => 'api']);

        $role->givePermissionTo('posts.edit');

        $pivotId = DB::table('sp_role_permissions')->value('id');

        $this->assertSame(1, (int) $pivotId);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter=IdTypeUuidTest`
Expected: FAIL. `role_permission_pivot_id_is_a_generated_uuid` fails (pivot id is still an integer). `model_has_role_pivot_writes_succeed_with_uuid_keys` / `model_permission_pivot_writes_succeed_with_uuid_keys` currently pass (nothing about them is governed yet, and the columns are all still integer), which is expected at this point — they'll matter once Step 3 changes the schema.

Run: `vendor/bin/phpunit --filter=IdTypeIntegerDefaultTest`
Expected: PASS (nothing has changed yet for the default case).

- [ ] **Step 3: Migrate the pivot ids**

In `database/migrations/2026_05_13_000000_create_sp_permissions_tables.php`, replace each of the three occurrences of:

```php
            // Surrogate key: nothing references it, and Eloquent's sync()
            // inserts pivot rows without an id, so it must stay auto-incrementing.
            $table->bigIncrements('id');
```
(for `sp_role_permissions`) and the two plain `$table->bigIncrements('id');` lines (for `sp_model_has_roles` and `sp_model_permissions`) with:

```php
            // Surrogate key: nothing references it. Governed by record.id_type
            // via a custom Pivot class (RolePermissionPivot / ModelHasRolePivot /
            // ModelPermissionPivot) registered on the relevant relationship with
            // ->using(), since Eloquent's sync()/attach() only fire model events
            // — and thus HasConfigurableKey's uuid generation — when a custom
            // pivot class is registered.
            MigrationIdHelper::primary($table);
```

(`MigrationIdHelper` is already imported in this file — confirmed at the top of the file.)

At this point, running the test suite would show pivot inserts failing outright under `id_type = 'uuid'` (NOT NULL constraint violation on a `uuid` column with no value supplied) — this is expected and is exactly the gap Step 4 closes. Do not run the suite between Step 3 and Step 4.

- [ ] **Step 4: Create the pivot classes**

Create `src/Authorization/Models/Pivots/RolePermissionPivot.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Sopheak\Core\Authorization\Traits\HasConfigurableKey;

/**
 * Pivot for sp_role_permissions.
 *
 * Registered via ->using() on Role::permissions() and Permission::roles() so
 * that attach()/sync() route through Eloquent's model lifecycle instead of a
 * raw insert — the only way HasConfigurableKey's `creating` hook can generate
 * a uuid for this table's own surrogate `id`.
 */
class RolePermissionPivot extends Pivot
{
    use HasConfigurableKey;

    protected $table = 'sp_role_permissions';
}
```

Create `src/Authorization/Models/Pivots/ModelHasRolePivot.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Sopheak\Core\Authorization\Traits\HasConfigurableKey;

/**
 * Pivot for sp_model_has_roles.
 *
 * Registered via ->using() on HasRoles::roles(). MorphPivot rather than
 * Pivot, since the relation is morphToMany. See RolePermissionPivot for why
 * a custom pivot class is required at all.
 */
class ModelHasRolePivot extends MorphPivot
{
    use HasConfigurableKey;

    protected $table = 'sp_model_has_roles';
}
```

Create `src/Authorization/Models/Pivots/ModelPermissionPivot.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Sopheak\Core\Authorization\Traits\HasConfigurableKey;

/**
 * Pivot for sp_model_permissions.
 *
 * Registered via ->using() on HasRoles::permissions(). See
 * RolePermissionPivot for why a custom pivot class is required at all.
 */
class ModelPermissionPivot extends MorphPivot
{
    use HasConfigurableKey;

    protected $table = 'sp_model_permissions';
}
```

- [ ] **Step 5: Wire the pivot classes onto the relationships**

In `src/Authorization/Models/Role.php`, add the import `use Sopheak\Core\Authorization\Models\Pivots\RolePermissionPivot;` and change:

```php
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'sp_role_permissions',
            'role_id',
            'permission_id'
        );
    }
```

to:

```php
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'sp_role_permissions',
            'role_id',
            'permission_id'
        )->using(RolePermissionPivot::class);
    }
```

In `src/Authorization/Models/Permission.php`, add the import `use Sopheak\Core\Authorization\Models\Pivots\RolePermissionPivot;` and change:

```php
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'sp_role_permissions',
            'permission_id',
            'role_id'
        );
    }
```

to:

```php
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'sp_role_permissions',
            'permission_id',
            'role_id'
        )->using(RolePermissionPivot::class);
    }
```

In `src/Authorization/Traits/HasRoles.php`, add the imports `use Sopheak\Core\Authorization\Models\Pivots\ModelHasRolePivot;` and `use Sopheak\Core\Authorization\Models\Pivots\ModelPermissionPivot;`, then change:

```php
    public function roles(): MorphToMany
    {
        $relation = $this->morphToMany(
            Role::class,
            'model',
            'sp_model_has_roles',
            'model_id',
            'role_id'
        )->withTimestamps();
```

to:

```php
    public function roles(): MorphToMany
    {
        $relation = $this->morphToMany(
            Role::class,
            'model',
            'sp_model_has_roles',
            'model_id',
            'role_id'
        )->using(ModelHasRolePivot::class)->withTimestamps();
```

and change:

```php
    public function permissions(): MorphToMany
    {
        $relation = $this->morphToMany(
            Permission::class,
            'model',
            'sp_model_permissions',
            'model_id',
            'permission_id'
        )->withTimestamps();
```

to:

```php
    public function permissions(): MorphToMany
    {
        $relation = $this->morphToMany(
            Permission::class,
            'model',
            'sp_model_permissions',
            'model_id',
            'permission_id'
        )->using(ModelPermissionPivot::class)->withTimestamps();
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter=IdTypeUuidTest`
Expected: PASS

Run: `vendor/bin/phpunit --filter=IdTypeIntegerDefaultTest`
Expected: PASS

Run: `vendor/bin/phpunit --filter=PermissionRegistrarTest` (and any other existing permissions/roles feature tests: `grep -rl "sp_role_permissions\|sp_model_has_roles\|sp_model_permissions\|assignRole\|givePermissionTo" tests/Feature/*.php tests/Unit/*.php`)
Expected: PASS. These exercise `attach()`/`detach()`/`sync()` under the default `integer` config, where `->using()` still applies but `HasConfigurableKey` is a no-op (`getIncrementing()` returns `true`) — behavior must be unchanged from before this task.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_05_13_000000_create_sp_permissions_tables.php \
        src/Authorization/Models/Pivots/RolePermissionPivot.php \
        src/Authorization/Models/Pivots/ModelHasRolePivot.php \
        src/Authorization/Models/Pivots/ModelPermissionPivot.php \
        src/Authorization/Models/Role.php src/Authorization/Models/Permission.php \
        src/Authorization/Traits/HasRoles.php \
        tests/Feature/IdTypeUuidTest.php tests/Feature/IdTypeIntegerDefaultTest.php
git commit -m "feat: pivot surrogate ids follow record.id_type via custom Pivot classes"
```

---

### Task 3: `sp_audit_logs.id` follows `record.id_type`

**Files:**
- Modify: `database/migrations/2025_01_27_000000_create_audit_logs_table.php`
- Modify: `config/sp-audit.php:244`
- Modify: `src/Services/AuditLogService.php:167`
- Modify: `tests/Feature/IdTypeUuidTest.php`
- Modify: `tests/Feature/IdTypeIntegerDefaultTest.php`

**Interfaces:**
- Consumes: `MigrationIdHelper::primary()` (unchanged), `RecordConfigService::idType()` (unchanged). `Str` is already imported in `AuditLogService.php:13`.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

In `tests/Feature/IdTypeUuidTest.php`, replace:

```php
    /** @test */
    public function audit_log_id_stays_an_auto_incrementing_integer(): void
    {
        // audit.enabled = true (set in getEnvironmentSetUp) is what
        // guarantees the real audit migration ran instead of the
        // TestCase::setUp() fallback fixture — not any column shape. Neither
        // id nor entity_id distinguish the two any more: ->id() is identical
        // in both, and Task 5 made entity_id a string in both. Provenance is
        // proven elsewhere (ClientModelReferenceColumnsTest carries its own
        // audit.enabled override plus schema assertions); this test only
        // checks behavior, not which code path built the table.
        $columns = collect(Schema::getColumns('sp_audit_logs'))->keyBy('name');
        $this->assertSame('varchar', $columns['entity_id']['type']);
        $this->assertSame('integer', $columns['id']['type']);

        DB::table('sp_audit_logs')->insert([
            'entity_type' => 'users',
            'entity_id' => '1',
            'event' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, (int) DB::table('sp_audit_logs')->value('id'));
    }
```

with:

```php
    /** @test */
    public function audit_log_id_is_a_generated_uuid(): void
    {
        // audit.enabled = true (set in getEnvironmentSetUp) is what
        // guarantees the real audit migration ran instead of the
        // TestCase::setUp() fallback fixture — not any column shape.
        $columns = collect(Schema::getColumns('sp_audit_logs'))->keyBy('name');
        $this->assertSame('varchar', $columns['entity_id']['type']);
        $this->assertSame('varchar', $columns['id']['type']);

        AuditLogService::handleAuditDataEntry(
            AuditLogEventEnum::CREATED,
            'users',
            'users',
            ['event' => AuditLogEventEnum::CREATED->value, 'entity_id' => '1', 'new_data' => ['id' => '1']],
        );

        $id = DB::table('sp_audit_logs')->value('id');
        $this->assertTrue(Str::isUuid((string) $id));
    }
```

Add the two new imports this test needs at the top of `tests/Feature/IdTypeUuidTest.php`:
```php
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Services\AuditLogService;
```

In `tests/Feature/IdTypeIntegerDefaultTest.php`, add:

```php
    /** @test */
    public function audit_log_id_stays_an_auto_incrementing_integer_by_default(): void
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
```

(This mirrors the assertion the old `IdTypeUuidTest::audit_log_id_stays_an_auto_incrementing_integer` used to make — it just now runs under the *default* config instead of the `uuid` one.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter=IdTypeUuidTest`
Expected: FAIL — `audit_log_id_is_a_generated_uuid` fails, because `sp_audit_logs.id` is still `bigIncrements` and the config still declares `'integer'`.

Run: `vendor/bin/phpunit --filter=IdTypeIntegerDefaultTest`
Expected: PASS (nothing has changed yet for the default case).

- [ ] **Step 3: Migrate the audit id and update its config**

In `database/migrations/2025_01_27_000000_create_audit_logs_table.php`, replace:

```php
            Schema::create('sp_audit_logs', function (Blueprint $blueprint): void {
                $blueprint->id();
```

with:

```php
            Schema::create('sp_audit_logs', function (Blueprint $blueprint): void {
                MigrationIdHelper::primary($blueprint);
```

(`MigrationIdHelper` is already imported in this file.)

In `config/sp-audit.php:244`, replace:

```php
                'id' => ['type' => 'integer', 'nullable' => false],
```

with:

```php
                'id' => ['type' => RecordConfigService::idType() === 'uuid' ? 'uuid' : 'bigIncrements', 'nullable' => false],
```

Confirm `use Sopheak\Core\Services\RecordConfigService;` is imported at the top of `config/sp-audit.php` (it must already be, since the file references `RecordConfigService` elsewhere for its tenant/queue settings — add it if missing).

- [ ] **Step 4: Generate the id before the raw insert**

In `src/Services/AuditLogService.php`, immediately before line 167's:

```php
        DB::table(RecordConfigService::auditLogModel())->insert($auditData);
```

insert:

```php
        if (RecordConfigService::idType() === 'uuid' && !array_key_exists('id', $auditData)) {
            $auditData['id'] = (string) Str::uuid();
        }

```

(`Str` is already imported at the top of this file, line 13.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter=IdTypeUuidTest`
Expected: PASS

Run: `vendor/bin/phpunit --filter=IdTypeIntegerDefaultTest`
Expected: PASS

Run: `vendor/bin/phpunit --filter=AuditLogServiceTest` (and any other existing audit feature/unit tests: `grep -rl "sp_audit_logs\|AuditLogService" tests/Feature/*.php tests/Unit/*.php`)
Expected: PASS. Confirm no other test asserts `sp_audit_logs.id` is an `integer` column type under a `uuid` config override — fix any that do.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2025_01_27_000000_create_audit_logs_table.php \
        config/sp-audit.php src/Services/AuditLogService.php \
        tests/Feature/IdTypeUuidTest.php tests/Feature/IdTypeIntegerDefaultTest.php
git commit -m "feat: sp_audit_logs.id follows record.id_type"
```

---

### Task 4: Documentation and changelog

**Files:**
- Modify: `docs/guide/features/feature-record-data-types.md:150-238`
- Modify: `CHANGELOG.md`

**Interfaces:** None — this task changes only prose.

- [ ] **Step 1: Update the feature doc's governance tables**

In `docs/guide/features/feature-record-data-types.md`, replace the `### What it governs` table:

```markdown
| Table | Column |
|---|---|
| `sp_permissions` | `id` |
| `sp_roles` | `id` |
| `sp_role_permissions` | `role_id`, `permission_id` |
| `sp_model_has_roles` | `role_id` |
| `sp_model_permissions` | `permission_id` |
```

with:

```markdown
| Table | Column |
|---|---|
| `sp_permissions` | `id` |
| `sp_roles` | `id` |
| `sp_role_permissions` | `id`, `role_id`, `permission_id` |
| `sp_model_has_roles` | `id`, `role_id` |
| `sp_model_permissions` | `id`, `permission_id` |
| `sp_audit_logs` | `id` |
| `sp_attachments` | `id`, `folder_id` |
| `sp_attachment_folders` | `id`, `parent_id` |
| `sp_webhook_endpoints` | `id` |
| `sp_webhook_subscriptions` | `id`, `endpoint_id` |
| `sp_webhook_deliveries` | `id`, `endpoint_id` |
```

Replace the two exclusion sections:

```markdown
### What always stays `bigIncrements`, regardless of the setting

- `sp_role_permissions.id`, `sp_model_has_roles.id`, `sp_model_permissions.id`
  and `sp_audit_logs.id`. These are surrogate keys: nothing references them,
  and their insert paths (including Eloquent's `sync()` for the pivot tables)
  supply no id, so they must stay auto-incrementing regardless of `id_type`.

### What always stays `uuid`, regardless of the setting

- `sp_attachments`, `sp_attachment_folders` (renamed from
  `sp_document_folders`) and `sp_webhook_*` (`sp_webhook_endpoints`,
  `sp_webhook_subscriptions`, `sp_webhook_deliveries`) always use `uuid`
  primary keys. They do not consult `record.id_type`.
```

with:

```markdown
### What always stays `bigIncrements`, regardless of the setting

- `sp_attachment_links.id`. This is a surrogate key: nothing references it,
  and its insert path goes through the generic Record API with no
  client-supplied id, so it stays auto-incrementing regardless of `id_type`.

### How the pivot surrogate ids and `sp_audit_logs.id` are generated under `uuid`

`sp_role_permissions.id`, `sp_model_has_roles.id`, and `sp_model_permissions.id`
are written via Eloquent's `sync()`/`attach()`, which never supplies an id
on a plain insert. Each pivot table has a dedicated `Pivot`/`MorphPivot`
subclass (`src/Authorization/Models/Pivots/`) registered via `->using()` on
its relationship, so writes route through Eloquent's model lifecycle and the
same `HasConfigurableKey` trait `Role`/`Permission` use generates the uuid.

`sp_audit_logs.id` is written via a raw `DB::table(...)->insert()` in
`AuditLogService`, which generates the uuid inline before the insert.

⚠️ **Breaking change for attachments and webhooks:** `sp_attachments`,
`sp_attachment_folders`, `sp_webhook_endpoints`, `sp_webhook_subscriptions`,
and `sp_webhook_deliveries` used to always use `uuid` primary keys regardless
of `record.id_type`. They now follow the setting like every other governed
table. Since the default is `'integer'`, an install that never set
`record.id_type` gets `bigIncrements` keys for these tables on its next fresh
migration instead of `uuid`. If you rely on the existing `uuid` schema, set
`'id_type' => 'uuid'` in `config/sp-record.php` **before** that migration
runs. An already-migrated environment is unaffected either way — this only
matters for new environments.
```

- [ ] **Step 2: Update the CHANGELOG**

In `CHANGELOG.md`, under `## [Unreleased]` → `### Changed`, replace the existing bullet:

```markdown
- **Configurable ID Type**: New `record.id_type` setting (`'integer'` default, or `'uuid'`) governs the primary keys of the package's own `sp_permissions` and `sp_roles` tables and the foreign keys that reference them. The default is a no-op for every existing install. Pick it before the package migrations first run; it is not safe to change afterwards (see the comment in `config/sp-record.php`). `sp_attachments`, `sp_attachment_folders` and `sp_webhook_*` keep uuid keys; the pivot ids and `sp_audit_logs.id` stay auto-incrementing integers. See `docs/guide/features/feature-record-data-types.md`.
```

with:

```markdown
- **Configurable ID Type**: New `record.id_type` setting (`'integer'` default, or `'uuid'`) governs the primary keys of the package's own `sp_permissions`, `sp_roles`, `sp_attachments`, `sp_attachment_folders`, `sp_webhook_endpoints`, `sp_webhook_subscriptions`, `sp_webhook_deliveries`, `sp_audit_logs`, and the three pivot tables' surrogate ids (`sp_role_permissions.id`, `sp_model_has_roles.id`, `sp_model_permissions.id`), plus every foreign key referencing those tables. Pick it before the package migrations first run; it is not safe to change afterwards (see the comment in `config/sp-record.php`). `sp_attachment_links.id` stays an auto-incrementing integer regardless — nothing references it. See `docs/guide/features/feature-record-data-types.md`.
```

Add a new bullet directly after it, still under `### Changed`:

```markdown
- **⚠️ Breaking — Attachments and webhooks default id type**: `sp_attachments`, `sp_attachment_folders`, `sp_webhook_endpoints`, `sp_webhook_subscriptions`, and `sp_webhook_deliveries` used to always use `uuid` primary keys, regardless of `record.id_type`. They now follow that setting like every other governed table, and its default is `'integer'`. Any install that has not explicitly set `record.id_type` will get `bigIncrements` keys for these tables on its **next fresh migration** instead of `uuid`. If you rely on the existing `uuid` schema, set `'id_type' => 'uuid'` in `config/sp-record.php` before that migration runs. Already-migrated environments are physically unaffected either way.
```

- [ ] **Step 3: Commit**

```bash
git add docs/guide/features/feature-record-data-types.md CHANGELOG.md
git commit -m "docs: expand record.id_type governance documentation"
```
