---
title: "Nested-Write Authorization Plan"
description: "Implementation plan for phase S of the MCP on Laravel MCP spec: nested child writes are authorised as direct requests on the child table."
keywords:
  - nested writes
  - authorization
  - security
  - plan
---

# Nested-Write Authorization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A nested child create, update or delete is authorised exactly as if it were a direct request on the child table — its permission and its `can*` flag — on every untrusted entry point (HTTP single and bulk, async bulk, Data MCP).

**Architecture:**
- **One decision function.** `PermissionUtils::actionDecision()` is extracted from the two identical `authorizeAction()` copies, so the parent check, the MCP check and the child check cannot drift.
- **Enforcement scope.** `NestedWriteAuthorizer::enforce()` opens a scope for untrusted entry points. The nested-write processors call `NestedWriteAuthorizer::authorizeChild()` before each child write.
- **Trusted callers are unaffected.** Calls outside a scope — app code and commands calling `RecordService` directly, which never authorise the parent either — keep today's behaviour.

**Tech Stack:** PHP 8.2+, Laravel 12/13, Orchestra Testbench, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-02-mcp-on-laravel-mcp-design.md` — §2.2 (finding), §6.7 (design), §8 phase S, §9 (verification), Q6 (no opt-out).

## Global Constraints

- "Every nested child operation is authorised as if it were a direct request on the child table." (spec §6.7)
- Child create → child `canCreate` plus its create permission; update → `canUpdate` plus update permission; delete → `canDelete` plus delete permission. Attach, detach and pivot updates of an existing related row are covered by the parent's update permission.
- Super admins (`super_admin_callback`) and public child actions (`isPublicAction`) pass, exactly as on a direct request.
- A refused item fails the whole request with **403 `Forbidden`**, and nothing is written. A disabled `can*` flag returns **422**, naming the relationship and the operation.
- No opt-out flag (Q6).
- Maintain backward compatibility for trusted internal callers (`CLAUDE.md`): `RecordService` called outside an enforcement scope behaves as today.
- MCP keeps its error contract: `-32002` Forbidden and `-32001` Unauthenticated as JSON-RPC errors (spec §6.4).
- The human partner commits. Executors skip the commit steps and record that in the ledger.
- Gate: `vendor/bin/phpunit`, `vendor/bin/phpstan analyse src tests`, `vendor/bin/rector process --dry-run --no-progress-bar`, `php bin/validate-docs.php`, `graft build`.

## Review Focus

1. **A trigger adds nested data.** A `beforeCreate` / `beforeUpdate` trigger that adds nested data after `authorizeAction()` must still be checked. Covered because the check runs inside the processors. Pinned by Task 2's trigger test.
2. **Async bulk.** `?async=true` with nested children runs in `ProcessBulkOperationJob`. Children must be authorised there, as the restored user. Pinned by Task 2's job test.
3. **Permission overrides.** A child table with a `permissions` override map (`['create' => ['custom:perm']]`) must be checked against the override, as on a direct request. Pinned by Task 1's override test.
4. **The scope can't leak.** After an exception the scope must be closed. A long-lived worker (Octane, queue) must not stay enforcing. Pinned by Task 2's scope test.
5. **Public children need no user.** A child table with public writes needs neither a user nor a permission. Pinned by Task 1's public test and Task 2's public-child test.

---

### Task 1: Extract the shared action decision

**Files:**
- Modify: `src/Utilities/PermissionUtils.php` (add the constants and `actionDecision()`)
- Modify: `src/Http/Controllers/Concerns/HasControllerHelpers.php:172-220` (`authorizeAction()` delegates to it)
- Modify: `src/Services/McpServerService.php:520-563` (`authorizeAction()` delegates to it)
- Test: `tests/Feature/PermissionActionDecisionTest.php` (create)

**Interfaces — Produces:**
- `PermissionUtils::DECISION_ALLOWED = 'allowed'`, `DECISION_UNAUTHENTICATED = 'unauthenticated'`, `DECISION_FORBIDDEN = 'forbidden'` (public string constants).
- `PermissionUtils::actionDecision(mixed $user, string $table, string $action): string` returns one of the three constants. It is the exact decision `authorizeAction()` makes today: public action → allowed; no user → unauthenticated; `pmsName` null or `[]` → allowed; permissions from the table's override map, or `mapPermissions()` (with `force_delete` independent); super admin → allowed; otherwise `userHasAnyPermission()`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/PermissionActionDecisionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The action decision HTTP, MCP and nested child writes share.
 *
 * @internal
 */
class PermissionActionDecisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $columns = ['id' => ['type' => 'integer', 'nullable' => false], 'name' => ['type' => 'string', 'nullable' => true]];
        Config::set('record.tables', [
            'widgets' => new RecordTableType(table: 'widgets', pmsName: 'widget', hasTenantId: false, columns: $columns),
            'public_widgets' => new RecordTableType(table: 'public_widgets', pmsName: 'public_widget', hasTenantId: false, public: new RecordTablePublic(read: true, write: true), columns: $columns),
            'open_widgets' => new RecordTableType(table: 'open_widgets', pmsName: null, hasTenantId: false, columns: $columns),
            'custom_widgets' => new RecordTableType(table: 'custom_widgets', pmsName: 'custom_widget', hasTenantId: false, permissions: ['create' => ['custom:make']], columns: $columns),
        ]);
        SchemaRegistryUtils::refresh();
    }

    private function user(int $id = 1): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => 'u']);
    }

    /** @test */
    public function a_public_action_is_allowed_without_a_user(): void
    {
        $this->assertSame(PermissionUtils::DECISION_ALLOWED, PermissionUtils::actionDecision(null, 'public_widgets', 'create'));
    }

    /** @test */
    public function no_user_on_an_authenticated_table_is_unauthenticated(): void
    {
        $this->assertSame(PermissionUtils::DECISION_UNAUTHENTICATED, PermissionUtils::actionDecision(null, 'widgets', 'create'));
    }

    /** @test */
    public function a_table_without_a_pms_name_needs_no_permission(): void
    {
        $this->assertSame(PermissionUtils::DECISION_ALLOWED, PermissionUtils::actionDecision($this->user(), 'open_widgets', 'create'));
    }

    /** @test */
    public function the_gate_decides_granted_and_denied(): void
    {
        Gate::before(fn($user, string $ability): ?bool => 'create:widget' === $ability ? true : null);

        $this->assertSame(PermissionUtils::DECISION_ALLOWED, PermissionUtils::actionDecision($this->user(), 'widgets', 'create'));
        $this->assertSame(PermissionUtils::DECISION_FORBIDDEN, PermissionUtils::actionDecision($this->user(), 'widgets', 'delete'));
    }

    /** @test */
    public function a_permission_override_map_is_honoured(): void
    {
        Gate::before(fn($user, string $ability): ?bool => 'custom:make' === $ability ? true : null);

        $this->assertSame(PermissionUtils::DECISION_ALLOWED, PermissionUtils::actionDecision($this->user(), 'custom_widgets', 'create'));
        $this->assertSame(PermissionUtils::DECISION_FORBIDDEN, PermissionUtils::actionDecision($this->user(), 'widgets', 'create'));
    }

    /** @test */
    public function a_super_admin_is_allowed_without_the_permission(): void
    {
        Config::set('permissions.super_admin_callback', fn($user): bool => 7 === (int) $user->id);

        $this->assertSame(PermissionUtils::DECISION_ALLOWED, PermissionUtils::actionDecision($this->user(7), 'widgets', 'delete'));
        $this->assertSame(PermissionUtils::DECISION_FORBIDDEN, PermissionUtils::actionDecision($this->user(8), 'widgets', 'delete'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter PermissionActionDecisionTest`
