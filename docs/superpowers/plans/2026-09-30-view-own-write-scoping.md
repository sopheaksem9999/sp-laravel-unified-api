---
title: "viewOwn Write Scoping Plan"
description: "Implementation plan extending viewOwn own-records scoping from list-only to single reads, writes, bulk, upsert and MCP, isolating cache keys per owner scope, and deprecating the unwired restrict_to_own_records config."
keywords:
  - viewOwn
  - own records
  - authorization
  - cache isolation
  - plan
---

# viewOwn Write Scoping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A user holding `viewOwn:{pmsName}` can read, update, delete, restore, force-delete and upsert only their own rows, on every transport, cached or not.

**Architecture:** The own-records decision (does the caller hold `viewOwn` for this table, and on which owner column) moves out of `QueryBuilderFiltersUtils::apply()` into one class, `OwnRecordsScope`, which list, by-id read and every write builder then call — the same way each already calls `applyTenantFilter()`. Cache keys fold in an owner-scope token through `RecordCacheService::queryFingerprint()`, the single function every read cache key already takes its fingerprint from. Upsert, whose `ON CONFLICT` cannot carry a `WHERE`, gets a pre-check refusing a conflict with a row owned by someone else.

**Tech Stack:** PHP 8.2+, Laravel 12/13, PHPUnit 11, PHPStan, Rector.

**Spec:** `docs/bug-reports/2026-09-30-view-own-write-scoping.md` (Task 0 writes it).

## Global Constraints

- Users **without** `viewOwn:{pmsName}` see no behaviour change anywhere, including cache keys — their fingerprint must be byte-identical to today's, so no cache churn.
- The owner column is resolved exactly as today: explicit `ownerColumn`, then `record.own_records_owner_columns`, first declared column wins, none declared → no restriction. The existing `OwnRecordsScopingTest` must stay green unmodified.
- A refused by-id read or write returns exactly what a nonexistent id returns — no existence oracle.
- Preserve the existing permission check verbatim: `Auth::check()`, `Auth::user()->id`, `Gate::check($prefix . $separator . $candidate)` over each `pmsName` alias. Changing the guard is out of scope.
- Every write builder is scoped at the same point `applyTenantFilter()` is applied — directly after it.
- Run `vendor/bin/phpunit`, `vendor/bin/phpstan analyse src tests`, and `vendor/bin/rector process --dry-run --no-progress-bar` before each commit. Rector must report no new findings for touched files.
- After code changes run `graphify update .`.

## Review Focus

1. **A user without `viewOwn` (an admin) keeps full access** — reading, updating and deleting another user's row must still work. Pinned in Task 2.
2. **Owner resolved to `user_id` via `ownerColumn`** — writes must scope on `user_id`, not `created_by_id`, or a customer could still edit an admin-created row they don't own. Pinned in Task 2.
3. **Refused write indistinguishable from a missing id** — same status and body as id `999`. Pinned in Task 2.
4. **Two different `viewOwn` users must not share a cached scoped result** — u42's cached list must never be served to u43. Pinned in Task 3.
5. **Upsert whose `match_on` key collides with another user's row** — must be refused, not silently overwrite their data. Pinned in Task 4.

---

### Task 0: Record the findings as a spec

**Files:**
- Create: `docs/bug-reports/2026-09-30-view-own-write-scoping.md`

**Interfaces:**
- Consumes: nothing.
- Produces: the spec the remaining tasks argue from.

- [ ] **Step 1: Write the report**

With YAML frontmatter (`title`, `description`, `keywords`), record three findings and their reproductions, caller = user 42 holding `viewOwn:widget`, row 2 owned by user 99:

| Operation on user 99's row | Result |
|---|---|
| List | `MINE` only — correct on a cold cache |
| List after an admin warmed the cache | `MINE, THEIRS` — cross-user leak |
| `GET /widgets/2` | `THEIRS` returned |
| `PUT /widgets/2` | `200`, row overwritten |
| `DELETE /widgets/2` | `200`, row deleted |
| `restrict_to_own_records = true`, no `viewOwn` | no effect on any operation |

Root causes: owner scoping lives only in `QueryBuilderFiltersUtils::apply()`, reached only by read-builder paths; the write methods build `DB::table()->where($pk, $id)` plus `applyTenantFilter()` only; `RecordCacheService::queryFingerprint()` is `md5(query + body)` with no user, so scoped and unscoped results share keys; `RecordConfigService::restrictToOwnRecords()` has no caller.

- [ ] **Step 2: Verify docs validation**

Run: `php bin/validate-docs.php 2>&1 | grep -c "bug-reports/2026-09-30-view-own-write-scoping"`
Expected: `0`

- [ ] **Step 3: Commit**

```bash
git add docs/bug-reports/2026-09-30-view-own-write-scoping.md
git commit -m "docs: record viewOwn write-scoping and cache-isolation findings"
```

---

### Task 1: Extract `OwnRecordsScope` (pure refactor)

**Files:**
- Create: `src/Utilities/OwnRecordsScope.php`
- Modify: `src/Utilities/QueryBuilderFiltersUtils.php` (scoping block in `apply()`, lines ~207-241; delete `resolveOwnRecordsOwnerColumn()`, lines ~1511-1557)
- Test: `tests/Unit/OwnRecordsScopeTest.php`