Expected: errors — `Undefined constant Sopheak\Core\Utilities\PermissionUtils::DECISION_ALLOWED`.

- [ ] **Step 3: Implement** — in `src/Utilities/PermissionUtils.php`, add `use Sopheak\Core\Types\RecordTableType;` to the imports (`SchemaRegistryUtils` is in the same namespace). Then add, after `isSuperAdmin()`:

```php
    public const DECISION_ALLOWED = 'allowed';

    public const DECISION_UNAUTHENTICATED = 'unauthenticated';

    public const DECISION_FORBIDDEN = 'forbidden';

    /**
     * Whether $user may perform $action on $table — the single decision every
     * entry point makes: HTTP and MCP authorizeAction(), and nested child
     * writes (NestedWriteAuthorizer). Each caller maps the result to its own
     * error contract.
     */
    public static function actionDecision(mixed $user, string $table, string $action): string
    {
        if (self::isPublicAction($table, $action)) {
            return self::DECISION_ALLOWED;
        }

        if (!$user) {
            return self::DECISION_UNAUTHENTICATED;
        }

        $tableSchema = SchemaRegistryUtils::getTable($table);
        if ($tableSchema instanceof RecordTableType) {
            if (is_null($tableSchema->pmsName)) {
                return self::DECISION_ALLOWED;
            }

            if (is_array($tableSchema->pmsName) && [] === $tableSchema->pmsName) {
                return self::DECISION_ALLOWED;
            }
        }

        // Use the per-table permission map when it defines this action.
        if ($tableSchema instanceof RecordTableType && is_array($tableSchema->permissions) && isset($tableSchema->permissions[$action])) {
            $permissions = (array) $tableSchema->permissions[$action];
        } elseif ('force_delete' === $action && $tableSchema instanceof RecordTableType && is_array($tableSchema->permissions) && !isset($tableSchema->permissions['force_delete'])) {
            // force_delete has no override — independent, no fallback to 'delete'.
            $permissions = self::mapPermissions($table, 'force_delete');
        } else {
            $permissions = self::mapPermissions($table, $action);
        }

        if (self::isSuperAdmin($user)) {
            return self::DECISION_ALLOWED;
        }

        return self::userHasAnyPermission($user, $permissions, $table, $action)
            ? self::DECISION_ALLOWED
            : self::DECISION_FORBIDDEN;
    }
```