**Interfaces:**
- Consumes: `RecordConfigService::table(string): mixed`, `RecordConfigService::ownRecordsPermissionPrefix(): string`, `RecordConfigService::permissionSeparator(): string`, `RecordConfigService::ownRecordsOwnerColumns(): array`, `QueryBuilderFiltersUtils::getAllowedColumns(string): array`.
- Produces:
  - `OwnRecordsScope::ownerColumn(string $table): ?string` — the owner column the current user is restricted to on `$table`, or `null` when no restriction applies.
  - `OwnRecordsScope::apply(\Illuminate\Database\Query\Builder $query, string $table, ?string $qualifiedTable = null): void` — adds `where({qualifiedTable}.{col}, userId)` when a restriction applies. `$qualifiedTable` defaults to `$table`.
  - `OwnRecordsScope::cacheToken(string $table): string` — `''` when no restriction, else `'own:{col}:{userId}'`.

- [ ] **Step 1: Write the failing unit test**

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\OwnRecordsScope;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * @internal
 */
class OwnRecordsScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('record.tables', [
            'widgets' => new RecordTableType(
                table: 'widgets',
                pmsName: 'widget',
                hasTenantId: false,
                columns: [
                    'id' => ['type' => 'integer'],
                    'created_by_id' => ['type' => 'bigInteger'],
                ],
            ),
            'orders' => new RecordTableType(
                table: 'orders',
                pmsName: 'order',
                hasTenantId: false,
                ownerColumn: 'user_id',
                columns: [
                    'id' => ['type' => 'integer'],
                    'user_id' => ['type' => 'bigInteger'],
                    'created_by_id' => ['type' => 'bigInteger'],
                ],
            ),
            'notes' => new RecordTableType(
                table: 'notes',
                pmsName: 'note',
                hasTenantId: false,
                columns: ['id' => ['type' => 'integer']],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
        Auth::setUser(new GenericUser(['id' => 42]));
    }

    /** @test */
    public function no_view_own_permission_means_no_restriction(): void
    {
        $this->assertNull(OwnRecordsScope::ownerColumn('widgets'));
        $this->assertSame('', OwnRecordsScope::cacheToken('widgets'));
    }

    /** @test */
    public function view_own_restricts_on_the_resolved_owner_column(): void
    {
        Gate::define('viewOwn:widget', fn(): bool => true);
        Gate::define('viewOwn:order', fn(): bool => true);

        $this->assertSame('created_by_id', OwnRecordsScope::ownerColumn('widgets'));
        $this->assertSame('user_id', OwnRecordsScope::ownerColumn('orders'));
        $this->assertSame('own:user_id:42', OwnRecordsScope::cacheToken('orders'));
    }

    /** @test */
    public function a_table_without_a_declared_owner_column_is_not_restricted(): void
    {
        Gate::define('viewOwn:note', fn(): bool => true);

        $this->assertNull(OwnRecordsScope::ownerColumn('notes'));
        $this->assertSame('', OwnRecordsScope::cacheToken('notes'));
    }

    /** @test */
    public function apply_qualifies_the_owner_column_with_the_given_table(): void
    {
        Gate::define('viewOwn:widget', fn(): bool => true);

        $query = DB::table('physical_widgets');
        OwnRecordsScope::apply($query, 'widgets', 'physical_widgets');

        $this->assertStringContainsString('"physical_widgets"."created_by_id"', $query->toSql());
        $this->assertContains(42, $query->getBindings());
    }

    /** @test */
    public function apply_is_a_no_op_without_a_restriction(): void
    {
        $query = DB::table('widgets');
        OwnRecordsScope::apply($query, 'widgets');

        $this->assertStringNotContainsString('where', strtolower($query->toSql()));
    }

    /** @test */
    public function a_guest_is_never_restricted(): void
    {
        Auth::logout();
        Gate::define('viewOwn:widget', fn(): bool => true);

        $this->assertNull(OwnRecordsScope::ownerColumn('widgets'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter OwnRecordsScopeTest`
Expected: FAIL — `Class "Sopheak\Core\Utilities\OwnRecordsScope" not found`

- [ ] **Step 3: Create the class**

`src/Utilities/OwnRecordsScope.php`. The permission loop is moved **verbatim** from `QueryBuilderFiltersUtils::apply()`, and the column resolution verbatim from `QueryBuilderFiltersUtils::resolveOwnRecordsOwnerColumn()`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Utilities;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordTableType;

/**
 * The single place that decides whether the current user is restricted to
 * their own rows on a table, and on which column.
 *
 * `viewOwn:{pmsName}` used to be enforced only inside
 * QueryBuilderFiltersUtils::apply(), which only list reads reach. By-id
 * reads, update, delete, restore and force-delete build their own queries, so
 * a viewOwn user saw only their own rows in a list yet could read, edit or
 * delete anyone's row by id. Every one of those paths now calls apply() here,
 * directly after applyTenantFilter().
 */
final class OwnRecordsScope
{
    /**
     * The owner column the current user is restricted to on $table, or null
     * when no own-records restriction applies — no authenticated user, no
     * viewOwn permission for any pmsName alias, or no owner column declared.
     */
    public static function ownerColumn(string $table): ?string
    {
        $recordConfig = RecordConfigService::table($table);
        $pmsName = $recordConfig->pmsName ?? null;

        if (!$pmsName || !Auth::check() || '' === RecordConfigService::ownRecordsPermissionPrefix()) {
            return null;
        }

        $pmsNames = is_array($pmsName) ? $pmsName : [$pmsName];
        $prefix = RecordConfigService::ownRecordsPermissionPrefix();
        $separator = RecordConfigService::permissionSeparator();

        $shouldRestrictToOwn = false;
        foreach ($pmsNames as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);
            if ('' === $candidate) {
                continue;
            }

            if (Gate::check($prefix . $separator . $candidate)) {
                $shouldRestrictToOwn = true;
                break;
            }
        }

        if (!$shouldRestrictToOwn) {
            return null;
        }

        return self::resolveOwnerColumn($recordConfig, QueryBuilderFiltersUtils::getAllowedColumns($table));
    }

    /**
     * Restrict $query to the current user's own rows when a restriction
     * applies. $qualifiedTable is the name the column is prefixed with in
     * SQL — the physical table — and defaults to $table.
     */
    public static function apply(Builder $query, string $table, ?string $qualifiedTable = null): void
    {
        $ownerColumn = self::ownerColumn($table);
        if (null === $ownerColumn) {
            return;
        }

        $query->where(($qualifiedTable ?? $table) . '.' . $ownerColumn, Auth::user()->id);
    }

    /**
     * A cache-key component that is empty when no restriction applies, so
     * unrestricted callers keep exactly the keys they have today, and
     * otherwise names the column and the user the rows are restricted to.
     */
    public static function cacheToken(string $table): string
    {
        $ownerColumn = self::ownerColumn($table);

        return null === $ownerColumn ? '' : 'own:' . $ownerColumn . ':' . Auth::user()->id;
    }

    /**
     * Resolution order: the table's explicit `ownerColumn`, then each entry of
     * `record.own_records_owner_columns`. The first candidate the table
     * actually declares wins; none declared means no restriction.
     *
     * @param array<int, string> $allowedCols
     */
    private static function resolveOwnerColumn(mixed $recordConfig, array $allowedCols): ?string
    {
        $candidates = [];

        if ($recordConfig instanceof RecordTableType) {
            $candidates[] = $recordConfig->ownerColumn;
        } elseif (is_array($recordConfig)) {
            $candidates[] = $recordConfig['ownerColumn'] ?? ($recordConfig['owner_column'] ?? null);
        }

        foreach (RecordConfigService::ownRecordsOwnerColumns() as $fallback) {
            $candidates[] = $fallback;
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);
            if ('' !== $candidate && in_array($candidate, $allowedCols, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Replace the inline block in `QueryBuilderFiltersUtils::apply()`**

Replace everything from the comment `// Handle check permission query only own user created record` through the closing brace of the `if ($pmsName && Auth::check() && ...)` block with:

```php
        // Restrict to the caller's own rows when they hold viewOwn:{pmsName}.
        OwnRecordsScope::apply($builder, $table);
```

Delete the now-unused private `resolveOwnRecordsOwnerColumn()` method and its docblock. Remove the `Gate` import if nothing else in the file uses it (`grep -n "Gate::" src/Utilities/QueryBuilderFiltersUtils.php` → none). Keep `Auth` only if still used.

- [ ] **Step 5: Run the new test and the existing list-scoping suite**

Run: `vendor/bin/phpunit --filter "OwnRecordsScopeTest|OwnRecordsScopingTest"`
Expected: PASS — both. `OwnRecordsScopingTest` passing **unmodified** is the proof the refactor changed no behaviour.

- [ ] **Step 6: Full gate**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse src tests`
Expected: all pass, `[OK] No errors`

- [ ] **Step 7: Commit**

```bash
git add src/Utilities/OwnRecordsScope.php src/Utilities/QueryBuilderFiltersUtils.php tests/Unit/OwnRecordsScopeTest.php
git commit -m "refactor: extract own-records scoping into OwnRecordsScope"
```

---

### Task 2: Scope by-id reads and every write builder

**Files:**
- Modify: `src/Services/RecordService.php` — `updateRecord()`, `fetchRawRecord()`, `deleteRecord()`, `restoreRecord()`, `forceDeleteRecord()`, `getRecord()`
- Test: `tests/Feature/OwnRecordsWriteScopingTest.php`

**Interfaces:**
- Consumes: `OwnRecordsScope::apply(Builder, string $table, ?string $qualifiedTable)` from Task 1.
- Produces: the HTTP fixture and helpers `seedRows()`, `actAs(int $id)` in `OwnRecordsWriteScopingTest`, reused by Tasks 3 and 4.

- [ ] **Step 1: Write the failing test**

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
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * viewOwn:{pmsName} was enforced for list reads only. A user who saw only
 * their own rows in a list could still read, update, delete, restore or
 * force-delete anyone's row by id.
 *
 * @internal
 */
class OwnRecordsWriteScopingTest extends TestCase
{
    use RefreshDatabase;

    protected const OWNER = 42;

    protected const OTHER = 99;

    protected const ADMIN = 1;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // MCP routes are registered at boot, so they must be enabled here for
        // Task 4's MCP tests; enabling them changes nothing for the HTTP tests.
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('record.mcp.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        DB::table('users')->insert([
            ['id' => self::ADMIN, 'name' => 'admin'],
            ['id' => self::OWNER, 'name' => 'owner'],
            ['id' => 43, 'name' => 'another viewOwn user'],
        ]);

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('orders', function (Blueprint $t): void {
            $t->id();
            $t->string('ref')->unique();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });

        Config::set('record.tables', [
            'widgets' => new RecordTableType(
                table: 'widgets',
                pmsName: 'widget',
                hasTenantId: false,
                softDeletes: true,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'deleted_at' => ['type' => 'datetime', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
            // Owner is the record's subject, not its audit author.
            'orders' => new RecordTableType(
                table: 'orders',
                pmsName: 'order',
                hasTenantId: false,
                canUpsert: true,
                ownerColumn: 'user_id',
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'ref' => ['type' => 'string', 'nullable' => false],
                    'user_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();

        // Everyone may do everything; only OWNER and user 43 are own-restricted.
        Gate::before(function ($user, string $ability): bool {
            if (str_starts_with($ability, 'viewOwn:')) {
                return in_array((int) $user->id, [self::OWNER, 43], true);
            }

            return true;
        });

        $this->seedRows();
    }

    protected function seedRows(): void
    {
        DB::table('widgets')->delete();
        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'MINE', 'created_by_id' => self::OWNER, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'THEIRS', 'created_by_id' => self::OTHER, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('orders')->delete();
        DB::table('orders')->insert([
            // Admin created it on the owner's behalf: owner is user_id, not created_by_id.
            ['id' => 1, 'ref' => 'ORD-MINE', 'user_id' => self::OWNER, 'created_by_id' => self::ADMIN, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'ref' => 'ORD-THEIRS', 'user_id' => self::OTHER, 'created_by_id' => self::OWNER, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function actAs(int $id): static
    {
        $user = new class extends Authenticatable {
            protected $table = 'users';

            public $timestamps = false;
        };
        $user->forceFill(['id' => $id, 'name' => 'u' . $id]);
        $user->exists = true;

        return $this->actingAs($user, 'api');
    }

    /** @test */
    public function reading_another_users_row_by_id_looks_like_a_missing_row(): void
    {
        $foreign = $this->actAs(self::OWNER)->getJson('/api/widgets/2');
        $missing = $this->actAs(self::OWNER)->getJson('/api/widgets/999');

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertStringNotContainsString('THEIRS', (string) $foreign->getContent());
    }

    /** @test */
    public function reading_your_own_row_still_works(): void
    {
        $this->actAs(self::OWNER)->getJson('/api/widgets/1')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'MINE');
    }

    /** @test */
    public function updating_another_users_row_is_refused_like_a_missing_row(): void
    {
        $foreign = $this->actAs(self::OWNER)->putJson('/api/widgets/2', ['name' => 'HIJACKED']);
        $missing = $this->actAs(self::OWNER)->putJson('/api/widgets/999', ['name' => 'HIJACKED']);

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame('THEIRS', DB::table('widgets')->where('id', 2)->value('name'));
    }

    /** @test */
    public function updating_your_own_row_still_works(): void
    {
        $this->actAs(self::OWNER)->putJson('/api/widgets/1', ['name' => 'RENAMED'])->assertStatus(200);

        $this->assertSame('RENAMED', DB::table('widgets')->where('id', 1)->value('name'));
    }

    /** @test */
    public function deleting_another_users_row_is_refused(): void
    {
        $foreign = $this->actAs(self::OWNER)->deleteJson('/api/widgets/2');
        $missing = $this->actAs(self::OWNER)->deleteJson('/api/widgets/999');

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertNull(DB::table('widgets')->where('id', 2)->value('deleted_at'));
    }

    /** @test */
    public function restoring_another_users_row_is_refused(): void
    {
        DB::table('widgets')->where('id', 2)->update(['deleted_at' => now()]);

        $this->actAs(self::OWNER)->postJson('/api/widgets/2/restore');

        $this->assertNotNull(DB::table('widgets')->where('id', 2)->value('deleted_at'));
    }

    /** @test */
    public function force_deleting_another_users_row_is_refused(): void
    {
        $this->actAs(self::OWNER)->deleteJson('/api/widgets/2/force');

        $this->assertSame(1, DB::table('widgets')->where('id', 2)->count());
    }

    /** @test */
    public function a_user_without_view_own_keeps_full_access(): void
    {
        $this->actAs(self::ADMIN)->getJson('/api/widgets/2')->assertStatus(200)->assertJsonPath('data.name', 'THEIRS');
        $this->actAs(self::ADMIN)->putJson('/api/widgets/2', ['name' => 'ADMIN-EDIT'])->assertStatus(200);

        $this->assertSame('ADMIN-EDIT', DB::table('widgets')->where('id', 2)->value('name'));
    }

    /** @test */
    public function writes_scope_on_the_owner_column_not_the_audit_author(): void
    {
        // Order 2: user_id = OTHER, but created_by_id = OWNER. Ownership is
        // user_id, so the OWNER must NOT be able to edit it.
        $this->actAs(self::OWNER)->putJson('/api/orders/2', ['ref' => 'ORD-HIJACKED']);
        $this->assertSame('ORD-THEIRS', DB::table('orders')->where('id', 2)->value('ref'));

        // Order 1: user_id = OWNER, created_by_id = ADMIN. The OWNER owns it.
        $this->actAs(self::OWNER)->putJson('/api/orders/1', ['ref' => 'ORD-MINE-EDITED'])->assertStatus(200);
        $this->assertSame('ORD-MINE-EDITED', DB::table('orders')->where('id', 1)->value('ref'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter OwnRecordsWriteScopingTest`
Expected: FAIL on the refusal tests (`reading_another…`, `updating_another…`, `deleting_another…`, `restoring_another…`, `force_deleting_another…`, `writes_scope_on_the_owner_column…`). The positive controls and `a_user_without_view_own_keeps_full_access` pass already.

If a route in this test differs from the package's (restore / force-delete URIs), confirm with `grep -n "restore\|force" routes/api.php` and correct the test — record the ruling.

- [ ] **Step 3: Scope the five write builders**

In `src/Services/RecordService.php`, each of `updateRecord()`, `fetchRawRecord()`, `deleteRecord()`, `restoreRecord()` and `forceDeleteRecord()` contains:

```php
        $query = DB::table($actualTableName)->where($pk, $id);
        $this->applyTenantFilter($query, $table, $tenantId);
```

Add directly after `applyTenantFilter` in all five:

```php
        OwnRecordsScope::apply($query, $table, $actualTableName);
```

`fetchRawRecord()` is included deliberately: the controller loads it before delete, restore and force-delete to feed triggers and audit, so unscoped it would hand another user's row to a `beforeDelete` trigger.

- [ ] **Step 4: Scope `getRecord()`**

In `getRecord()`, directly after:

```php
        $builder = $this->createReadBuilder($actualTableName);
        $this->applyTenantFilter($builder, $actualTableName, $tenantId);
```

add:

```php
        OwnRecordsScope::apply($builder, $table, $actualTableName);
```

- [ ] **Step 5: Import**

Add `use Sopheak\Core\Utilities\OwnRecordsScope;` to `src/Services/RecordService.php`.

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit --filter "OwnRecordsWriteScopingTest|OwnRecordsScopingTest"`
Expected: PASS

- [ ] **Step 7: Full gate**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse src tests`
Expected: all pass, `[OK] No errors`

- [ ] **Step 8: Commit**

```bash
git add src/Services/RecordService.php tests/Feature/OwnRecordsWriteScopingTest.php
git commit -m "fix: enforce viewOwn on by-id reads, update, delete, restore and force-delete"
```

---

### Task 3: Isolate cache keys by owner scope

**Files:**
- Modify: `src/Services/RecordCacheService.php` — `queryFingerprint()` (line ~130)
- Modify: `src/Services/RecordService.php` — the two `queryFingerprint($request)` calls in `listRecords()` and the one in `getRecord()`
- Test: `tests/Feature/OwnRecordsCacheIsolationTest.php`

**Interfaces:**
- Consumes: `OwnRecordsScope::cacheToken(string $table): string` from Task 1; the `OwnRecordsWriteScopingTest` fixture from Task 2.
- Produces: `RecordCacheService::queryFingerprint(Request $request, ?string $table = null): string`.

- [ ] **Step 1: Write the failing test**

Extend Task 2's fixture so the table is cached:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * RecordCacheService::queryFingerprint() is md5(query + body) with no user in
 * it, so a scoped result and an unscoped one shared a cache key. Whoever
 * warmed the cache first decided what every later caller saw: after an admin
 * listed widgets, a viewOwn user was served the admin's full list.
 *
 * @internal
 */
class OwnRecordsCacheIsolationTest extends OwnRecordsWriteScopingTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('record.cache.enabled', true);

        $tables = Config::get('record.tables');
        $widgets = $tables['widgets'];
        $tables['widgets'] = new RecordTableType(
            table: 'widgets',
            pmsName: 'widget',
            hasTenantId: false,
            softDeletes: true,
            disableCache: false,
            columns: $widgets->columns,
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
        Cache::flush();
    }

    /**
     * @return array<int, string>
     */
    private function listedNames(int $userId): array
    {
        return array_column((array) $this->actAs($userId)->getJson('/api/widgets')->json('data'), 'name');
    }

    /** @test */
    public function an_admin_warming_the_list_cache_does_not_leak_to_a_view_own_user(): void
    {
        $this->assertSame(['MINE', 'THEIRS'], $this->listedNames(self::ADMIN));
        $this->assertSame(['MINE'], $this->listedNames(self::OWNER));
    }

    /** @test */
    public function a_view_own_user_warming_the_cache_does_not_starve_an_admin(): void
    {
        $this->assertSame(['MINE'], $this->listedNames(self::OWNER));
        $this->assertSame(['MINE', 'THEIRS'], $this->listedNames(self::ADMIN));
    }

    /** @test */
    public function two_view_own_users_do_not_share_a_cached_scoped_result(): void
    {
        $this->assertSame(['MINE'], $this->listedNames(self::OWNER));
        // User 43 owns nothing; must not receive user 42's cached list.
        $this->assertSame([], $this->listedNames(43));
    }

    /** @test */
    public function an_admin_warming_the_record_cache_does_not_leak_to_a_view_own_user(): void
    {
        $this->actAs(self::ADMIN)->getJson('/api/widgets/2')->assertJsonPath('data.name', 'THEIRS');

        $foreign = $this->actAs(self::OWNER)->getJson('/api/widgets/2');
        $this->assertStringNotContainsString('THEIRS', (string) $foreign->getContent());
    }
}
```

Note: this class extends `OwnRecordsWriteScopingTest`, so it **re-runs all of Task 2's tests against a cached table** too — that is intentional.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter OwnRecordsCacheIsolationTest`
Expected: FAIL — `an_admin_warming_the_list_cache…`, `two_view_own_users…`, and `an_admin_warming_the_record_cache…`.

- [ ] **Step 3: Fold the owner scope into the fingerprint**

Replace `queryFingerprint()` in `src/Services/RecordCacheService.php`:

```php
    public function queryFingerprint(Request $request, ?string $table = null): string
    {
        $query = $request->json()->all() + $request->query();
        $this->recursiveKsort($query);

        // Own-records scoping makes an identical query return different rows
        // for different users. Without the scope in the key, whoever warmed the
        // cache first decided what every later caller saw. The token is empty
        // for unrestricted callers, so their keys are byte-identical to before.
        if (null !== $table) {
            $ownScope = OwnRecordsScope::cacheToken($table);
            if ('' !== $ownScope) {
                $query['__own_scope'] = $ownScope;
            }
        }

        return md5(serialize($query));
    }
```

Add `use Sopheak\Core\Utilities\OwnRecordsScope;`.

- [ ] **Step 4: Pass the table at the three read call sites**

In `src/Services/RecordService.php`:

- `listRecords()`, both occurrences: `queryFingerprint($request)` → `queryFingerprint($request, $actualTableName)`. Use `$actualTableName` because that is what `listRecords()` passes to `QueryBuilderFiltersUtils::apply()`, which is where the list's own scope is decided; the token must mirror that decision exactly.
- `getRecord()`: `queryFingerprint($request)` → `queryFingerprint($request, $table)`. Use `$table` because that is what Task 2 passes to `OwnRecordsScope::apply()` in `getRecord()`.

Leave the table-function and global-function fingerprints alone; RPC functions do not apply `viewOwn`.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter "OwnRecordsCacheIsolationTest|OwnRecordsWriteScopingTest|OwnRecordsScopingTest"`
Expected: PASS

- [ ] **Step 6: Full gate**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse src tests`
Expected: all pass, `[OK] No errors`

- [ ] **Step 7: Commit**

```bash
git add src/Services/RecordCacheService.php src/Services/RecordService.php tests/Feature/OwnRecordsCacheIsolationTest.php
git commit -m "fix: isolate read cache keys by viewOwn owner scope"
```

---

### Task 4: Guard upsert; pin bulk and MCP

**Files:**
- Modify: `src/Utilities/OwnRecordsScope.php` (add `assertNoForeignMatches()`)
- Modify: `src/Services/RecordService.php` — before `DB::table($actualTableName)->upsert([$item], …)` in `upsertRecord()` (line ~335) and before `DB::table($actualTableName)->upsert($preparedItems, …)` in `bulkUpsertRecord()` (line ~431)
- Test: `tests/Feature/OwnRecordsWriteScopingTest.php`

**Interfaces:**
- Consumes: `OwnRecordsScope::ownerColumn()` from Task 1; the fixture from Task 2.
- Produces: `OwnRecordsScope::assertNoForeignMatches(string $table, string $qualifiedTable, array $items, array $matchOn): void` — throws `HttpResponseException` (403, `RecordApiJsonResponseEnum::FORBIDDEN`) when any item's `match_on` tuple matches an existing row not owned by the current user.

Upsert's `ON CONFLICT … DO UPDATE` cannot carry a `WHERE`, so the owner filter cannot ride on the statement. A pre-check refuses the call instead. A refusal here does reveal that a row with that key exists — but the same unique index already reveals it on a plain create, so no new oracle is introduced.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/OwnRecordsWriteScopingTest.php`:

```php
    /** @test */
    public function upsert_cannot_overwrite_another_users_row_through_a_match_key(): void
    {
        $this->actAs(self::OWNER)->postJson('/api/orders/upsert?match_on=ref', [
            'ref' => 'ORD-THEIRS',
            'user_id' => self::OWNER,
        ]);

        $this->assertSame(self::OTHER, (int) DB::table('orders')->where('ref', 'ORD-THEIRS')->value('user_id'));
    }

    /** @test */
    public function upsert_of_your_own_row_still_works(): void
    {
        $this->actAs(self::OWNER)->postJson('/api/orders/upsert?match_on=ref', [
            'ref' => 'ORD-MINE',
            'user_id' => self::OWNER,
        ])->assertStatus(200);

        $this->assertSame(1, DB::table('orders')->where('ref', 'ORD-MINE')->count());
    }

    /** @test */
    public function upsert_inserting_a_new_row_still_works(): void
    {
        $this->actAs(self::OWNER)->postJson('/api/orders/upsert?match_on=ref', [
            'ref' => 'ORD-NEW',
            'user_id' => self::OWNER,
        ])->assertStatus(200);

        $this->assertSame(1, DB::table('orders')->where('ref', 'ORD-NEW')->count());
    }

    /** @test */
    public function bulk_upsert_touching_any_foreign_row_changes_nothing(): void
    {
        $this->actAs(self::OWNER)->postJson('/api/orders/bulk/upsert?match_on=ref', [
            ['ref' => 'ORD-MINE', 'user_id' => self::OWNER],
            ['ref' => 'ORD-THEIRS', 'user_id' => self::OWNER],
        ]);

        $this->assertSame(self::OTHER, (int) DB::table('orders')->where('ref', 'ORD-THEIRS')->value('user_id'));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function mcp(int $userId, string $tool, array $arguments): string
    {
        $response = $this->actAs($userId)->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);

        return (string) ($response->json('result.content.0.text') ?? json_encode($response->json()));
    }

    /** @test */
    public function mcp_tools_cannot_read_update_or_delete_another_users_row(): void
    {
        $this->assertStringNotContainsString('THEIRS', $this->mcp(self::OWNER, 'read_widgets', ['id' => 2]));

        $this->mcp(self::OWNER, 'update_widgets', ['id' => 2, 'payload' => ['name' => 'HIJACKED']]);
        $this->mcp(self::OWNER, 'delete_widgets', ['id' => 2]);

        $this->assertSame('THEIRS', DB::table('widgets')->where('id', 2)->value('name'));
        $this->assertNull(DB::table('widgets')->where('id', 2)->value('deleted_at'));
    }

    /** @test */
    public function mcp_read_of_your_own_row_still_works(): void
    {
        $this->assertStringContainsString('MINE', $this->mcp(self::OWNER, 'read_widgets', ['id' => 1]));
    }

    /** @test */
    public function bulk_update_and_delete_cannot_reach_another_users_row(): void
    {
        $this->actAs(self::OWNER)->postJson('/api/widgets/bulk/update', [['id' => 2, 'name' => 'HIJACKED']]);
        $this->actAs(self::OWNER)->postJson('/api/widgets/bulk/delete', [['id' => 2]]);

        $this->assertSame('THEIRS', DB::table('widgets')->where('id', 2)->value('name'));
        $this->assertNull(DB::table('widgets')->where('id', 2)->value('deleted_at'));
    }
```

- [ ] **Step 2: Run them**

Run: `vendor/bin/phpunit --filter OwnRecordsWriteScopingTest`
Expected: FAIL on `upsert_cannot_overwrite…` and `bulk_upsert_touching…`. Already PASSING, and pinned rather than fixed here: `bulk_update_and_delete…` (bulk update/delete call `updateRecord()`/`deleteRecord()` per item, scoped in Task 2) and `mcp_tools_cannot_read_update_or_delete…` (MCP `update_*`/`delete_*` reach the same service methods via `executeUpdate()`/`executeDelete()`, and `read_*` goes through `executeGetById()` → `QueryBuilderFiltersUtils::apply()`, scoped in Task 1). The positive controls pass.

- [ ] **Step 3: Add the guard**

Append to `OwnRecordsScope`, with `use Illuminate\Http\Exceptions\HttpResponseException;`, `use Illuminate\Support\Facades\DB;`, `use Sopheak\Core\Enums\RecordApiJsonResponseEnum;`, `use Sopheak\Core\Services\RecordApiResponseService;`:

```php
    /**
     * Refuse an upsert whose match_on key collides with a row the current user
     * does not own.
     *
     * An upsert's ON CONFLICT … DO UPDATE cannot carry a WHERE, so the owner
     * filter that guards every other write cannot ride on the statement; a
     * viewOwn user could otherwise overwrite another user's row by naming its
     * unique key. A row with a NULL owner is not the caller's either.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $matchOn
     */
    public static function assertNoForeignMatches(string $table, string $qualifiedTable, array $items, array $matchOn): void
    {
        $ownerColumn = self::ownerColumn($table);
        if (null === $ownerColumn || [] === $matchOn || [] === $items) {
            return;
        }

        $userId = Auth::user()->id;
        $owner = $qualifiedTable . '.' . $ownerColumn;

        $foreign = DB::table($qualifiedTable)
            ->where(function (Builder $any) use ($items, $matchOn, $qualifiedTable): void {
                foreach ($items as $item) {
                    $any->orWhere(function (Builder $tuple) use ($item, $matchOn, $qualifiedTable): void {
                        foreach ($matchOn as $key) {
                            $tuple->where($qualifiedTable . '.' . $key, $item[$key] ?? null);
                        }
                    });
                }
            })
            ->where(function (Builder $notMine) use ($owner, $userId): void {
                $notMine->where($owner, '!=', $userId)->orWhereNull($owner);
            })
            ->exists();

        if ($foreign) {
            throw new HttpResponseException(
                RecordApiResponseService::errorWrapped('Forbidden', RecordApiJsonResponseEnum::FORBIDDEN->value)
            );
        }
    }
```

- [ ] **Step 4: Call it before both upserts**

In `upsertRecord()`, directly before `DB::table($actualTableName)->upsert([$item], $matchOn, $updateColumns);`:

```php
        OwnRecordsScope::assertNoForeignMatches($table, $actualTableName, [$item], $matchOn);
```

In `bulkUpsertRecord()`, directly before `$affected = DB::table($actualTableName)->upsert($preparedItems, $matchOn, $updateColumns);`:

```php
        OwnRecordsScope::assertNoForeignMatches($table, $actualTableName, $preparedItems, $matchOn);
```

Checking the whole batch before writing any of it means a batch with one foreign row writes nothing, rather than a partial batch.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter "OwnRecordsWriteScopingTest|OwnRecordsCacheIsolationTest|OwnRecordsScopeTest|OwnRecordsScopingTest"`
Expected: PASS

- [ ] **Step 6: Full gate**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse src tests && vendor/bin/rector process --dry-run --no-progress-bar`
Expected: all pass, `[OK] No errors`, no new rector findings for touched files.

- [ ] **Step 7: Commit**

```bash
git add src/Utilities/OwnRecordsScope.php src/Services/RecordService.php tests/Feature/OwnRecordsWriteScopingTest.php
git commit -m "fix: refuse viewOwn upserts that collide with another user's row"
```

---

### Task 5: Deprecate `restrict_to_own_records`; document; release notes

**Files:**
- Modify: `config/sp-record.php` (line 353), `src/Console/SetupPackageCommand.php` (line ~774), `src/Services/RecordConfigService.php` (`restrictToOwnRecords()`, line ~238)
- Modify: `docs/guide/features/feature-permission-own-records.md`
- Modify: `CHANGELOG.md`, `docs/changelog.md`
- Modify: `docs/bug-reports/2026-09-30-view-own-write-scoping.md`

**Interfaces:**
- Consumes: the behaviour built in Tasks 1–4.
- Produces: nothing.

`restrict_to_own_records` has never been read. Wiring it now would make it a global switch restricting every authenticated user — admins included — which is a separate feature with its own design questions. Deprecating keeps behaviour identical and stops the config from promising something the code does not do.

- [ ] **Step 1: Correct the two config comments**

In both `config/sp-record.php` and `src/Console/SetupPackageCommand.php`, replace:

```php
'restrict_to_own_records' => false, // limit queries to records created by the authenticated user
```

with (match each file's indentation):

```php
'restrict_to_own_records' => false, // DEPRECATED — has no effect. Use the viewOwn:{pmsName} permission (see own_records_permission_prefix).
```

- [ ] **Step 2: Mark the accessor deprecated**

Above `public static function restrictToOwnRecords(): bool` in `src/Services/RecordConfigService.php`:

```php
    /**
     * @deprecated Never wired into the runtime and has no effect. Own-records
     *             restriction is driven by the viewOwn:{pmsName} permission;
     *             see OwnRecordsScope.
     */
```

- [ ] **Step 3: Update the own-records guide**

In `docs/guide/features/feature-permission-own-records.md`:

- Replace the caveat "The check applies to list queries only; single-record reads and writes are not affected." with a statement that it applies to list, by-id read, update, delete, restore, force-delete, bulk update/delete and upsert, on HTTP and MCP; that a refused by-id read or write looks exactly like a missing id; and that a refused upsert returns 403.
- Replace the `restrict_to_own_records` caveat with: deprecated, never wired, has no effect.
- Add a short "Caching" note: cached results are keyed per owner scope, so a `viewOwn` user never receives another caller's cached rows, and unrestricted callers keep their existing cache keys.

- [ ] **Step 4: Changelog**

Under `## [Unreleased]` → `### Fixed` in **both** `CHANGELOG.md` and `docs/changelog.md` (keep `docs/changelog.md`'s blank line after `###` headings):

```markdown
- **`viewOwn` was enforced on list reads only**: a user holding `viewOwn:{pmsName}` saw only their own rows in a list but could read, update, delete, restore or force-delete any row by id, and upsert over another user's row through its `match_on` key. Own-records scoping now lives in `OwnRecordsScope` and is applied — directly after the tenant filter — to by-id reads and every write builder, so bulk update/delete and the MCP tools inherit it; upsert, whose `ON CONFLICT` cannot carry a `WHERE`, refuses a colliding foreign row with 403. A refused by-id read or write is indistinguishable from a missing id. Users without `viewOwn` are unaffected. See `tests/Feature/OwnRecordsWriteScopingTest.php`.
- **Cached reads leaked across owner scopes**: `RecordCacheService::queryFingerprint()` hashed only the query and body, so a scoped and an unscoped result shared a cache key and whoever warmed the cache first decided what every later caller saw — after an admin listed a cached table, a `viewOwn` user was served the admin's full list. The fingerprint now carries an owner-scope token for restricted callers; unrestricted callers keep byte-identical keys, so no cache is invalidated. See `tests/Feature/OwnRecordsCacheIsolationTest.php`.
```

Under `### Deprecated` (create the heading in each file if absent):

```markdown
- **`record.restrict_to_own_records`** has never been read by the runtime and has no effect; its comment claimed it limited queries to the authenticated user's records. It is kept for compatibility and marked deprecated. Use the `viewOwn:{pmsName}` permission.
```

- [ ] **Step 5: Close the spec**

In `docs/bug-reports/2026-09-30-view-own-write-scoping.md`, set status to fixed and add a resolution section mapping each reproduction row to its new behaviour.

- [ ] **Step 6: Final gate**

Run: `graphify update . && vendor/bin/phpunit && vendor/bin/phpstan analyse src tests && php bin/validate-docs.php`
Expected: all pass, `[OK] No errors`, no *new* docs-validation failure.

- [ ] **Step 7: Commit**

```bash
git add config/sp-record.php src/Console/SetupPackageCommand.php src/Services/RecordConfigService.php docs/guide/features/feature-permission-own-records.md CHANGELOG.md docs/changelog.md docs/bug-reports/2026-09-30-view-own-write-scoping.md
git commit -m "docs: document viewOwn write scoping; deprecate restrict_to_own_records"
```

---

## Out of Scope

- **Owner transfer on update.** An own-scoped update is refused on foreign rows, but a `viewOwn` user may still set the owner column on their *own* row to another user's id, handing it away, when the owner column is client-writable (e.g. `user_id`). `created_by_id` is auto-filled and typically write-disabled, so it is not affected. Guarding this is a create/update payload policy, not a scoping fix.
- **Create-time ownership.** A `viewOwn` user creating a row with `user_id` set to someone else plants a row in that user's scope. Same policy question as above.
- **Config key ≠ physical table name.** `listRecords()` passes the physical name to `QueryBuilderFiltersUtils::apply()`, which then looks up config by it; where a config key differs from its `table:` value, list scoping silently does not apply. Writes use the config key and are unaffected. Preserved here so Task 1 stays a pure refactor.
- **Guard used for the check.** The scope uses the default guard (`Auth::check()`, `Gate::check()`), as today. After auth middleware this is the authenticated guard; outside HTTP it may not be.
- **`restrict_to_own_records` as a global switch.** Deprecated rather than wired; see Task 5.