Replace the body of `HasControllerHelpers::authorizeAction()` with:

```php
    private function authorizeAction(string $table, string $action): void
    {
        $decision = PermissionUtils::actionDecision(auth(RecordConfigService::authGuard())->user(), $table, $action);

        if (PermissionUtils::DECISION_UNAUTHENTICATED === $decision) {
            throw new HttpResponseException(
                RecordApiResponseService::errorWrapped('Unauthenticated', RecordApiJsonResponseEnum::UNAUTHORIZED->value)
            );
        }

        if (PermissionUtils::DECISION_FORBIDDEN === $decision) {
            throw new HttpResponseException(
                RecordApiResponseService::errorWrapped('Forbidden', RecordApiJsonResponseEnum::FORBIDDEN->value)
            );
        }
    }
```

Replace the body of `McpServerService::authorizeAction()` with:

```php
    protected function authorizeAction(string $table, string $action): void
    {
        // Same decision as HasControllerHelpers::authorizeAction(), mapped to
        // the MCP error codes.
        $decision = PermissionUtils::actionDecision(auth(RecordConfigService::authGuard())->user(), $table, $action);

        if (PermissionUtils::DECISION_UNAUTHENTICATED === $decision) {
            throw new Exception(message: 'Unauthenticated', code: -32001);
        }

        if (PermissionUtils::DECISION_FORBIDDEN === $decision) {
            throw new Exception(message: 'Forbidden', code: -32002);
        }
    }
```

Remove any imports this leaves unused in either file (PHPStan or Rector will report them).

- [ ] **Step 4: Run the test and the authorization suites**

Run: `vendor/bin/phpunit --filter "PermissionActionDecisionTest|OwnRecords|McpTenantIsolation|PermissionGateIntegration|RelationshipWritePermission"`
Expected: all pass.

- [ ] **Step 5: Full suite**

Run: `vendor/bin/phpunit`
Expected: no failures. The refactor is behaviour-identical.

- [ ] **Step 6: Commit** (the human partner commits; skip and record it in the ledger)

```bash
git add src/Utilities/PermissionUtils.php src/Http/Controllers/Concerns/HasControllerHelpers.php src/Services/McpServerService.php tests/Feature/PermissionActionDecisionTest.php
git commit -m "refactor: share the action authorization decision across HTTP, MCP and nested writes"
```

---

### Task 2: Authorise nested child writes on HTTP and async bulk

**Files:**
- Create: `src/Utilities/NestedWriteAuthorizer.php`
- Create: `src/Exceptions/NestedWriteRefusedException.php`
- Modify: `src/Utilities/RelationshipResolverUtils.php` (`processRelatedData()`, `processBelongsToManyOperation()`, `processHasManyThroughOperation()`)
- Modify: `src/Http/Controllers/Concerns/HasControllerHelpers.php:41-52` (`withinTransaction()` opens the scope)
- Modify: `src/Http/Controllers/Concerns/HasBulkOperations.php` (`bulkRecord()` wraps `recordService->bulkRecord()`)
- Modify: `src/Jobs/ProcessBulkOperationJob.php` (wraps `recordService->bulkRecord()`)
- Test: `tests/Feature/NestedChildWriteAuthorizationTest.php` (create)

**Interfaces:**
- Consumes: `PermissionUtils::actionDecision()` and the `DECISION_*` constants (Task 1).
- Produces:
  - `NestedWriteAuthorizer::enforce(Closure $callback): mixed`, `NestedWriteAuthorizer::isEnforcing(): bool`, and `NestedWriteAuthorizer::authorizeChild(string $parentTable, string $relationship, string $childTable, string $operation): void`. The operation is `'create'`, `'update'` or `'delete'`.
  - `NestedWriteRefusedException extends HttpResponseException`, with `public readonly string $decision`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/NestedChildWriteAuthorizationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Jobs\ProcessBulkOperationJob;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordMetaHasManyThroughType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\NestedWriteAuthorizer;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class NestedChildWriteTrigger
{
    public static function addItem(mixed ...$params): array
    {
        $params[0]->merge(['items' => [['name' => 'FROM-TRIGGER']]]);

        return $params;
    }
}

/**
 * A nested write authorised only the parent. The child table's own
 * permissions and can* flags were never checked, so a user allowed to update
 * an invoice could create, edit and delete invoice items, even on a table
 * configured canCreate/canDelete false.
 *
 * @internal
 */
class NestedChildWriteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> abilities the current user holds */
    private array $granted = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('invoices', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('tags', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('invoice_tag', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('tag_id');
        });
        Schema::create('modules', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('meta', function (Blueprint $t): void {
            $t->id();
            $t->string('owner');
            $t->unsignedBigInteger('owner_id');
            $t->unsignedBigInteger('target_id');
            $t->timestamps();
        });

        DB::table('invoices')->insert(['id' => 1, 'title' => 'INV-1']);
        DB::table('invoice_items')->insert(['id' => 1, 'invoice_id' => 1, 'name' => 'OLD']);
        DB::table('tags')->insert(['id' => 1, 'name' => 'EXISTING']);

        $this->configure();

        Gate::before(fn($user, string $ability): ?bool => in_array($ability, $this->granted, true) ? true : null);
        $this->actingAs(new GenericUser(['id' => 5, 'name' => 'u']), 'api');
    }

    private function configure(bool $itemsCanCreate = true, bool $itemsCanDelete = true, bool $itemsPublic = false, mixed $beforeUpdate = null): void
    {
        $tables = [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoice',
                hasTenantId: false,
                softDeletes: false,
                beforeUpdate: $beforeUpdate,
                relationships: [
                    'items' => new RecordHasManyType(table: 'invoice_items', foreignKey: 'invoice_id', type: RecordRelationshipsEnum::HAS_MANY, localKey: 'id'),
                    'tags' => new RecordMetaBelongsToManyType(related: 'tags', table: 'invoice_tag', foreignPivotKey: 'invoice_id', relatedPivotKey: 'tag_id'),
                    'modules' => new RecordMetaHasManyThroughType(table: 'modules', through: 'meta', firstKey: 'owner_id', secondLocalKey: 'target_id', ownerColumn: 'owner', owner: 'invoice'),
                ],
            ),
            'invoice_items' => new RecordTableType(
                table: 'invoice_items',
                pmsName: 'invoice_item',
                hasTenantId: false,
                softDeletes: false,
                canCreate: $itemsCanCreate,
                canDelete: $itemsCanDelete,
                public: $itemsPublic ? new RecordTablePublic(read: true, write: true) : new RecordTablePublic(),
            ),
            'tags' => new RecordTableType(table: 'tags', pmsName: 'tag', hasTenantId: false, softDeletes: false),
            'modules' => new RecordTableType(table: 'modules', pmsName: 'module', hasTenantId: false, softDeletes: false),
        ];
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    private function updateInvoice(array $nested): \Illuminate\Testing\TestResponse
    {
        return $this->putJson('/api/invoices/1', ['title' => 'INV-1-EDITED'] + $nested);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame('INV-1', DB::table('invoices')->where('id', 1)->value('title'), 'the parent write must roll back too');
        $this->assertSame(['OLD'], DB::table('invoice_items')->pluck('name')->all());
    }

    /** @test */
    public function a_nested_create_update_or_delete_without_the_child_permission_is_forbidden(): void
    {
        $this->granted = ['update:invoice'];

        $this->updateInvoice(['items' => [['name' => 'NEW']]])->assertStatus(403);
        $this->updateInvoice(['items' => [['id' => 1, 'name' => 'EDIT']]])->assertStatus(403);
        $this->updateInvoice(['items' => [['id' => 1, '_delete' => true]]])->assertStatus(403);

        $this->assertNothingWritten();
    }

    /** @test */
    public function a_nested_write_with_the_child_permission_succeeds(): void
    {
        $this->granted = ['update:invoice', 'create:invoice_item', 'update:invoice_item', 'delete:invoice_item'];

        $this->updateInvoice(['items' => [['id' => 1, 'name' => 'EDIT'], ['name' => 'NEW']]])->assertStatus(200);
        $this->assertEqualsCanonicalizing(['EDIT', 'NEW'], DB::table('invoice_items')->pluck('name')->all());

        $this->updateInvoice(['items' => [['id' => 1, '_delete' => true]]])->assertStatus(200);
        $this->assertSame(['NEW'], DB::table('invoice_items')->pluck('name')->all());
    }

    /** @test */
    public function a_disabled_can_flag_on_the_child_table_is_a_422(): void
    {
        $this->granted = ['update:invoice', 'create:invoice_item', 'delete:invoice_item'];
        $this->configure(itemsCanCreate: false, itemsCanDelete: false);

        $this->updateInvoice(['items' => [['name' => 'NEW']]])
            ->assertStatus(422)
            ->assertJsonPath('message', "Cannot create item in relationship 'items' for table 'invoices': canCreate is disabled on table 'invoice_items'.");
        $this->updateInvoice(['items' => [['id' => 1, '_delete' => true]]])->assertStatus(422);

        $this->assertNothingWritten();
    }

    /** @test */
    public function a_super_admin_passes(): void
    {
        $this->granted = ['update:invoice'];
        Config::set('permissions.super_admin_callback', fn($user): bool => 5 === (int) $user->id);

        $this->updateInvoice(['items' => [['name' => 'NEW']]])->assertStatus(200);
        $this->assertDatabaseHas('invoice_items', ['name' => 'NEW']);
    }

    /** @test */
    public function a_public_child_table_needs_no_child_permission(): void
    {
        $this->granted = ['update:invoice'];
        $this->configure(itemsPublic: true);

        $this->updateInvoice(['items' => [['name' => 'NEW']]])->assertStatus(200);
    }

    /** @test */
    public function attaching_an_existing_related_row_needs_only_the_parent_permission(): void
    {
        $this->granted = ['update:invoice'];

        $this->updateInvoice(['tags' => [['id' => 1]]])->assertStatus(200);
        $this->assertDatabaseHas('invoice_tag', ['invoice_id' => 1, 'tag_id' => 1]);
    }

    /** @test */
    public function creating_a_new_related_row_through_a_many_to_many_needs_the_related_create_permission(): void
    {
        $this->granted = ['update:invoice'];

        $this->updateInvoice(['tags' => [['name' => 'NEW-TAG']]])->assertStatus(403);
        $this->assertDatabaseMissing('tags', ['name' => 'NEW-TAG']);

        $this->granted = ['update:invoice', 'create:tag'];
        $this->updateInvoice(['tags' => [['name' => 'NEW-TAG']]])->assertStatus(200);
        $this->assertDatabaseHas('tags', ['name' => 'NEW-TAG']);
    }

    /** @test */
    public function creating_a_has_many_through_target_needs_the_target_create_permission(): void
    {
        $this->granted = ['update:invoice'];

        $this->updateInvoice(['modules' => [['name' => 'NEW-MODULE']]])->assertStatus(403);
        $this->assertDatabaseMissing('modules', ['name' => 'NEW-MODULE']);
    }

    /** @test */
    public function a_nested_create_on_a_new_parent_is_checked_too(): void
    {
        $this->granted = ['create:invoice'];

        $this->postJson('/api/invoices', ['title' => 'INV-2', 'items' => [['name' => 'NEW']]])->assertStatus(403);
        $this->assertDatabaseMissing('invoices', ['title' => 'INV-2']);
    }

    /** @test */
    public function bulk_endpoints_are_checked_per_item(): void
    {
        $this->granted = ['create:invoice', 'update:invoice', 'delete:invoice'];

        $this->postJson('/api/invoices/bulk/create', [['title' => 'INV-2', 'items' => [['name' => 'NEW']]]])->assertStatus(403);
        $this->postJson('/api/invoices/bulk/update', [['id' => 1, 'title' => 'X', 'items' => [['name' => 'NEW']]]])->assertStatus(403);
        $this->postJson('/api/invoices/bulk', [['id' => 1, 'title' => 'X', 'items' => [['name' => 'NEW']]]])->assertStatus(403);

        $this->assertNothingWritten();
        $this->assertDatabaseMissing('invoices', ['title' => 'INV-2']);
    }

    /** @test */
    public function the_async_bulk_job_checks_children_as_the_restored_user(): void
    {
        $this->granted = ['update:invoice'];

        $job = new ProcessBulkOperationJob('update', 'invoices', [['id' => 1, 'title' => 'X', 'items' => [['name' => 'NEW']]]], null, ['user_id' => 5, 'guard' => 'api', 'headers' => [], 'server' => []]);

        try {
            app()->call([$job, 'handle']);
            $this->fail('The job must refuse the nested child write.');
        } catch (\Throwable) {
            // Refused, as on the synchronous endpoint.
        }

        $this->assertNothingWritten();
    }

    /** @test */
    public function a_trigger_that_adds_nested_data_is_still_checked(): void
    {
        $this->granted = ['update:invoice'];
        $this->configure(beforeUpdate: new RecordTableTriggerType(class: NestedChildWriteTrigger::class, functionName: 'addItem'));

        $this->updateInvoice([])->assertStatus(403);
        $this->assertDatabaseMissing('invoice_items', ['name' => 'FROM-TRIGGER']);
    }

    /** @test */
    public function trusted_internal_calls_outside_a_scope_are_unchanged(): void
    {
        // App code and commands call RecordService directly; they never
        // authorise the parent either, so children are not authorised.
        $this->app['auth']->forgetGuards();

        RecordService::executeUpdate('invoices', 1, ['title' => 'INTERNAL', 'items' => [['name' => 'INTERNAL-ITEM']]]);

        $this->assertDatabaseHas('invoice_items', ['name' => 'INTERNAL-ITEM']);
    }

    /** @test */
    public function the_scope_is_closed_after_an_exception(): void
    {
        try {
            NestedWriteAuthorizer::enforce(static function (): never {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertFalse(NestedWriteAuthorizer::isEnforcing());
    }
}
```

  Verified while writing this plan:
  - the bulk routes are `{table}/bulk`, `{table}/bulk/create` and `{table}/bulk/update`;
  - `ProcessBulkOperationJob::__construct(operation, table, items, tenantId, requestContext)`;
  - `handle(RecordService $recordService)`, so `app()->call()` resolves it;
  - `RecordTableTriggerType(class, functionName)`;
  - `beforeUpdate` accepts a `RecordTableTriggerType`.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter NestedChildWriteAuthorizationTest`
Expected: the refusal tests fail, receiving 200 where 403 or 422 is expected, plus a class-not-found error for `NestedWriteAuthorizer`. These pass already: `a_nested_write_with_the_child_permission_succeeds`, `a_super_admin_passes`, `a_public_child_table_needs_no_child_permission`, `attaching_an_existing_related_row_needs_only_the_parent_permission`, `trusted_internal_calls_outside_a_scope_are_unchanged`.

- [ ] **Step 3: Implement**

`src/Exceptions/NestedWriteRefusedException.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Utilities\PermissionUtils;

/**
 * A nested child write the caller is not authorised for. Carries the same
 * 401/403 response a direct request on the child table would get, so the
 * HTTP controllers' HttpResponseException handling returns it unchanged.
 * MCP maps $decision to its own error codes.
 */
final class NestedWriteRefusedException extends HttpResponseException
{
    public function __construct(public readonly string $decision)
    {
        parent::__construct(PermissionUtils::DECISION_UNAUTHENTICATED === $decision
            ? RecordApiResponseService::errorWrapped('Unauthenticated', RecordApiJsonResponseEnum::UNAUTHORIZED->value)
            : RecordApiResponseService::errorWrapped('Forbidden', RecordApiJsonResponseEnum::FORBIDDEN->value));
    }
}
```

`src/Utilities/NestedWriteAuthorizer.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Utilities;

use Closure;
use InvalidArgumentException;
use Sopheak\Core\Exceptions\NestedWriteRefusedException;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordTableType;

/**
 * Authorises nested child writes as direct requests on the child table.
 *
 * A nested write used to authorise only the parent: a user allowed to update
 * an invoice could create, edit and delete its items with no invoice_item
 * permission, even on a table configured canCreate/canDelete false.
 *
 * Enforcement applies inside enforce() only. Untrusted entry points — the
 * HTTP CRUD and bulk endpoints, the async bulk job and the Data MCP — open
 * that scope. Trusted callers (app code and commands calling RecordService
 * directly) never authorise the parent either, so they keep today's
 * behaviour.
 */
final class NestedWriteAuthorizer
{
    private static int $depth = 0;

    public static function enforce(Closure $callback): mixed
    {
        ++self::$depth;

        try {
            return $callback();
        } finally {
            --self::$depth;
        }
    }

    public static function isEnforcing(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @param 'create'|'delete'|'update' $operation
     */
    public static function authorizeChild(string $parentTable, string $relationship, string $childTable, string $operation): void
    {
        if (!self::isEnforcing()) {
            return;
        }

        $childSchema = SchemaRegistryUtils::getTable($childTable);
        if ($childSchema instanceof RecordTableType) {
            $enabled = match ($operation) {
                'create' => $childSchema->canCreate,
                'update' => $childSchema->canUpdate,
                'delete' => $childSchema->canDelete,
            };

            if (!$enabled) {
                throw new InvalidArgumentException(sprintf(
                    "Cannot %s item in relationship '%s' for table '%s': can%s is disabled on table '%s'.",
                    $operation,
                    $relationship,
                    $parentTable,
                    ucfirst($operation),
                    $childTable
                ));
            }
        }

        $decision = PermissionUtils::actionDecision(auth(RecordConfigService::authGuard())->user(), $childTable, $operation);
        if (PermissionUtils::DECISION_ALLOWED !== $decision) {
            throw new NestedWriteRefusedException($decision);
        }
    }
}
```

In `RelationshipResolverUtils.php`, add `NestedWriteAuthorizer::authorizeChild(...)` calls. It is in the same namespace, so no import is needed.

1. `processRelatedData()`, delete branch — directly after
   `self::assertRelationshipOperationAllowed($table, (string) $alias, 'delete', $allowDelete);`:

```php
                    NestedWriteAuthorizer::authorizeChild($table, (string) $alias, $relatedTable, 'delete');
```

2. `processRelatedData()`, create/update — directly after the
   `self::assertRelationshipOperationAllowed($table, (string) $alias, $hasPk ? 'update' : 'create', $hasPk ? $allowUpdate : $allowCreate);`
   call:

```php
                NestedWriteAuthorizer::authorizeChild($table, (string) $alias, $relatedTable, $hasPk ? 'update' : 'create');
```

3. `processBelongsToManyOperation()`, inside `if (!$relatedId) {`, directly after
   `self::assertRelationshipOperationAllowed($table, $alias, 'create', $allowCreate);`:

```php
                NestedWriteAuthorizer::authorizeChild($table, $alias, $relatedTable, 'create');
```

4. `processHasManyThroughOperation()`, inside `if (!$targetId) {`, directly after
   `self::assertRelationshipOperationAllowed($table, $alias, 'create', $allowCreate);`:

```php
                NestedWriteAuthorizer::authorizeChild($table, $alias, $targetTable, 'create');
```

5. `processRelatedData()` — delete the commented-out block that begins
   `// Permission check per related action` (the 13 lines of `//` comments
   before `if ($hasPk) {`). `authorizeChild()` replaces it (spec §6.7).

`HasControllerHelpers::withinTransaction()` — run `$fn` inside the scope.
Only the CRUD and bulk traits call it, never function endpoints:

```php
    private function withinTransaction(callable $fn): mixed
    {
        DB::beginTransaction();
        try {
            // Nested child writes made by this request are authorised as
            // direct requests on the child table (NestedWriteAuthorizer).
            $result = NestedWriteAuthorizer::enforce(fn (): mixed => $fn());
            DB::commit();
            return $result;
        } catch (Throwable $throwable) {
            DB::rollBack();
            throw $throwable;
        }
    }
```

(Add `use Sopheak\Core\Utilities\NestedWriteAuthorizer;`.)

`HasBulkOperations::bulkRecord()` — replace
`$result = $this->recordService->bulkRecord($request, $table, $tenantId, $legacyAction);` with:

```php
            $result = NestedWriteAuthorizer::enforce(fn (): array => $this->recordService->bulkRecord($request, $table, $tenantId, $legacyAction));
```

(Add the import.)

`ProcessBulkOperationJob::handle()` — replace
`$recordService->bulkRecord($request, $this->table, $this->tenantId, $this->operation);` with:

```php
            // The parent was authorised when the request dispatched this job;
            // its nested children are authorised here, as the restored user.
            NestedWriteAuthorizer::enforce(fn (): array => $recordService->bulkRecord($request, $this->table, $this->tenantId, $this->operation));
```

(Add the import. If `bulkRecord()`'s return type is not `array`, use `mixed` in the arrow function.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter NestedChildWriteAuthorizationTest`
Expected: all pass.

- [ ] **Step 5: Mutation check** — temporarily make `authorizeChild()` return immediately (`return;` as its first line). Re-run Step 4: the refusal tests must fail. Restore the code.

- [ ] **Step 6: Full suite**

Run: `vendor/bin/phpunit`
Expected: no failures. An existing nested-write test that fails is either a fixture that doesn't grant the child permission (grant it, ledger it as a ruling) or a real regression (fix the code).

- [ ] **Step 7: Commit** (the human partner commits; skip and record it in the ledger)

```bash
git add src/Utilities/NestedWriteAuthorizer.php src/Exceptions/NestedWriteRefusedException.php src/Utilities/RelationshipResolverUtils.php src/Http/Controllers/Concerns/HasControllerHelpers.php src/Http/Controllers/Concerns/HasBulkOperations.php src/Jobs/ProcessBulkOperationJob.php tests/Feature/NestedChildWriteAuthorizationTest.php
git commit -m "fix(security): authorise nested child writes as direct requests on the child table"
```

---

### Task 3: Authorise nested child writes on the Data MCP

**Files:**
- Modify: `src/Services/McpServerService.php` (`handleToolsCall()`: the create and update branches)
- Test: `tests/Feature/NestedChildWriteAuthorizationTest.php` (add MCP cases)

**Interfaces — Consumes:** `NestedWriteAuthorizer::enforce()`, `NestedWriteRefusedException::$decision` and `PermissionUtils::DECISION_UNAUTHENTICATED` (Tasks 1–2).

- [ ] **Step 1: Write the failing tests** — add to `NestedChildWriteAuthorizationTest`. Also add
  `$app['config']->set('record.mcp.enabled', true); $app['config']->set('record.mcp.read_only', false); $app['config']->set('record.mcp.middleware', []);`
  in a `getEnvironmentSetUp()` override that calls the parent first.

```php
    private function mcpUpdateInvoice(array $payload): array
    {
        return (array) $this->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'update_invoices', 'arguments' => ['id' => 1, 'payload' => $payload]],
        ])->json();
    }

    /** @test */
    public function the_data_mcp_refuses_a_nested_child_write_with_the_forbidden_code_and_writes_nothing(): void
    {
        $this->granted = ['update:invoice'];

        $response = $this->mcpUpdateInvoice(['title' => 'INV-1-EDITED', 'items' => [['name' => 'NEW']]]);

        $this->assertSame(-32002, $response['error']['code'] ?? null);
        $this->assertSame('Forbidden', $response['error']['message'] ?? null);
        $this->assertNothingWritten();
    }

    /** @test */
    public function the_data_mcp_allows_a_nested_child_write_with_the_child_permission(): void
    {
        $this->granted = ['update:invoice', 'create:invoice_item'];

        $response = $this->mcpUpdateInvoice(['title' => 'INV-1-EDITED', 'items' => [['name' => 'NEW']]]);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertDatabaseHas('invoice_items', ['name' => 'NEW']);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter "NestedChildWriteAuthorizationTest::the_data_mcp"`
Expected: the refusal test fails. With no scope the child write succeeds and `error` is absent. The permitted case passes.

- [ ] **Step 3: Implement** — in `McpServerService::handleToolsCall()`, change the `create` / `update` arms of the `match`, and add a dedicated catch **before** the existing `catch (Exception $exception)`:

```php
                'create' => NestedWriteAuthorizer::enforce(fn (): array => DB::transaction(fn (): array => RecordService::executeCreate($table, $payload, $queryParams, $tenantId))),
                'update' => NestedWriteAuthorizer::enforce(fn (): array => DB::transaction(fn (): array => RecordService::executeUpdate($table, $id, $payload, $queryParams, $tenantId))),
```

```php
        } catch (NestedWriteRefusedException $refused) {
            // Same contract as a refused parent (authorizeAction()): a
            // JSON-RPC error, not an isError tool result.
            throw PermissionUtils::DECISION_UNAUTHENTICATED === $refused->decision
                ? new Exception(message: 'Unauthenticated', code: -32001)
                : new Exception(message: 'Forbidden', code: -32002);
        } catch (Exception $exception) {
```

The transaction makes a refused child roll back the parent write as well. HTTP
already ran in a transaction; MCP did not. Add the imports:
`Illuminate\Support\Facades\DB` (if missing),
`Sopheak\Core\Exceptions\NestedWriteRefusedException` and
`Sopheak\Core\Utilities\NestedWriteAuthorizer`.

- [ ] **Step 4: Run the tests and the MCP suites**

Run: `vendor/bin/phpunit --filter "NestedChildWriteAuthorizationTest|Mcp"`
Expected: all pass.

- [ ] **Step 5: Full suite**

Run: `vendor/bin/phpunit`
Expected: no failures.

- [ ] **Step 6: Commit** (the human partner commits; skip and record it in the ledger)

```bash
git add src/Services/McpServerService.php tests/Feature/NestedChildWriteAuthorizationTest.php
git commit -m "fix(security): authorise nested child writes on the Data MCP and roll back refused writes"
```

---

### Task 4: Docs and release notes

**Files:**
- Modify: `docs/guide/api/api-nested-and-bulk-operations.md` (the nested-write scoping list)
- Modify: `CHANGELOG.md` and `docs/changelog.md` (`[Unreleased]` → `### Security`, and `### Notes`)
- Modify: `docs/superpowers/specs/2026-10-02-mcp-on-laravel-mcp-design.md` §2.2 (mark it fixed)

- [ ] **Step 1: Guide** — in `api-nested-and-bulk-operations.md`, in the list under "Children are scoped to what the caller could write on the child table directly:", add this as the first bullet:

```markdown
- Every child create, update or delete needs the child table's own permission
  (`create:` / `update:` / `delete:` + its `pmsName`) and its `canCreate` /
  `canUpdate` / `canDelete` flag, exactly as a direct request would. A missing
  permission fails the whole request with `403`, and a disabled flag with
  `422`; nothing is written. Attaching or detaching an existing related record
  needs only the parent's update permission.
```

- [ ] **Step 2: Changelog** — in both files, under `## [Unreleased]`, add a `### Security` section, with a blank line after the heading in `docs/changelog.md`:

```markdown
- **Nested writes bypassed the child table's authorization**: a nested create, update or delete (for example `PUT /invoices/1` with `items: [...]`) checked only the parent's permission and the relationship's `allow*` flags. A user allowed to update an invoice could create, edit and delete its items with no `invoice_item` permission, even on a table configured `canCreate: false` / `canDelete: false`. Every child operation is now authorised as a direct request on the child table, on the HTTP CRUD and bulk endpoints, the async bulk job and the Data MCP: a missing permission is `403` (MCP `-32002`), a disabled `can*` flag `422`, and nothing is written. The Data MCP now runs create and update in a transaction, so a refused child also rolls back the parent. Trusted internal calls to `RecordService` are unchanged. See `tests/Feature/NestedChildWriteAuthorizationTest.php`.
```

And under `### Notes`, creating the heading if absent:

```markdown
- **Upgrade note — nested writes need the child permission**: users who wrote child rows through a parent (for example invoice items through `PUT /invoices/{id}`) now also need the child table's `create:` / `update:` / `delete:` permission. Grant those permissions to the affected roles before upgrading.
```

- [ ] **Step 3: Spec** — at the end of §2.2, add: `> **Fixed in phase S** — see docs/superpowers/plans/2026-10-02-nested-write-authorization.md.`

- [ ] **Step 4: Final gate**

Run: `graft build && vendor/bin/phpunit && vendor/bin/phpstan analyse src tests && vendor/bin/rector process --dry-run --no-progress-bar && php bin/validate-docs.php`
Expected:
- `phpunit` green;
- PHPStan `[OK]`;
- Rector: no findings in files touched by this plan (pre-existing findings elsewhere are recorded, not fixed);
- `validate-docs`: no new failures beyond the 8 pre-existing frontmatter ones.

- [ ] **Step 5: Commit** (the human partner commits; skip and record it in the ledger)

```bash
git add docs/guide/api/api-nested-and-bulk-operations.md CHANGELOG.md docs/changelog.md docs/superpowers/specs/2026-10-02-mcp-on-laravel-mcp-design.md
git commit -m "docs: nested writes need the child table's permission"
```
